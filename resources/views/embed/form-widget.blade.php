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
    ])->values()->all();

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
    ];
    $json = json_encode($config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES);
@endphp
(function () {
    "use strict";

    var CONFIG = {!! $json !!};
    var ROOT_ID = "pw-form-" + CONFIG.slug;

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

    function injectStyles() {
        if (document.getElementById("pw-form-styles")) return;
        var css = ""
            + ".pw-form{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;color:#1f2937;max-width:560px;}"
            + ".pw-form .pw-row{margin-bottom:14px;}"
            + ".pw-form label{display:block;font-weight:600;font-size:13px;margin-bottom:6px;color:#374151;}"
            + ".pw-form .pw-req{color:#ef4444;}"
            + ".pw-form input,.pw-form textarea,.pw-form select{width:100%;padding:10px 12px;border:1px solid #d1d5db;border-radius:6px;font-size:14px;font-family:inherit;background:#fff;box-sizing:border-box;}"
            + ".pw-form input:focus,.pw-form textarea:focus,.pw-form select:focus{outline:none;border-color:#6366F1;box-shadow:0 0 0 3px rgba(99,102,241,0.15);}"
            + ".pw-form textarea{min-height:96px;resize:vertical;}"
            + ".pw-form .pw-hp{position:absolute;left:-9999px;width:1px;height:1px;opacity:0;}"
            + ".pw-form button{background:#0F172A;color:#fff;border:none;border-radius:6px;padding:10px 18px;font-size:14px;font-weight:600;cursor:pointer;font-family:inherit;}"
            + ".pw-form button:hover{background:#1f2937;}"
            + ".pw-form button:disabled{opacity:0.6;cursor:not-allowed;}"
            + ".pw-form .pw-err{color:#ef4444;font-size:12px;margin-top:4px;}"
            + ".pw-form .pw-success{padding:16px;background:#ecfdf5;border:1px solid #10b981;border-radius:6px;color:#065f46;}"
            + ".pw-form .pw-gdpr{font-size:12px;color:#6b7280;margin-top:8px;}"
            + ".pw-form .pw-gdpr label{font-weight:400;font-size:12px;display:flex;gap:8px;align-items:flex-start;}"
            + ".pw-form .pw-gdpr input{width:auto;}";
        var style = document.createElement("style");
        style.id = "pw-form-styles";
        style.appendChild(document.createTextNode(css));
        document.head.appendChild(style);
    }

    function renderField(field) {
        var row = el("div", { class: "pw-row" });
        var labelChildren = [field.label];
        if (field.is_required) labelChildren.push(el("span", { class: "pw-req" }, [" *"]));
        if (field.type !== "hidden") row.appendChild(el("label", { for: "pw-" + field.field_key }, labelChildren));

        var input;
        if (field.type === "textarea") {
            input = el("textarea", {
                name: field.field_key,
                id: "pw-" + field.field_key,
                placeholder: field.placeholder || "",
            });
        } else if (field.type === "select") {
            var opts = (field.options || []).map(function (o) {
                return el("option", { value: o }, [o]);
            });
            opts.unshift(el("option", { value: "" }, ["Choose..."]));
            input = el("select", {
                name: field.field_key,
                id: "pw-" + field.field_key,
            }, opts);
        } else {
            input = el("input", {
                type: field.type === "phone" ? "tel" : field.type,
                name: field.field_key,
                id: "pw-" + field.field_key,
                placeholder: field.placeholder || "",
                value: field.default_value || "",
            });
        }
        if (field.is_required) input.setAttribute("required", "required");
        row.appendChild(input);

        var err = el("div", { class: "pw-err", id: "pw-err-" + field.field_key });
        row.appendChild(err);
        return row;
    }

    function render(root) {
        injectStyles();
        var form = el("form", { class: "pw-form", novalidate: "novalidate" });

        CONFIG.fields.forEach(function (f) {
            form.appendChild(renderField(f));
        });

        // Honeypot — invisible to humans, irresistible to bots.
        var hp = el("input", { type: "text", name: "_hp", class: "pw-hp", tabindex: "-1", autocomplete: "off" });
        form.appendChild(hp);

        if (CONFIG.gdpr_enabled) {
            var consent = el("div", { class: "pw-gdpr" }, [
                el("label", {}, [
                    el("input", { type: "checkbox", name: "_gdpr", required: "required" }),
                    el("span", {}, [CONFIG.gdpr_text || "I agree to be contacted about my enquiry."]),
                ]),
            ]);
            form.appendChild(consent);
        }

        var btn = el("button", { type: "submit" }, [CONFIG.submit_button_text || "Submit"]);
        form.appendChild(btn);

        form.addEventListener("submit", function (e) {
            e.preventDefault();
            btn.disabled = true;
            btn.textContent = "Sending...";

            // Clear previous errors.
            CONFIG.fields.forEach(function (f) {
                var ne = document.getElementById("pw-err-" + f.field_key);
                if (ne) ne.textContent = "";
            });

            var data = new FormData(form);
            fetch(CONFIG.submit_url, {
                method: "POST",
                headers: { "Accept": "application/json" },
                body: data,
                credentials: "omit",
            }).then(function (resp) {
                return resp.json().then(function (json) { return { status: resp.status, json: json }; });
            }).then(function (r) {
                if (r.status === 422 && r.json && r.json.errors) {
                    Object.keys(r.json.errors).forEach(function (k) {
                        var ne = document.getElementById("pw-err-" + k);
                        if (ne) ne.textContent = r.json.errors[k][0];
                    });
                    btn.disabled = false;
                    btn.textContent = CONFIG.submit_button_text;
                    return;
                }
                if (r.status === 429) {
                    btn.textContent = "Try again later";
                    return;
                }
                if (r.json && r.json.redirect) {
                    window.location.href = r.json.redirect;
                    return;
                }
                var success = el("div", { class: "pw-success" }, [
                    r.json && r.json.message ? r.json.message : CONFIG.success_message,
                ]);
                form.parentNode.replaceChild(success, form);
            }).catch(function () {
                btn.disabled = false;
                btn.textContent = CONFIG.submit_button_text;
                var generic = document.getElementById("pw-err-" + (CONFIG.fields[0] && CONFIG.fields[0].field_key));
                if (generic) generic.textContent = "Submission failed. Please try again.";
            });
        });

        root.innerHTML = "";
        root.appendChild(form);
    }

    function init() {
        var root = document.getElementById(ROOT_ID);
        if (!root) return;
        render(root);
    }

    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", init);
    } else {
        init();
    }
})();
