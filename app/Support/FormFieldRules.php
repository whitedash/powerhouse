<?php

namespace App\Support;

use App\Models\FormField;

/**
 * Single source of truth for a form's per-field validation rules, keyed by
 * field_key. Previously this logic was duplicated inline in three submission
 * paths (Public/FormController::submit, Public/FormDraftController::submit,
 * Portal/FormController::submit); collapsing it here so the per-step "Next"
 * gate and the final-submit validation can never drift apart.
 *
 * The caller decides the field set: the per-step gate passes one step's fields,
 * final-submit validation passes all of them — same builder either way.
 *
 * Behaviour is preserved exactly from the previous inline builders:
 *  - placeholder fields are display-only — skipped entirely (no rule);
 *  - every other field gets `required` when is_required, else `nullable`;
 *  - email adds `email:rfc`; number adds `numeric`;
 *  - date AND datetime add `date` (datetime is validated as a plain date today —
 *    intentionally unchanged here);
 *  - text/phone/textarea/select/radio/checkbox/hidden carry only
 *    required/nullable (values are NOT constrained to options).
 */
class FormFieldRules
{
    /**
     * @param  iterable<FormField>  $fields  the whole form's fields, or any subset
     * @return array<string, string> field_key => pipe-joined rule string
     */
    public static function for(iterable $fields): array
    {
        $rules = [];

        foreach ($fields as $field) {
            // Placeholder fields are display-only — no input, no rule.
            if ($field->type === 'placeholder') {
                continue;
            }

            $chain = [$field->is_required ? 'required' : 'nullable'];

            if ($field->type === 'email') {
                $chain[] = 'email:rfc';
            }
            if ($field->type === 'number') {
                $chain[] = 'numeric';
            }
            if ($field->type === 'date' || $field->type === 'datetime') {
                $chain[] = 'date';
            }

            $rules[$field->field_key] = implode('|', $chain);
        }

        return $rules;
    }
}
