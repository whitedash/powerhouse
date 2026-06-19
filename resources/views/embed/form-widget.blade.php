{{-- Embed widget. Output is application/javascript, so this --}}
{{-- file must be valid JS. Blade interpolates form data at the --}}
{{-- top, then a self-contained IIFE renders + wires the form. --}}
{{-- --}}
{{-- The JSON dump uses JSON_HEX_*  so it round-trips safely into --}}
{{-- a single-quoted JS string context (no </script> escape). --}}
@php
    $fields = $form->fields->map(fn ($f) => [
        'label' => $f->label,
        'field_key' => $f->field_key,
        'type' => $f->type,
        'placeholder' => $f->placeholder,
        'default_value' => $f->default_value,
        'options' => $f->options,
        'is_required' => (bool) $f->is_required,
        // Layout width within the widget's 12-col grid (full/half/third).
        'width' => $f->width->value,
        // Which step this field belongs to (drives the multi-step grouping).
        'form_step_id' => $f->form_step_id,
    ])->values()->all();

    // Steps ordered by sort_order (relation default). A single-step form has one
    // row, so is_multistep is false and the widget renders flat as before.
    $steps = $form->steps->map(fn ($s) => [
        'id' => $s->id,
        'label' => $s->label,
        'sort_order' => $s->sort_order,
    ])->values()->all();

    // Draft endpoints (anonymous, CSRF-excluded). Save/submit append the token
    // (and /submit) in JS once init() returns it.
    $draftBase = rtrim((string) config('app.url'), '/').'/forms/'.$form->slug.'/draft';

    $config = [
        'slug' => $form->slug,
        'name' => $form->name,
        'submit_url' => $submit_url,
        'submit_button_text' => $form->submit_button_text,
        'success_message' => $form->success_message ?? "Thank you! We'll be in touch soon.",
        'redirect_url' => $form->redirect_url,
        'gdpr_enabled' => (bool) $form->gdpr_consent_enabled,
        'gdpr_text' => $form->gdpr_consent_text,
        'fields' => $fields,
        'steps' => $steps,
        'is_multistep' => count($steps) > 1,
        'draft_init_url' => $draftBase,
        'draft_save_url' => $draftBase,
        'draft_submit_url' => $draftBase,
        // Effective design tokens resolved by the controller (default set
        // for an un-themed form, or defaults merged with a theme). Drives
        // the --pw-* CSS variables on the shadow root.
        'theme' => $tokens,
    ];
    $json = json_encode($config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES);
@endphp
(function () {
    "use strict";

    var CONFIG = {!! $json !!};
    var ROOT_ID = "pw-form-" + CONFIG.slug;

    // ── Multi-step state ──────────────────────────────────────────────
    var LS_KEY = "pw_draft_" + CONFIG.slug;
    var draftToken = null;
    var currentStep = 0;
    var totalSteps = CONFIG.is_multistep ? CONFIG.steps.length : 1;
    var stepGroups = [];   // [{ step, fields }] — populated in init()
    var answers = {};      // accumulated field values across steps
    var rootEl = null;     // mount point, cached by init()

    function el(tag, attrs, children) {
        var node = document.createElement(tag);
        if (attrs) {
            Object.keys(attrs).forEach(function (k) {
                if (k === "class") node.className = attrs[k];
                else if (k === "html") node.innerHTML = attrs[k];
                else node.setAttribute(k, attrs[k]);
            });
        }
        (children || []).forEach(function (c) {
            if (typeof c === "string") node.appendChild(document.createTextNode(c));
            else if (c) node.appendChild(c);
        });
        return node;
    }

    // Responsive by CONTAINER width: CSS @container collapses half/third to
    // full below ~480px of grid width. Where @container is unsupported, fall
    // back to a ResizeObserver that toggles the same collapse class.
    function watchGridWidth(grid) {
        var supported = window.CSS && CSS.supports && CSS.supports("container-type: inline-size");
        if (supported || typeof ResizeObserver === "undefined") return;
        var ro = new ResizeObserver(function (entries) {
            for (var i = 0; i < entries.length; i++) {
                grid.classList.toggle("pw-grid--narrow", entries[i].contentRect.width < 480);
            }
        });
        ro.observe(grid);
    }

    // Design tokens resolved server-side (default set == the original
    // hardcoded look, so an un-themed form is pixel-identical to before).
    var THEME = CONFIG.theme || {};

    function buildStyleEl() {
        var t = THEME;

        // CSS custom properties on .pw-form — children read them via var().
        // With the default token values these resolve to exactly the old
        // literals, so the rendered form is unchanged.
        var vars = ".pw-form{"
            + "--pw-font-family:" + t.font_family + ";"
            + "--pw-font-size:" + t.font_size + ";"
            + "--pw-text:" + t.text + ";"
            + "--pw-label:" + t.label + ";"
            + "--pw-accent:" + t.accent + ";"
            + "--pw-focus-ring:" + t.focus_ring + ";"
            + "--pw-bg:" + t.background + ";"
            + "--pw-surface:" + t.surface + ";"
            + "--pw-border:" + t.border + ";"
            + "--pw-border-width:" + t.border_width + ";"
            + "--pw-radius:" + t.radius + ";"
            + "--pw-form-padding:" + t.form_padding + ";"
            + "--pw-form-border-width:" + t.form_border_width + ";"
            + "--pw-form-border-radius:" + t.form_border_radius + ";"
            + "--pw-form-border-color:" + t.form_border_color + ";"
            + "--pw-button-bg:" + t.button_bg + ";"
            + "--pw-button-bg-hover:" + t.button_bg_hover + ";"
            + "--pw-button-text:" + t.button_text + ";"
            + "--pw-error:" + t.error + ";"
            + "--pw-success-bg:" + t.success_bg + ";"
            + "--pw-success-border:" + t.success_border + ";"
            + "--pw-success-text:" + t.success_text + ";"
            + "}";

        var css = vars
            + ".pw-form{font-family:var(--pw-font-family);color:var(--pw-text);background:var(--pw-bg);padding:var(--pw-form-padding);border:var(--pw-form-border-width) solid var(--pw-form-border-color);border-radius:var(--pw-form-border-radius);box-sizing:border-box;}"
            + ".pw-logo{max-height:48px;margin-bottom:16px;display:block;}"
            + ".pw-heading{font-size:18px;font-weight:600;margin:0 0 16px;color:var(--pw-text);}"
            // Fields lay out on a 12-col grid; the gap replaces the old
            // per-row margin (single-column all-full = same rhythm as before).
            + ".pw-form .pw-grid{display:grid;grid-template-columns:repeat(12,1fr);gap:14px;container-type:inline-size;margin-bottom:14px;}"
            + ".pw-form .pw-row{margin:0;min-width:0;}"
            + ".pw-form .pw-col-12{grid-column:span 12;}"
            + ".pw-form .pw-col-6{grid-column:span 6;}"
            + ".pw-form .pw-col-4{grid-column:span 4;}"
            // Collapse to one column on a NARROW CONTAINER (not viewport).
            + "@container (max-width:400px){.pw-form .pw-col-6,.pw-form .pw-col-4{grid-column:span 12;}}"
            // ResizeObserver fallback toggles this class where @container is unsupported.
            + ".pw-form .pw-grid.pw-grid--narrow .pw-col-6,.pw-form .pw-grid.pw-grid--narrow .pw-col-4{grid-column:span 12;}"
            + ".pw-form label{display:block;font-weight:600;font-size:13px;margin-bottom:6px;color:var(--pw-label);}"
            + ".pw-form .pw-req{color:var(--pw-error);}"
            + ".pw-form input,.pw-form textarea,.pw-form select{width:100%;padding:10px 12px;border:var(--pw-border-width) solid var(--pw-border);border-radius:var(--pw-radius);font-size:var(--pw-font-size);font-family:inherit;background:var(--pw-surface);box-sizing:border-box;}"
            + ".pw-form input:focus,.pw-form textarea:focus,.pw-form select:focus{outline:none;border-color:var(--pw-accent);box-shadow:0 0 0 3px var(--pw-focus-ring);}"
            + ".pw-form textarea{min-height:96px;resize:vertical;}"
            + ".pw-form .pw-hp{position:absolute;left:-9999px;width:1px;height:1px;opacity:0;}"
            // Solid button keeps border:none (pixel-identical default); the
            // outline variant adds its own border below.
            // Modern easing/timing across transform/shadow/background/colour.
            + ".pw-form button{background:var(--pw-button-bg);color:var(--pw-button-text);border:none;border-radius:var(--pw-radius);padding:10px 18px;font-size:var(--pw-font-size);font-weight:600;cursor:pointer;font-family:inherit;display:inline-flex;align-items:center;justify-content:center;gap:8px;transition:transform .2s cubic-bezier(.4,0,.2,1),box-shadow .2s cubic-bezier(.4,0,.2,1),background .2s cubic-bezier(.4,0,.2,1),color .2s cubic-bezier(.4,0,.2,1);}"
            + ".pw-form button:focus-visible{outline:2px solid var(--pw-accent);outline-offset:2px;}"
            + ".pw-form button:active{transform:translateY(1px);}"
            + ".pw-form button:disabled{opacity:0.6;cursor:not-allowed;}"
            + ".pw-form button.pw-btn-block{width:100%;}"
            + ".pw-form button.pw-btn-outline{background:transparent;color:var(--pw-button-bg);border:var(--pw-border-width) solid var(--pw-button-bg);}"
            // Submit-button icon: inherits text colour, scales with font, and
            // directional icons slide subtly on hover.
            + ".pw-form .pw-btn-icon{display:inline-flex;align-items:center;transition:transform .2s cubic-bezier(.4,0,.2,1);}"
            + ".pw-form .pw-btn-icon svg{width:1em;height:1em;display:block;}"
            + ".pw-form button:hover .pw-btn-icon-dir.pw-btn-icon-trail{transform:translateX(3px);}"
            + ".pw-form button:hover .pw-btn-icon-dir.pw-btn-icon-lead{transform:translateX(-3px);}"
            // Hover: lift — rise + soft elevation.
            + ".pw-form button.pw-btn-hover-lift:hover{transform:translateY(-2px);box-shadow:0 6px 16px -4px rgba(0,0,0,0.25);}"
            + ".pw-form button.pw-btn-hover-lift:active{transform:translateY(0);box-shadow:none;}"
            // Hover: glow — translucent coloured ring + halo from the button colour.
            + ".pw-form button.pw-btn-hover-glow:hover{box-shadow:0 0 0 4px color-mix(in srgb,var(--pw-button-bg) 28%,transparent),0 4px 14px -2px color-mix(in srgb,var(--pw-button-bg) 45%,transparent);}"
            // Hover: shine — a light sheen sweeps across (clipped pseudo-element).
            + ".pw-form button.pw-btn-hover-shine{position:relative;overflow:hidden;}"
            + ".pw-form button.pw-btn-hover-shine::after{content:'';position:absolute;top:0;left:-150%;width:60%;height:100%;background:linear-gradient(120deg,transparent,rgba(255,255,255,0.35),transparent);transform:skewX(-20deg);transition:left .5s cubic-bezier(.4,0,.2,1);pointer-events:none;}"
            + ".pw-form button.pw-btn-hover-shine:hover::after{left:150%;}"
            // Hover: fill — colour overlay wipes in from the left (behind the text).
            + ".pw-form button.pw-btn-hover-fill{position:relative;overflow:hidden;z-index:0;}"
            + ".pw-form button.pw-btn-hover-fill::before{content:'';position:absolute;inset:0;background:var(--pw-button-bg-hover);transform:scaleX(0);transform-origin:left;transition:transform .2s cubic-bezier(.4,0,.2,1);z-index:-1;}"
            + ".pw-form button.pw-btn-hover-fill:hover::before{transform:scaleX(1);}"
            + ".pw-form button.pw-btn-outline.pw-btn-hover-fill::before{background:var(--pw-button-bg);}"
            + ".pw-form button.pw-btn-outline.pw-btn-hover-fill:hover{color:var(--pw-button-text);}"
            + ".pw-form .pw-err{color:var(--pw-error);font-size:12px;margin-top:4px;}"
            + ".pw-form .pw-success{padding:16px;background:var(--pw-success-bg);border:var(--pw-border-width) solid var(--pw-success-border);border-radius:var(--pw-radius);color:var(--pw-success-text);}"
            + ".pw-form .pw-gdpr{font-size:12px;color:#6b7280;margin-top:8px;}"
            + ".pw-form .pw-gdpr label{font-weight:400;font-size:12px;display:flex;gap:8px;align-items:flex-start;}"
            + ".pw-form .pw-gdpr input{width:auto;}"
            // Multi-step chrome — progress bar, nav row, autosave hint, resume
            // banner. Styled from the same --pw-* tokens as the rest of the form.
            + ".pw-form .ms-progress{display:flex;flex-direction:column;gap:8px;margin-bottom:18px;}"
            + ".pw-form .ms-progress-head{display:flex;align-items:baseline;justify-content:space-between;gap:10px;}"
            + ".pw-form .ms-progress-step{font-size:11px;text-transform:uppercase;letter-spacing:.12em;color:var(--pw-label);}"
            + ".pw-form .ms-progress-label{font-size:14px;font-weight:600;color:var(--pw-text);}"
            + ".pw-form .ms-seg-track{display:flex;gap:6px;}"
            + ".pw-form .ms-seg{flex:1;height:6px;border-radius:999px;background:var(--pw-border);}"
            + ".pw-form .ms-seg.done{background:var(--pw-accent);}"
            + ".pw-form .ms-seg.current{background:var(--pw-accent);box-shadow:0 0 0 3px var(--pw-focus-ring);}"
            + ".pw-form .ms-nav{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-top:18px;}"
            + ".pw-form .ms-nav .ms-nav-spacer{flex:1;}"
            + ".pw-form .ms-autosave{display:flex;align-items:center;justify-content:center;gap:6px;font-size:12px;color:var(--pw-label);margin-top:12px;}"
            + ".pw-resume{display:flex;flex-direction:column;gap:6px;border:1px solid var(--pw-border);border-left:3px solid var(--pw-accent);border-radius:var(--pw-radius);padding:14px 16px;background:var(--pw-surface);margin-bottom:18px;}"
            + ".pw-resume .ms-resume-ttl{font-size:14px;font-weight:600;color:var(--pw-text);}"
            + ".pw-resume .ms-resume-msg{font-size:13px;color:var(--pw-label);}"
            + ".pw-resume .ms-resume-actions{display:flex;gap:10px;margin-top:6px;}"
            + ".ms-inert{opacity:.4;filter:blur(1px);pointer-events:none;user-select:none;}";

        // Optional per-theme custom CSS, injected AFTER the variable styles
        // (inside the shadow root, so it can't leak to the host page).
        if (t.custom_css) {
            css += String(t.custom_css);
        }

        var style = document.createElement("style");
        style.id = "pw-form-styles";
        style.appendChild(document.createTextNode(css));
        return style;
    }

    // Field width → 12-col grid span class.
    var WIDTH_COLS = { full: "pw-col-12", half: "pw-col-6", third: "pw-col-4" };

    // Inline SVG icon set for the submit button. stroke=currentColor so each
    // icon inherits the button text colour; 1em so it scales with the font.
    var ICONS = {
        arrow: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14"/><path d="M13 6l6 6l-6 6"/></svg>',
        send: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10 14l11 -11"/><path d="M21 3l-6.5 18a.55 .55 0 0 1 -1 0l-3.5 -7l-7 -3.5a.55 .55 0 0 1 0 -1z"/></svg>',
        chevron: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 6l6 6l-6 6"/></svg>',
        check: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12l5 5l10 -10"/></svg>',
        download: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 17v2a2 2 0 0 0 2 2h12a2 2 0 0 0 2 -2v-2"/><path d="M7 11l5 5l5 -5"/><path d="M12 4v12"/></svg>',
    };
    // Icons that imply direction (slide on hover).
    var DIRECTIONAL_ICONS = { arrow: true, chevron: true };

    function renderField(field) {
        var span = WIDTH_COLS[field.width] || "pw-col-12";
        var row = el("div", { class: "pw-row " + span });
        var labelChildren = [field.label];
        if (field.is_required) labelChildren.push(el("span", { class: "pw-req" }, [" *"]));
        if (field.type !== "hidden") row.appendChild(el("label", { for: "pw-" + field.field_key }, labelChildren));

        // Repopulate from the running answer set (so Back/Next keeps values),
        // falling back to the field's configured default.
        var currentVal = answers[field.field_key];
        if (currentVal == null) currentVal = field.default_value || "";

        var input;
        if (field.type === "textarea") {
            input = el("textarea", {
                name: field.field_key,
                id: "pw-" + field.field_key,
                placeholder: field.placeholder || "",
            }, [String(currentVal)]);
        } else if (field.type === "select") {
            var opts = (field.options || []).map(function (o) {
                return el("option", { value: o }, [o]);
            });
            opts.unshift(el("option", { value: "" }, ["Choose..."]));
            input = el("select", {
                name: field.field_key,
                id: "pw-" + field.field_key,
            }, opts);
            input.value = String(currentVal);
        } else {
            input = el("input", {
                type: field.type === "phone" ? "tel" : field.type,
                name: field.field_key,
                id: "pw-" + field.field_key,
                placeholder: field.placeholder || "",
                value: String(currentVal),
            });
        }
        if (field.is_required) input.setAttribute("required", "required");
        row.appendChild(input);

        var err = el("div", { class: "pw-err", id: "pw-err-" + field.field_key });
        row.appendChild(err);
        return row;
    }

    // Collect the current step's field values into the running answer set.
    function collectAnswers(form) {
        var fd = new FormData(form);
        fd.forEach(function (v, k) {
            if (k === "_hp" || k === "_gdpr") return;
            answers[k] = v;
        });
    }

    // Optional theme chrome rendered ABOVE the form (logo, then heading).
    // Both default to null → nothing rendered → identical to before.
    function buildLead() {
        var lead = [];
        if (THEME.logo_url) {
            lead.push(el("img", {
                class: "pw-logo",
                src: THEME.logo_url,
                alt: THEME.heading || CONFIG.name || "",
            }));
        }
        if (THEME.heading) {
            lead.push(el("div", { class: "pw-heading" }, [THEME.heading]));
        }
        return lead;
    }

    // Mount the stylesheet + lead chrome + given nodes into the root, preferring
    // an isolated shadow root (host CSS can't bleed in, our CSS can't leak out).
    function mount(nodes) {
        var lead = buildLead();
        if (typeof rootEl.attachShadow === "function") {
            var shadow = rootEl.shadowRoot || rootEl.attachShadow({ mode: "open" });
            shadow.innerHTML = "";
            shadow.appendChild(buildStyleEl());
            lead.forEach(function (n) { shadow.appendChild(n); });
            nodes.forEach(function (n) { shadow.appendChild(n); });
            return;
        }
        if (!document.getElementById("pw-form-styles")) {
            document.head.appendChild(buildStyleEl());
        }
        rootEl.innerHTML = "";
        lead.forEach(function (n) { rootEl.appendChild(n); });
        nodes.forEach(function (n) { rootEl.appendChild(n); });
    }

    // Segmented progress indicator for the given step (N done · 1 current).
    function buildProgress(stepIndex) {
        var wrap = el("div", { class: "ms-progress" });
        var head = el("div", { class: "ms-progress-head" });
        head.appendChild(el("span", { class: "ms-progress-step" }, ["Step " + (stepIndex + 1) + " of " + totalSteps]));
        var label = stepGroups[stepIndex].step.label || "";
        if (label) head.appendChild(el("span", { class: "ms-progress-label" }, [label]));
        wrap.appendChild(head);
        var track = el("div", { class: "ms-seg-track" });
        for (var i = 0; i < totalSteps; i++) {
            var cls = "ms-seg";
            if (i < stepIndex) cls += " done";
            else if (i === stepIndex) cls += " current";
            track.appendChild(el("span", { class: cls }));
        }
        wrap.appendChild(track);
        return wrap;
    }

    // Build the primary action button. The theme icon is applied on the final
    // step only (matching the original single-step submit button).
    function buildPrimaryButton(isLast, labelSpan) {
        var btnClass = "";
        if (THEME.button_style === "outline") btnClass += "pw-btn-outline ";
        if (THEME.full_width) btnClass += "pw-btn-block ";
        btnClass += "pw-btn-hover-" + (THEME.button_hover || "lift");
        btnClass = btnClass.trim();
        var btnAttrs = { type: "submit" };
        if (btnClass) btnAttrs.class = btnClass;

        var children = [labelSpan];
        if (isLast) {
            var iconName = THEME.button_icon || "none";
            var position = THEME.button_icon_position === "leading" ? "leading" : "trailing";
            if (iconName !== "none" && ICONS[iconName]) {
                var iconCls = "pw-btn-icon";
                if (DIRECTIONAL_ICONS[iconName]) {
                    iconCls += " pw-btn-icon-dir " + (position === "leading" ? "pw-btn-icon-lead" : "pw-btn-icon-trail");
                }
                var iconSpan = el("span", { class: iconCls, html: ICONS[iconName], "aria-hidden": "true" });
                children = position === "leading" ? [iconSpan, labelSpan] : [labelSpan, iconSpan];
            }
        }
        return el("button", btnAttrs, children);
    }

    // Build the form for one step. The submit event drives BOTH "next" (save +
    // advance) and the final submit, branching on whether this is the last step.
    function buildStepForm(stepIndex) {
        var isLast = stepIndex === totalSteps - 1;
        var form = el("form", { class: "pw-form", novalidate: "novalidate" });

        // Error nodes live inside the form, so query through it rather than
        // document.getElementById — once inside a shadow root the document-level
        // lookup can't see it.
        function errNode(key) {
            return form.querySelector('[id="pw-err-' + key + '"]');
        }

        if (CONFIG.is_multistep) {
            form.appendChild(buildProgress(stepIndex));
        }

        // Fields for this step on the 12-col grid.
        var grid = el("div", { class: "pw-grid" });
        stepGroups[stepIndex].fields.forEach(function (f) {
            grid.appendChild(renderField(f));
        });
        form.appendChild(grid);
        watchGridWidth(grid);

        // Honeypot on every step — invisible to humans, irresistible to bots
        // (checked server-side on the final submit only).
        form.appendChild(el("input", { type: "text", name: "_hp", class: "pw-hp", tabindex: "-1", autocomplete: "off" }));

        // GDPR consent on the last step, just before submit.
        if (CONFIG.gdpr_enabled && isLast) {
            form.appendChild(el("div", { class: "pw-gdpr" }, [
                el("label", {}, [
                    el("input", { type: "checkbox", name: "_gdpr", required: "required" }),
                    el("span", {}, [CONFIG.gdpr_text || "I agree to be contacted about my enquiry."]),
                ]),
            ]));
        }

        // Label lives in its own span so the handler can swap the text without
        // wiping the icon. Next on intermediate steps; Submit on the last.
        var label = isLast ? (CONFIG.submit_button_text || "Submit") : "Next";
        var labelSpan = el("span", { class: "pw-btn-label" }, [label]);
        var primary = buildPrimaryButton(isLast, labelSpan);

        if (CONFIG.is_multistep) {
            var nav = el("div", { class: "ms-nav" });
            if (stepIndex > 0) {
                var back = el("button", { type: "button", class: "pw-btn-outline" }, ["Back"]);
                back.addEventListener("click", function () {
                    collectAnswers(form);
                    currentStep = stepIndex - 1;
                    render(currentStep);
                });
                nav.appendChild(back);
            } else {
                nav.appendChild(el("span", { class: "ms-nav-spacer" }));
            }
            nav.appendChild(primary);
            form.appendChild(nav);
            form.appendChild(el("div", { class: "ms-autosave" }, ["Your progress is saved automatically"]));
        } else {
            form.appendChild(primary);
        }

        form.addEventListener("submit", function (e) {
            e.preventDefault();
            collectAnswers(form);

            // Intermediate step → save the draft, then advance.
            if (!isLast) {
                primary.disabled = true;
                labelSpan.textContent = "Saving...";
                saveDraft(stepIndex).then(function () {
                    currentStep = stepIndex + 1;
                    render(currentStep);
                }).catch(function () {
                    primary.disabled = false;
                    labelSpan.textContent = label;
                });
                return;
            }

            // Last step → clear errors and submit.
            primary.disabled = true;
            labelSpan.textContent = "Sending...";
            CONFIG.fields.forEach(function (f) {
                var ne = errNode(f.field_key);
                if (ne) ne.textContent = "";
            });

            var request;
            if (CONFIG.is_multistep) {
                // Persist the final step, then promote the draft to a submission.
                request = saveDraft(stepIndex).then(function () {
                    return fetch(CONFIG.draft_submit_url + "/" + draftToken + "/submit", {
                        method: "POST",
                        headers: { "Accept": "application/json" },
                        credentials: "omit",
                    });
                });
            } else {
                // Single-step → original direct submit (unchanged behaviour).
                request = fetch(CONFIG.submit_url, {
                    method: "POST",
                    headers: { "Accept": "application/json" },
                    body: new FormData(form),
                    credentials: "omit",
                });
            }

            request.then(function (resp) {
                return resp.json().then(function (json) { return { status: resp.status, json: json }; });
            }).then(function (r) {
                if (r.status === 422 && r.json && r.json.errors) {
                    Object.keys(r.json.errors).forEach(function (k) {
                        var ne = errNode(k);
                        if (ne) ne.textContent = r.json.errors[k][0];
                    });
                    primary.disabled = false;
                    labelSpan.textContent = label;
                    return;
                }
                if (r.status === 429) {
                    labelSpan.textContent = "Try again later";
                    return;
                }
                if (r.json && r.json.redirect) {
                    if (CONFIG.is_multistep) clearDraft();
                    window.location.href = r.json.redirect;
                    return;
                }
                if (CONFIG.is_multistep) clearDraft();
                var success = el("div", { class: "pw-success" }, [
                    r.json && r.json.message ? r.json.message : CONFIG.success_message,
                ]);
                form.parentNode.replaceChild(success, form);
            }).catch(function () {
                primary.disabled = false;
                labelSpan.textContent = label;
                var generic = errNode(CONFIG.fields[0] && CONFIG.fields[0].field_key);
                if (generic) generic.textContent = "Submission failed. Please try again.";
            });
        });

        return form;
    }

    // Persist one step's answers; lazily creates the draft on first save and
    // stores the token in localStorage so the respondent can resume later.
    function saveDraft(stepIndex) {
        if (!CONFIG.is_multistep) return Promise.resolve();
        var ensure = draftToken
            ? Promise.resolve()
            : fetch(CONFIG.draft_init_url, {
                method: "POST",
                headers: { "Accept": "application/json" },
                credentials: "omit",
            }).then(function (resp) { return resp.json(); }).then(function (data) {
                draftToken = data.draft_token;
                try { localStorage.setItem(LS_KEY, draftToken); } catch (e) {}
            });
        return ensure.then(function () {
            return fetch(CONFIG.draft_save_url + "/" + draftToken, {
                method: "PUT",
                headers: { "Content-Type": "application/json", "Accept": "application/json" },
                credentials: "omit",
                body: JSON.stringify({ step: stepIndex + 1, answers: answers }),
            });
        }).then(function () {
            try { localStorage.setItem(LS_KEY + "_step", String(stepIndex + 1)); } catch (e) {}
        });
    }

    function clearDraft() {
        try {
            localStorage.removeItem(LS_KEY);
            localStorage.removeItem(LS_KEY + "_step");
        } catch (e) {}
        draftToken = null;
        currentStep = 0;
    }

    // Continue from a saved token — trust it (the backend validates on submit)
    // and resume at the last persisted step.
    function resumeDraft(token) {
        draftToken = token;
        var saved = 0;
        try { saved = parseInt(localStorage.getItem(LS_KEY + "_step"), 10) || 0; } catch (e) {}
        currentStep = Math.min(Math.max(saved, 0), totalSteps - 1);
        render(currentStep);
    }

    // Resume banner (STATE 3) — shown on load when a saved token is present.
    // The step-0 fields render beneath it, inert until the respondent chooses.
    function showResumeBanner(token) {
        var actions = el("div", { class: "ms-resume-actions" });
        var cont = el("button", { type: "button", class: "pw-btn-hover-lift" }, ["Continue"]);
        cont.addEventListener("click", function () { resumeDraft(token); });
        var over = el("button", { type: "button", class: "pw-btn-outline" }, ["Start over"]);
        over.addEventListener("click", function () { clearDraft(); render(0); });
        actions.appendChild(cont);
        actions.appendChild(over);

        var banner = el("div", { class: "pw-resume" }, [
            el("div", { class: "ms-resume-ttl" }, ["Welcome back"]),
            el("div", { class: "ms-resume-msg" }, ["Continue where you left off, or start over."]),
            actions,
        ]);

        var grid = el("div", { class: "pw-grid" });
        stepGroups[0].fields.forEach(function (f) { grid.appendChild(renderField(f)); });
        var inert = el("div", { class: "ms-inert" }, [grid]);

        mount([banner, inert]);
    }

    function render(stepIndex) {
        mount([buildStepForm(stepIndex)]);
    }

    function init() {
        rootEl = document.getElementById(ROOT_ID);
        if (!rootEl) return;

        // Group fields by step. Single-step forms collapse to one group so the
        // flat render path stays byte-for-byte the original behaviour.
        if (CONFIG.is_multistep) {
            stepGroups = CONFIG.steps.map(function (step) {
                return {
                    step: step,
                    fields: CONFIG.fields.filter(function (f) { return f.form_step_id === step.id; }),
                };
            });
        } else {
            stepGroups = [{ step: { label: "", sort_order: 0 }, fields: CONFIG.fields }];
        }

        // Resume a saved draft (multi-step only) before the first render.
        var savedToken = null;
        try { savedToken = localStorage.getItem(LS_KEY); } catch (e) {}
        if (savedToken && CONFIG.is_multistep) {
            showResumeBanner(savedToken);
            return;
        }

        render(currentStep);
    }

    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", init);
    } else {
        init();
    }
})();
