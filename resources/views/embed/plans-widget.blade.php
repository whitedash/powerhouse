{{-- Plans embed widget (PLANS-WIDGET-DESIGN.md §3a). Output is --}}
{{-- application/javascript, so this file must be valid JS. Blade --}}
{{-- interpolates the catalog at the top, then a self-contained IIFE --}}
{{-- renders plan cards + the purchase flow. Same delivery mechanism --}}
{{-- as embed/form-widget.blade.php; v1 ships a single static token --}}
{{-- set rather than the forms theme system (design Q13). --}}
{{-- --}}
{{-- The JSON dump uses JSON_HEX_* so it round-trips safely into the --}}
{{-- JS context (no </script> escape). --}}
@php
    $plans = $product->plans->map(fn ($plan) => [
        'id' => $plan->id,
        'name' => $plan->name,
        'description' => $plan->description,
        'features' => array_values(array_filter((array) ($plan->features ?? []), 'is_string')),
        'prices' => $plan->activePrices->map(fn ($price) => [
            'id' => $price->id,
            'label' => $price->label,
            'amount' => '£'.number_format((float) $price->price, 2),
            'interval' => $price->interval_label,
            'is_default' => (bool) $price->is_default,
        ])->values()->all(),
    ])->filter(fn (array $plan) => $plan['prices'] !== [])->values()->all();

    $config = [
        'slug' => $product->slug,
        'product_name' => $product->name,
        'plans' => $plans,
        'checkout_url' => $checkout_url,
        // Publishable key + site key are public by definition — they ship
        // to every browser that renders the widget.
        'stripe_key' => $stripe_key,
        'turnstile_site_key' => $turnstile_site_key,
    ];
    $json = json_encode($config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES);
@endphp
(function () {
    "use strict";

    var CONFIG = {!! $json !!};
    var ROOT_ID = "pw-plans-" + CONFIG.slug;
    var rootEl = null;
    var turnstileWidgetId = null;
    var selectedPrice = null;

    function el(tag, attrs, children) {
        var node = document.createElement(tag);
        if (attrs) {
            Object.keys(attrs).forEach(function (k) {
                if (k === "class") node.className = attrs[k];
                else node.setAttribute(k, attrs[k]);
            });
        }
        (children || []).forEach(function (c) {
            if (typeof c === "string") node.appendChild(document.createTextNode(c));
            else if (c) node.appendChild(c);
        });
        return node;
    }

    // External scripts are loaded lazily, only once a visitor starts a
    // purchase — a page that merely SHOWS the pricing grid never pulls
    // Stripe.js or Turnstile.
    var loaded = {};
    function loadScript(src) {
        if (loaded[src]) return loaded[src];
        loaded[src] = new Promise(function (resolve, reject) {
            var s = document.createElement("script");
            s.src = src;
            s.async = true;
            s.onload = resolve;
            s.onerror = reject;
            document.head.appendChild(s);
        });
        return loaded[src];
    }

    var STYLE = [
        "#", ROOT_ID, " { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; color: #0f172a; }",
        "#", ROOT_ID, " .pw-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(230px, 1fr)); gap: 16px; }",
        "#", ROOT_ID, " .pw-plan { border: 1px solid #e2e8f0; border-radius: 12px; background: #fff; padding: 22px; display: flex; flex-direction: column; }",
        "#", ROOT_ID, " .pw-plan h3 { margin: 0 0 6px; font-size: 17px; }",
        "#", ROOT_ID, " .pw-desc { font-size: 13px; color: #64748b; margin: 0 0 14px; line-height: 1.5; }",
        "#", ROOT_ID, " .pw-features { list-style: none; margin: 0 0 16px; padding: 0; font-size: 13px; color: #334155; }",
        "#", ROOT_ID, " .pw-features li { padding: 3px 0 3px 20px; position: relative; }",
        "#", ROOT_ID, " .pw-features li:before { content: '\\2713'; position: absolute; left: 0; color: #16a34a; }",
        "#", ROOT_ID, " .pw-price-row { display: flex; align-items: baseline; justify-content: space-between; gap: 8px; padding: 8px 0; border-top: 1px solid #f1f5f9; margin-top: auto; }",
        "#", ROOT_ID, " .pw-amount { font-size: 18px; font-weight: 700; }",
        "#", ROOT_ID, " .pw-interval { font-size: 12px; color: #94a3b8; }",
        "#", ROOT_ID, " .pw-btn { border: 0; border-radius: 8px; background: #0f172a; color: #fff; font-size: 13px; font-weight: 600; padding: 8px 14px; cursor: pointer; }",
        "#", ROOT_ID, " .pw-btn:disabled { opacity: .55; cursor: default; }",
        "#", ROOT_ID, " .pw-panel { border: 1px solid #e2e8f0; border-radius: 12px; background: #fff; padding: 22px; max-width: 480px; }",
        "#", ROOT_ID, " .pw-panel label { display: block; font-size: 13px; font-weight: 600; margin: 0 0 4px; }",
        "#", ROOT_ID, " .pw-panel input { width: 100%; box-sizing: border-box; border: 1px solid #cbd5e1; border-radius: 8px; padding: 9px 11px; font-size: 14px; margin: 0 0 14px; }",
        "#", ROOT_ID, " .pw-error { color: #dc2626; font-size: 13px; margin: 0 0 10px; min-height: 16px; }",
        "#", ROOT_ID, " .pw-back { background: none; border: 0; color: #64748b; font-size: 13px; cursor: pointer; padding: 0; margin: 0 0 14px; }",
        "#", ROOT_ID, " .pw-checkout { min-height: 400px; }",
    ].join("");

    function renderGrid() {
        rootEl.innerHTML = "";
        var grid = el("div", { "class": "pw-grid" });
        CONFIG.plans.forEach(function (plan) {
            var card = el("div", { "class": "pw-plan" }, [
                el("h3", null, [plan.name]),
                plan.description ? el("p", { "class": "pw-desc" }, [plan.description]) : null,
                plan.features.length
                    ? el("ul", { "class": "pw-features" }, plan.features.map(function (f) { return el("li", null, [f]); }))
                    : null,
            ]);
            plan.prices.forEach(function (price) {
                var btn = el("button", { "class": "pw-btn", type: "button" }, ["Choose"]);
                btn.addEventListener("click", function () { renderPurchase(plan, price); });
                card.appendChild(el("div", { "class": "pw-price-row" }, [
                    el("span", null, [
                        el("span", { "class": "pw-amount" }, [price.amount]),
                        el("span", { "class": "pw-interval" }, [" " + (price.label || price.interval)]),
                    ]),
                    btn,
                ]));
            });
            grid.appendChild(card);
        });
        rootEl.appendChild(grid);
    }

    function renderPurchase(plan, price) {
        selectedPrice = price;
        rootEl.innerHTML = "";

        var back = el("button", { "class": "pw-back", type: "button" }, ["← All plans"]);
        back.addEventListener("click", renderGrid);

        var error = el("p", { "class": "pw-error" }, []);
        var turnstileHost = el("div", null, []);
        var submit = el("button", { "class": "pw-btn", type: "button" }, ["Continue to payment — " + price.amount]);
        var nameInput = el("input", { type: "text", autocomplete: "name" });
        var emailInput = el("input", { type: "email", autocomplete: "email" });
        // Honeypot: hidden from humans, tempting to bots.
        var hp = el("input", { type: "text", tabindex: "-1", autocomplete: "off", "aria-hidden": "true" });
        hp.style.cssText = "position:absolute;left:-9999px;height:1px;width:1px;opacity:0;";

        var panel = el("div", { "class": "pw-panel" }, [
            back,
            el("h3", null, [plan.name + " — " + price.amount + " " + (price.label || price.interval)]),
            el("label", null, ["Your name"]), nameInput,
            el("label", null, ["Email address"]), emailInput,
            hp, turnstileHost, error, submit,
        ]);
        rootEl.appendChild(panel);

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
                    return mountCheckout(r.json.client_secret);
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

    function mountCheckout(clientSecret) {
        return loadScript("https://js.stripe.com/v3/").then(function () {
            rootEl.innerHTML = "";
            var host = el("div", { "class": "pw-checkout" });
            rootEl.appendChild(host);
            var stripe = window.Stripe(CONFIG.stripe_key);
            return stripe.initEmbeddedCheckout({ clientSecret: clientSecret }).then(function (checkout) {
                checkout.mount(host);
            });
        });
    }

    function init() {
        rootEl = document.getElementById(ROOT_ID);
        if (!rootEl) return;
        var style = document.createElement("style");
        style.textContent = STYLE;
        document.head.appendChild(style);
        renderGrid();
    }

    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", init);
    } else {
        init();
    }
})();
