{{-- Plans embed widget (PLANS-WIDGET-DESIGN.md §3a). Output is --}}
{{-- application/javascript, so this file must be valid JS. Blade --}}
{{-- interpolates the catalog + resolved theme tokens at the top, then a --}}
{{-- self-contained IIFE renders plan cards into a SHADOW ROOT (forms' --}}
{{-- isolation idiom: :host --pw-* variables, custom_css injected AFTER --}}
{{-- the variable styles) and runs the purchase flow in a body-appended --}}
{{-- MODAL. The modal is deliberately light-DOM with inline styles: --}}
{{-- Turnstile and Stripe's embedded-checkout iframes mount unreliably --}}
{{-- inside shadow roots, and inline styles give the overlay its own --}}
{{-- isolation on arbitrary host pages. --}}
{{-- --}}
{{-- The JSON dump uses JSON_HEX_* so it round-trips safely into the --}}
{{-- JS context (no </script> escape). --}}
@php
    /** Rendered for BOTH embed flavours (PlanEmbedController):
     *    product  — /plans/{slug}/embed.js  → $plan_rows = all public plans
     *    single   — /plan/{id}/embed.js     → $plan_rows = one plan
     *  $tokens = PlanThemeTokens::resolve() output for the product's theme. */
    $plans = $plan_rows->map(fn ($plan) => [
        'id' => $plan->id,
        'name' => $plan->name,
        'description' => $plan->description,
        'features' => array_values(array_filter((array) ($plan->features ?? []), 'is_string')),
        // Per-plan theme OVERRIDE: resolved tokens when the plan carries
        // its own theme_id, null = inherit the root (:host) theme. The
        // IIFE applies these as per-card CSS variables (they cascade over
        // :host for that card) and the checkout modal adopts them.
        'theme' => $plan->theme_id !== null
            ? \App\Support\PlanThemeTokens::resolve($plan->theme)
            : null,
        'prices' => $plan->activePrices->map(fn ($price) => [
            'id' => $price->id,
            'label' => $price->label,
            'amount' => '£'.number_format((float) $price->price, 2),
            'interval' => $price->interval_label,
            // Setup fee (recurring "fee now, then price per interval"):
            // the formatted immediate fee, or null for a plain price.
            'setup_fee' => ($price->setup_fee !== null && (float) $price->setup_fee > 0)
                ? '£'.number_format((float) $price->setup_fee, 2)
                : null,
            'is_default' => (bool) $price->is_default,
        ])->values()->all(),
    ])->filter(fn (array $plan) => $plan['prices'] !== [])->values()->all();

    $config = [
        'slug' => $product->slug,
        'product_name' => $product->name,
        'root_id' => $root_id,
        'plans' => $plans,
        'checkout_url' => $checkout_url,
        'stripe_key' => $stripe_key,
        'turnstile_site_key' => $turnstile_site_key,
        // Resolved design tokens (defaults merged with the product's plan
        // theme). custom_css rides along — it is public CSS by definition
        // once rendered into the embed.
        'theme' => $tokens,
    ];
    $json = json_encode($config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES);
@endphp
(function () {
    "use strict";

    var CONFIG = {!! $json !!};
    var ROOT_ID = CONFIG.root_id;
    var T = CONFIG.theme || {};
    var shadow = null;           // the grid's shadow root
    var overlayEl = null;        // body-appended modal overlay (light DOM)
    var checkoutInstance = null; // Stripe embedded checkout — ONE per page
    var turnstileWidgetId = null;
    var selectedPrice = null;
    var prevOverflow = null;

    function el(tag, attrs, children) {
        var node = document.createElement(tag);
        if (attrs) {
            Object.keys(attrs).forEach(function (k) {
                if (k === "class") node.className = attrs[k];
                else if (k === "style") node.style.cssText = attrs[k];
                else node.setAttribute(k, attrs[k]);
            });
        }
        (children || []).forEach(function (c) {
            if (typeof c === "string") node.appendChild(document.createTextNode(c));
            else if (c) node.appendChild(c);
        });
        return node;
    }

    var loaded = {};
    function loadScript(src) {
        if (loaded[src]) return loaded[src];
        loaded[src] = new Promise(function (resolve, reject) {
            var s = document.createElement("script");
            s.src = src; s.async = true;
            s.onload = resolve; s.onerror = reject;
            document.head.appendChild(s);
        });
        return loaded[src];
    }

    // ── Shadow-root styles: :host carries the --pw-* variables (forms'
    //    idiom); rules consume them; custom_css is appended AFTER the
    //    variable styles so a theme can override anything.
    function buildStyleEl() {
        var vars = ":host{"
            + "--pw-font-family:" + T.font_family + ";"
            + "--pw-font-size:" + T.font_size + ";"
            + "--pw-text:" + T.text + ";"
            + "--pw-accent:" + T.accent + ";"
            + "--pw-bg:" + T.background + ";"
            + "--pw-surface:" + T.surface + ";"
            + "--pw-border:" + T.border + ";"
            + "--pw-border-width:" + T.border_width + ";"
            + "--pw-radius:" + T.radius + ";"
            + "--pw-button-bg:" + T.button_bg + ";"
            + "--pw-button-bg-hover:" + T.button_bg_hover + ";"
            + "--pw-button-text:" + T.button_text + ";"
            + "--pw-error:" + T.error + ";"
            + "--pw-card-bg:" + T.card_bg + ";"
            + "--pw-card-border:" + T.card_border + ";"
            + "--pw-card-radius:" + T.card_radius + ";"
            + "--pw-price:" + T.price_color + ";"
            + "--pw-check:" + T.feature_check + ";"
            + "--pw-muted:" + T.muted + ";"
            + "}";
        var rules = [
            ":host { font-family: var(--pw-font-family); color: var(--pw-text); background: var(--pw-bg); display: block; }",
            ".pw-heading { font-size: 18px; font-weight: 700; margin: 0 0 14px; }",
            ".pw-logo { max-height: 40px; margin: 0 0 12px; display: block; }",
            ".pw-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(230px, 1fr)); gap: 16px; }",
            ".pw-plan { border: var(--pw-border-width) solid var(--pw-card-border); border-radius: var(--pw-card-radius); background: var(--pw-card-bg); padding: 22px; display: flex; flex-direction: column; }",
            ".pw-plan h3 { margin: 0 0 6px; font-size: 17px; }",
            ".pw-desc { font-size: 13px; color: var(--pw-muted); margin: 0 0 14px; line-height: 1.5; }",
            ".pw-features { list-style: none; margin: 0 0 16px; padding: 0; font-size: 13px; color: var(--pw-text); }",
            ".pw-features li { padding: 3px 0 3px 20px; position: relative; }",
            ".pw-features li:before { content: '\\2713'; position: absolute; left: 0; color: var(--pw-check); }",
            ".pw-price-row { display: flex; align-items: baseline; justify-content: space-between; gap: 8px; padding: 8px 0; border-top: 1px solid var(--pw-card-border); margin-top: auto; }",
            ".pw-amount { font-size: 18px; font-weight: 700; color: var(--pw-price); }",
            ".pw-interval { font-size: 12px; color: #94a3b8; }",
            ".pw-btn { border: 0; border-radius: var(--pw-radius); background: var(--pw-button-bg); color: var(--pw-button-text); font-size: var(--pw-font-size); font-weight: 600; padding: 8px 14px; cursor: pointer; }",
            ".pw-btn:hover { background: var(--pw-button-bg-hover); }",
            ".pw-btn:disabled { opacity: .55; cursor: default; }",
        ].join("\n");
        var css = vars + "\n" + rules;
        if (T.custom_css) css += "\n" + T.custom_css;
        var style = document.createElement("style");
        style.textContent = css;
        return style;
    }

    function renderGrid() {
        shadow.innerHTML = "";
        shadow.appendChild(buildStyleEl());
        if (T.logo_url) shadow.appendChild(el("img", { class: "pw-logo", src: T.logo_url, alt: "" }));
        if (T.heading) shadow.appendChild(el("p", { class: "pw-heading" }, [T.heading]));
        var grid = el("div", { class: "pw-grid" });
        CONFIG.plans.forEach(function (plan) {
            var card = el("div", { class: "pw-plan" }, [
                el("h3", null, [plan.name]),
                plan.description ? el("p", { class: "pw-desc" }, [plan.description]) : null,
                plan.features.length
                    ? el("ul", { class: "pw-features" }, plan.features.map(function (f) { return el("li", null, [f]); }))
                    : null,
            ]);
            // Per-plan theme override: set the card-scoped CSS variables on
            // the card itself — they cascade over :host for this card only,
            // so one table renders differently-themed plans side by side.
            if (plan.theme) {
                var o = plan.theme;
                card.style.cssText = "--pw-card-bg:" + o.card_bg + ";--pw-card-border:" + o.card_border + ";--pw-card-radius:" + o.card_radius + ";--pw-price:" + o.price_color + ";--pw-check:" + o.feature_check + ";--pw-muted:" + o.muted + ";--pw-button-bg:" + o.button_bg + ";--pw-button-bg-hover:" + o.button_bg_hover + ";--pw-button-text:" + o.button_text + ";--pw-text:" + o.text + ";--pw-radius:" + o.radius + ";--pw-border-width:" + o.border_width + ";";
            }
            plan.prices.forEach(function (price) {
                var btn = el("button", { class: "pw-btn", type: "button" }, ["Choose"]);
                btn.addEventListener("click", function () { openModal(plan, price); });
                card.appendChild(el("div", { class: "pw-price-row" }, [
                    el("span", null, [
                        el("span", { class: "pw-amount" }, [price.amount]),
                        el("span", { class: "pw-interval" }, [" " + (price.label || price.interval)]),
                    ]),
                    btn,
                ]));
            });
            grid.appendChild(card);
        });
        shadow.appendChild(grid);
    }

    // ── Modal (light DOM + inline styles: Turnstile/Stripe iframes mount
    //    unreliably in shadow roots; inline styles isolate on any host).
    //    AT = the ACTIVE theme: the selected plan's override, else the
    //    root theme — so a themed plan's purchase panel matches its card.
    var AT = T;

    function fieldStyle() {
        return "width:100%;box-sizing:border-box;border:" + AT.border_width + " solid " + AT.border + ";border-radius:" + AT.radius + ";padding:9px 11px;font-size:" + AT.font_size + ";margin:0 0 14px;font-family:inherit;background:" + AT.surface + ";color:" + AT.text + ";";
    }

    function openModal(plan, price) {
        if (overlayEl) closeModal(); // never two overlays
        selectedPrice = price;
        AT = plan.theme || T;

        prevOverflow = document.documentElement.style.overflow;
        document.documentElement.style.overflow = "hidden";

        var panel = el("div", {
            role: "dialog", "aria-modal": "true", "aria-label": plan.name,
            style: "background:" + AT.card_bg + ";color:" + AT.text + ";font-family:" + AT.font_family + ";border-radius:" + AT.card_radius + ";max-width:520px;width:calc(100% - 32px);max-height:calc(100vh - 64px);overflow:auto;padding:24px;position:relative;",
        });

        var close = el("button", { type: "button", "aria-label": "Close", style: "position:absolute;top:12px;right:12px;border:0;background:none;font-size:20px;line-height:1;cursor:pointer;color:" + AT.muted + ";" }, ["×"]);
        close.addEventListener("click", closeModal);
        panel.appendChild(close);

        // All step content lives in this wrapper (the close button stays
        // outside it) so swapping steps can animate ONE element's height.
        var stepWrap = el("div", null, []);
        panel.appendChild(stepWrap);

        // A setup-fee price charges the FEE now and recurs at price.amount,
        // so the header can't just show one number. Title = plan name; a
        // distinct pricing line spells out both amounts. A plain price keeps
        // the original single-amount header.
        if (price.setup_fee) {
            stepWrap.appendChild(el("h3", { style: "margin:0 0 6px;font-size:17px;" }, [plan.name]));
            stepWrap.appendChild(el("p", { style: "margin:0 0 16px;font-size:14px;font-weight:600;color:" + AT.price + ";" },
                [price.setup_fee + " now, then " + price.amount + " " + price.interval]));
        } else {
            stepWrap.appendChild(el("h3", { style: "margin:0 0 16px;font-size:17px;" }, [plan.name + " — " + price.amount + " " + (price.label || price.interval)]));
        }

        var error = el("p", { style: "color:" + AT.error + ";font-size:13px;margin:0 0 10px;min-height:16px;" }, []);
        var turnstileHost = el("div", null, []);
        var nameInput = el("input", { type: "text", autocomplete: "name", style: fieldStyle() });
        var emailInput = el("input", { type: "email", autocomplete: "email", style: fieldStyle() });
        var companyInput = el("input", { type: "text", autocomplete: "organization", style: fieldStyle() });
        var phoneInput = el("input", { type: "tel", autocomplete: "tel", style: fieldStyle() });
        var hp = el("input", { type: "text", tabindex: "-1", autocomplete: "off", "aria-hidden": "true", style: "position:absolute;left:-9999px;height:1px;width:1px;opacity:0;" });
        // The button shows the IMMEDIATE charge — the fee when there is one,
        // else the price (unchanged).
        var payNow = price.setup_fee || price.amount;
        var submit = el("button", { type: "button", style: "border:0;border-radius:" + AT.radius + ";background:" + AT.button_bg + ";color:" + AT.button_text + ";font-size:" + AT.font_size + ";font-weight:600;padding:10px 16px;cursor:pointer;" }, ["Continue to payment — " + payNow]);

        var labelStyle = "display:block;font-size:13px;font-weight:600;margin:0 0 4px;";
        stepWrap.appendChild(el("label", { style: labelStyle }, ["Your name"]));
        stepWrap.appendChild(nameInput);
        stepWrap.appendChild(el("label", { style: labelStyle }, ["Email address"]));
        stepWrap.appendChild(emailInput);
        stepWrap.appendChild(el("label", { style: labelStyle }, ["Company / organisation (optional)"]));
        stepWrap.appendChild(companyInput);
        stepWrap.appendChild(el("label", { style: labelStyle }, ["Phone number (optional)"]));
        stepWrap.appendChild(phoneInput);
        stepWrap.appendChild(hp);
        stepWrap.appendChild(turnstileHost);
        stepWrap.appendChild(error);
        stepWrap.appendChild(submit);
        // Card-vaulting consent (design §3): stated inline, BEFORE the
        // visitor reaches Stripe's payment fields on the next step — no
        // separate checkbox, lighter friction. Muted so it reads as a
        // reassurance, not a warning.
        stepWrap.appendChild(el("p", { style: "font-size:12px;line-height:1.5;color:" + AT.muted + ";margin:10px 0 0;" },
            ["Your card is securely stored by our payment provider for future billing on this plan. You can remove it any time."]));

        overlayEl = el("div", { style: "position:fixed;inset:0;background:rgba(15,23,42,.55);z-index:2147483000;display:flex;align-items:center;justify-content:center;" });
        overlayEl.addEventListener("click", function (e) { if (e.target === overlayEl) closeModal(); });
        overlayEl.appendChild(panel);
        document.body.appendChild(overlayEl);
        document.addEventListener("keydown", onEsc);
        nameInput.focus();

        if (CONFIG.turnstile_site_key) {
            loadScript("https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit").then(function () {
                turnstileWidgetId = window.turnstile.render(turnstileHost, { sitekey: CONFIG.turnstile_site_key });
            }).catch(function () { /* server-side verification still gates */ });
        }

        submit.addEventListener("click", function () {
            error.textContent = "";
            submit.disabled = true;

            var body = new FormData();
            body.append("plan_price_id", String(selectedPrice.id));
            body.append("name", nameInput.value);
            body.append("email", emailInput.value);
            if (companyInput.value) body.append("company", companyInput.value);
            if (phoneInput.value) body.append("phone", phoneInput.value);
            body.append("_hp", hp.value);
            if (turnstileWidgetId !== null && window.turnstile) {
                body.append("cf-turnstile-response", window.turnstile.getResponse(turnstileWidgetId) || "");
            }

            fetch(CONFIG.checkout_url, {
                method: "POST",
                headers: { "Accept": "application/json" },
                body: body,
                credentials: "omit",
            }).then(function (resp) {
                return resp.json().then(function (json) { return { status: resp.status, json: json }; });
            }).then(function (r) {
                if (r.json && r.json.client_secret) {
                    return mountCheckout(stepWrap, r.json.client_secret);
                }
                var errs = (r.json && r.json.errors) || {};
                var first = Object.keys(errs)[0];
                error.textContent = first ? errs[first][0] : "Something went wrong — please try again.";
                submit.disabled = false;
                if (turnstileWidgetId !== null && window.turnstile) window.turnstile.reset(turnstileWidgetId);
            }).catch(function () {
                error.textContent = "Network error — please try again.";
                submit.disabled = false;
            });
        });
    }

    // Animated step swap: fix the wrapper at its current height, replace
    // the content, then transition to the incoming content's natural
    // height. overflow:hidden during the run prevents any flash of
    // clipped/zero-height content; on transitionend the height is
    // RELEASED to auto so later content growth — Stripe's embedded
    // Checkout resizes its own iframe for validation errors etc. — flows
    // naturally instead of fighting a pinned container.
    function swapStep(wrap, nodes) {
        var from = wrap.offsetHeight;
        wrap.style.height = from + "px";
        wrap.style.overflow = "hidden";
        wrap.style.transition = "height .3s ease";
        void wrap.offsetHeight; // commit the fixed start height
        wrap.innerHTML = "";
        nodes.forEach(function (n) { wrap.appendChild(n); });
        var to = wrap.scrollHeight;
        requestAnimationFrame(function () {
            wrap.style.height = to + "px";
        });
        wrap.addEventListener("transitionend", function done(e) {
            if (e.propertyName !== "height") return;
            wrap.removeEventListener("transitionend", done);
            wrap.style.height = "auto";
            wrap.style.overflow = "";
            wrap.style.transition = "";
        });
    }

    function mountCheckout(stepWrap, clientSecret) {
        return loadScript("https://js.stripe.com/v3/").then(function () {
            // Animate the panel from the form's height to the payment
            // step's placeholder height; the close button lives outside
            // the wrapper and is untouched.
            var host = el("div", { style: "min-height:420px;" });
            swapStep(stepWrap, [host]);
            var stripe = window.Stripe(CONFIG.stripe_key);
            return stripe.initEmbeddedCheckout({ clientSecret: clientSecret }).then(function (checkout) {
                // Stripe permits ONE mounted embedded Checkout per page —
                // hold the handle so closeModal() can destroy() it; a
                // reopen without destroy would refuse to mount.
                checkoutInstance = checkout;
                checkout.mount(host);
            });
        });
    }

    function onEsc(e) {
        if (e.key === "Escape") closeModal();
    }

    function closeModal() {
        if (checkoutInstance) {
            try { checkoutInstance.destroy(); } catch (e) { /* already gone */ }
            checkoutInstance = null;
        }
        turnstileWidgetId = null; // widget node is removed with the overlay
        if (overlayEl && overlayEl.parentNode) overlayEl.parentNode.removeChild(overlayEl);
        overlayEl = null;
        document.removeEventListener("keydown", onEsc);
        document.documentElement.style.overflow = prevOverflow || "";
    }

    function init() {
        var host = document.getElementById(ROOT_ID);
        if (!host) return;
        // Forms' isolation idiom: render into an open shadow root so host-
        // page CSS can't bleed in (fallback: light DOM for ancient engines).
        shadow = (typeof host.attachShadow === "function")
            ? (host.shadowRoot || host.attachShadow({ mode: "open" }))
            : host;
        renderGrid();
    }

    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", init);
    } else {
        init();
    }
})();
