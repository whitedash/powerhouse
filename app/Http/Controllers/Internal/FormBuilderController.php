<?php

namespace App\Http\Controllers\Internal;

use App\Enums\FieldWidth;
use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Form;
use App\Models\FormField;
use App\Models\FormStep;
use App\Models\FormSubmission;
use App\Models\FormTheme;
use App\Services\FormContentSanitizer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Internal CRUD for the form builder.
 *
 * Two surfaces:
 *   - Forms/Index.vue lists every form with embed snippet,
 *     webhook URL, and submission count.
 *   - Forms/Submissions.vue is per-form, showing raw payloads
 *     and the lead row they spawned (if any).
 *
 * The submit endpoint, embed.js endpoint, and webhook receiver
 * are all in App\Http\Controllers\Public — this controller
 * never serves a public visitor.
 */
class FormBuilderController extends Controller
{
    /**
     * Allowed field types — kept in sync with the form_fields
     * enum. The validator references this constant so a future
     * enum addition doesn't open a silent gap.
     */
    private const FIELD_TYPES = [
        'text', 'email', 'phone', 'textarea',
        'select', 'radio', 'checkbox', 'number',
        'date', 'datetime', 'hidden', 'placeholder',
    ];

    public function index(): Response
    {
        $forms = Form::query()
            ->with(['steps', 'fields', 'createdBy:id,name'])
            ->withCount('submissions')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (Form $f): array => $this->mapFormCard($f));

        return Inertia::render('Internal/Forms/Index', [
            'forms' => $forms,
            // Themes for the builder's theme selector. "Default"
            // (theme_id = null) is rendered client-side.
            'themes' => FormTheme::orderBy('name')->get(['id', 'name'])->all(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validatePayload($request);

        $form = DB::transaction(function () use ($data, $request): Form {
            $form = Form::create([
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'slug' => $data['slug'],
                'status' => $data['status'] ?? 'draft',
                'submit_button_text' => $data['submit_button_text'] ?? 'Submit',
                'success_message' => $data['success_message'] ?? null,
                'redirect_url' => $data['redirect_url'] ?? null,
                'gdpr_consent_enabled' => (bool) ($data['gdpr_consent_enabled'] ?? false),
                'gdpr_consent_text' => $data['gdpr_consent_text'] ?? null,
                'theme_id' => $data['theme_id'] ?? null,
                // 32 hex chars = 128 bits of entropy. The secret
                // is rotated by editing the form and saving with
                // a regenerate-on-save flag (future sprint);
                // for now it's set once at creation.
                'webhook_secret' => Str::random(32),
                'created_by' => $request->user()->id,
            ]);

            $this->buildStepsPayload($form, $data);

            $this->log($request, 'form.created', $form->id, after: [
                'name' => $form->name,
                'slug' => $form->slug,
                'fields' => $this->fieldCount($data),
            ]);

            return $form;
        });

        return back()->with('success', "Form \"{$form->name}\" created.");
    }

    public function update(int $id, Request $request): RedirectResponse
    {
        $form = Form::findOrFail($id);

        // Slug update is allowed but uniqueness ignores self.
        $data = $this->validatePayload($request, $form->id);

        DB::transaction(function () use ($form, $data, $request): void {
            $before = $form->only(['name', 'slug', 'status', 'gdpr_consent_enabled']);

            $form->update([
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'slug' => $data['slug'],
                'status' => $data['status'] ?? $form->status,
                'submit_button_text' => $data['submit_button_text'] ?? 'Submit',
                'success_message' => $data['success_message'] ?? null,
                'redirect_url' => $data['redirect_url'] ?? null,
                'gdpr_consent_enabled' => (bool) ($data['gdpr_consent_enabled'] ?? false),
                'gdpr_consent_text' => $data['gdpr_consent_text'] ?? null,
                'theme_id' => $data['theme_id'] ?? null,
            ]);

            // Step/field strategy: delete-and-recreate. Clear ALL fields first
            // — including any legacy fields with a null form_step_id that
            // predate multi-step (relying on the step-cascade alone would orphan
            // them) — then the steps, then rebuild from the payload. Fields don't
            // own state (submissions store raw key=value), so a wipe-and-add is
            // the simplest way to honour reorders, edits, and step moves.
            $form->fields()->delete();
            $form->steps()->delete();
            $this->buildStepsPayload($form, $data);

            $this->log($request, 'form.updated', $form->id, $before, [
                'name' => $form->name,
                'slug' => $form->slug,
                'status' => $form->status,
                'fields' => $this->fieldCount($data),
            ]);
        });

        return back()->with('success', 'Form updated.');
    }

    public function destroy(int $id, Request $request): RedirectResponse
    {
        $form = Form::findOrFail($id);

        // forms <- form_submissions is RESTRICT, so a form with
        // submissions can't be deleted. We surface that as a
        // friendly error rather than a 500.
        $hasSubmissions = FormSubmission::where('form_id', $form->id)->exists();
        if ($hasSubmissions) {
            return back()->withErrors([
                'form' => 'This form has submissions. Set its status to inactive instead of deleting.',
            ]);
        }

        DB::transaction(function () use ($form, $request): void {
            $snapshot = $form->only(['id', 'name', 'slug']);

            // form_fields cascade-deletes via FK.
            $form->delete();

            $this->log($request, 'form.deleted', $snapshot['id'], before: $snapshot);
        });

        return back()->with('success', 'Form deleted.');
    }

    /**
     * Per-form submissions list. The lead column is hydrated so
     * the table can link to the converted lead/customer.
     */
    public function submissions(int $id): Response
    {
        $form = Form::findOrFail($id);

        $submissions = FormSubmission::query()
            ->where('form_id', $form->id)
            ->with('lead:id,first_name,last_name,status,customer_id')
            ->orderByDesc('created_at')
            ->limit(200)
            ->get()
            ->map(fn (FormSubmission $s): array => [
                'id' => $s->id,
                'data' => $s->data,
                'status' => $s->status,
                'ip_address' => $s->ip_address,
                'referrer_url' => $s->referrer_url,
                'lead' => $s->lead === null ? null : [
                    'id' => $s->lead->id,
                    'name' => trim($s->lead->first_name.' '.($s->lead->last_name ?? '')),
                    'status' => $s->lead->status,
                    'customer_id' => $s->lead->customer_id,
                ],
                'created_at' => $s->created_at?->toIso8601String(),
            ]);

        return Inertia::render('Internal/Forms/Submissions', [
            'form' => [
                'id' => $form->id,
                'name' => $form->name,
                'slug' => $form->slug,
                'status' => $form->status,
            ],
            'submissions' => $submissions,
        ]);
    }

    /**
     * Shared validation between store + update. The exception is
     * the slug uniqueness rule, which ignores self on update.
     *
     * @return array<string, mixed>
     */
    private function validatePayload(Request $request, ?int $formId = null): array
    {
        $slugRule = ['required', 'string', 'max:100', 'regex:/^[a-z0-9-]+$/'];
        $slugRule[] = $formId === null
            ? 'unique:forms,slug'
            : Rule::unique('forms', 'slug')->ignore($formId);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'slug' => $slugRule,
            'status' => ['nullable', Rule::in(['active', 'inactive', 'draft'])],
            'submit_button_text' => ['nullable', 'string', 'max:100'],
            'success_message' => ['nullable', 'string', 'max:1000'],
            // redirect_url is operator-supplied and could point
            // anywhere — but it's NOT consumed by the server (the
            // browser just follows it), so SSRF doesn't apply.
            // We validate URL shape only.
            'redirect_url' => ['nullable', 'url:http,https', 'max:500'],
            'gdpr_consent_enabled' => ['nullable', 'boolean'],
            'gdpr_consent_text' => ['nullable', 'string', 'max:2000'],
            // Optional visual theme; null = default tokens (today's look).
            'theme_id' => ['nullable', 'integer', 'exists:form_themes,id'],

            // Multi-step: the real fields live nested inside steps. EVERY persisted
            // field key is ruled explicitly so validated() returns the COMPLETE
            // field — a partial child rule under steps.*.fields.* would trip
            // Laravel's excludeUnvalidatedArrayKeys and silently prune the un-ruled
            // siblings (the multi-step save bug). Ruling `type` with the allowlist
            // also yields a clean 422 here instead of a DB-enum 500. label and
            // field_key are nullable because display-only placeholder fields carry
            // a blank label + a server-synthesised key; the cross-validation pass
            // below still enforces "required unless placeholder" with step-scoped
            // messages. (Signed off in scripts/audit-validated-keys.allow.)
            'steps' => ['nullable', 'array'],
            'steps.*.label' => ['nullable', 'string', 'max:255'],
            'steps.*.fields' => ['nullable', 'array'],
            'steps.*.fields.*.label' => ['nullable', 'string', 'max:255'],
            'steps.*.fields.*.field_key' => ['nullable', 'string', 'max:100'],
            'steps.*.fields.*.type' => ['required', Rule::in(self::FIELD_TYPES)],
            'steps.*.fields.*.content' => ['nullable', 'string'],
            'steps.*.fields.*.placeholder' => ['nullable', 'string', 'max:255'],
            'steps.*.fields.*.default_value' => ['nullable', 'string', 'max:255'],
            'steps.*.fields.*.options' => ['nullable', 'array'],
            'steps.*.fields.*.options.*' => ['string', 'max:255'],
            'steps.*.fields.*.is_required' => ['nullable', 'boolean'],
            'steps.*.fields.*.width' => ['nullable', Rule::enum(FieldWidth::class)],
            'steps.*.fields.*.sort_order' => ['nullable', 'integer'],

            // Flat fields are the legacy single-step path (steps absent), still
            // posted by older tests; required-per-field there. content is ruled so
            // this path stays fully-ruled (no prune). The builder always posts
            // steps, so this branch is dead in production but kept for compat.
            'fields' => ['nullable', 'array', 'min:1'],
            'fields.*.label' => ['required', 'string', 'max:255'],
            'fields.*.field_key' => ['required', 'string', 'regex:/^[a-z][a-z0-9_]*$/', 'max:100'],
            'fields.*.type' => ['required', Rule::in(self::FIELD_TYPES)],
            'fields.*.content' => ['nullable', 'string'],
            'fields.*.placeholder' => ['nullable', 'string', 'max:255'],
            'fields.*.default_value' => ['nullable', 'string', 'max:255'],
            'fields.*.options' => ['nullable', 'array'],
            'fields.*.options.*' => ['string', 'max:255'],
            'fields.*.is_required' => ['nullable', 'boolean'],
            // Layout width; null/blank => full (today's single-column stack).
            'fields.*.width' => ['nullable', Rule::enum(FieldWidth::class)],
        ]);

        // Cross-validate the nested step fields with STEP-SCOPED error keys so
        // the builder can surface "Step 1: field 2 …" instead of an opaque flat
        // "fields.0.field_key". placeholder fields are display-only: their
        // field_key is synthesised server-side and their label (the display
        // text) may be left blank, so both rules are relaxed for them.
        $steps = $data['steps'] ?? [];
        if (is_array($steps) && $steps !== []) {
            $stepErrors = [];
            $totalFields = 0;

            foreach ($steps as $si => $stepData) {
                $stepData = is_array($stepData) ? $stepData : [];
                $stepLabel = (string) ($stepData['label'] ?? ('Step '.((int) $si + 1)));
                $fields = is_array($stepData['fields'] ?? null) ? $stepData['fields'] : [];
                $totalFields += count($fields);

                foreach ($fields as $fi => $field) {
                    $field = is_array($field) ? $field : [];
                    $type = $field['type'] ?? null;
                    $fieldLabel = (string) ($field['label'] ?? ('Field '.((int) $fi + 1)));
                    $key = (string) ($field['field_key'] ?? '');

                    if (empty($field['label']) && $type !== 'placeholder') {
                        $stepErrors["steps.{$si}.fields.{$fi}.label"] =
                            "\"{$stepLabel}\": field {$fieldLabel} is missing a label.";
                    }

                    if ($type !== 'placeholder'
                        && ($key === '' || ! preg_match('/^[a-z][a-z0-9_]*$/', $key))) {
                        $stepErrors["steps.{$si}.fields.{$fi}.field_key"] =
                            "\"{$stepLabel}\": \"{$fieldLabel}\" has an invalid key (lowercase letters, numbers, underscores only).";
                    }

                    if (! is_string($type) || ! in_array($type, self::FIELD_TYPES, true)) {
                        $stepErrors["steps.{$si}.fields.{$fi}.type"] =
                            "\"{$stepLabel}\": \"{$fieldLabel}\" has an invalid field type.";
                    }
                }
            }

            if ($stepErrors !== []) {
                throw ValidationException::withMessages($stepErrors);
            }

            if ($totalFields === 0) {
                throw ValidationException::withMessages([
                    'steps' => 'The form must have at least one field.',
                ]);
            }
        }

        return $data;
    }

    /**
     * Create the form's steps and their fields. Multi-step path when `steps` is
     * present; otherwise a single default "Step 1" wraps the legacy flat
     * `fields` array so every field always belongs to exactly one step.
     * sort_order is step-scoped (the index within each step's field list).
     *
     * @param  array<string, mixed>  $data
     */
    private function buildStepsPayload(Form $form, array $data): void
    {
        $steps = $data['steps'] ?? [];

        if (is_array($steps) && $steps !== []) {
            foreach ($steps as $stepIndex => $stepData) {
                $stepData = is_array($stepData) ? $stepData : [];
                $label = $stepData['label'] ?? null;

                $step = FormStep::create([
                    'form_id' => $form->id,
                    'label' => is_string($label) && $label !== '' ? $label : 'Step '.((int) $stepIndex + 1),
                    'sort_order' => (int) $stepIndex,
                ]);

                $fields = is_array($stepData['fields'] ?? null) ? $stepData['fields'] : [];
                foreach ($fields as $fieldIndex => $field) {
                    FormField::create($this->fieldRow($form, $step, (array) $field, (int) $fieldIndex));
                }
            }

            return;
        }

        // Legacy flat-fields path — one default step holds every field.
        $step = FormStep::create([
            'form_id' => $form->id,
            'label' => 'Step 1',
            'sort_order' => 0,
        ]);

        $fields = is_array($data['fields'] ?? null) ? $data['fields'] : [];
        foreach ($fields as $i => $field) {
            FormField::create($this->fieldRow($form, $step, (array) $field, (int) $i));
        }
    }

    /**
     * Build the FormField attribute array for one field on a step.
     *
     * @param  array<string, mixed>  $field
     * @return array<string, mixed>
     */
    private function fieldRow(Form $form, FormStep $step, array $field, int $sortOrder): array
    {
        $type = is_string($field['type'] ?? null) ? $field['type'] : 'text';
        $key = (string) ($field['field_key'] ?? '');

        // Placeholder fields carry no respondent input, so a posted key is
        // optional — synthesise a stable one (placeholder_{sort_order}) when the
        // frontend omits it, and never mark a display-only field required.
        if ($type === 'placeholder' && $key === '') {
            $key = 'placeholder_'.$sortOrder;
        }

        // Placeholder display text lives in `content` (TEXT), sanitised to a
        // safe HTML subset; `label` is emptied for placeholders. Other types
        // keep their label and carry no content.
        $isPlaceholder = $type === 'placeholder';
        $content = null;
        if ($isPlaceholder) {
            $raw = is_string($field['content'] ?? null) ? $field['content'] : '';
            // Server-side allow-list sanitisation (symfony/html-sanitizer): the
            // STORED body is always clean regardless of the client. The DOMPurify
            // in the builder/renderer is convenience, not the security boundary.
            $content = app(FormContentSanitizer::class)->sanitize($raw);
        }

        return [
            'form_id' => $form->id,
            'form_step_id' => $step->id,
            'label' => $isPlaceholder ? '' : ($field['label'] ?? null),
            'content' => $content,
            'field_key' => $key,
            'type' => $type,
            'placeholder' => $field['placeholder'] ?? null,
            'default_value' => $field['default_value'] ?? null,
            'options' => $field['options'] ?? null,
            'is_required' => $type !== 'placeholder' && (bool) ($field['is_required'] ?? false),
            'width' => $field['width'] ?? FieldWidth::Full->value,
            'sort_order' => $sortOrder,
        ];
    }

    /**
     * Count total fields across either the nested steps payload or the flat
     * fields array (for the activity-log entry, which is otherwise unchanged).
     *
     * @param  array<string, mixed>  $data
     */
    private function fieldCount(array $data): int
    {
        $steps = $data['steps'] ?? [];

        if (is_array($steps) && $steps !== []) {
            $count = 0;
            foreach ($steps as $step) {
                $stepFields = is_array($step) && is_array($step['fields'] ?? null) ? $step['fields'] : [];
                $count += count($stepFields);
            }

            return $count;
        }

        $fields = is_array($data['fields'] ?? null) ? $data['fields'] : [];

        return count($fields);
    }

    /**
     * Map a single FormField to the builder's field shape. Shared between the
     * flat `fields` list and each step's nested `fields`.
     *
     * @return array<string, mixed>
     */
    private function mapField(FormField $field): array
    {
        return [
            'id' => $field->id,
            'label' => $field->label,
            'content' => $field->content,
            'field_key' => $field->field_key,
            'type' => $field->type,
            'placeholder' => $field->placeholder,
            'default_value' => $field->default_value,
            'options' => $field->options,
            'is_required' => $field->is_required,
            'width' => $field->width->value,
            'sort_order' => $field->sort_order,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function mapFormCard(Form $f): array
    {
        return [
            'id' => $f->id,
            'name' => $f->name,
            'description' => $f->description,
            'slug' => $f->slug,
            'status' => $f->status,
            'submit_button_text' => $f->submit_button_text,
            'success_message' => $f->success_message,
            'redirect_url' => $f->redirect_url,
            'gdpr_consent_enabled' => $f->gdpr_consent_enabled,
            'gdpr_consent_text' => $f->gdpr_consent_text,
            'theme_id' => $f->theme_id,
            'fields' => $f->fields->map(fn (FormField $field): array => $this->mapField($field))->values(),
            // Nested step shape the builder slide-over reconstructs editor.steps
            // from. Steps ordered by sort_order; each carries only its own fields
            // (filtered by form_step_id), also sort_order-ordered.
            'steps' => $f->steps->sortBy('sort_order')->values()->map(fn (FormStep $step): array => [
                'id' => $step->id,
                'label' => $step->label,
                'sort_order' => $step->sort_order,
                'fields' => $f->fields
                    ->where('form_step_id', $step->id)
                    ->sortBy('sort_order')
                    ->values()
                    ->map(fn (FormField $field): array => $this->mapField($field)),
            ]),
            'fields_count' => $f->fields->count(),
            'submissions_count' => (int) ($f->submissions_count ?? 0),
            'submission_count' => $f->submission_count,
            'embed_url' => $f->embed_url,
            'embed_snippet' => $this->buildEmbedSnippet($f),
            'webhook_url' => $f->webhook_url,
            'webhook_secret' => $f->webhook_secret,
            'created_at' => $f->created_at?->toIso8601String(),
            'created_by' => $f->createdBy->name,
        ];
    }

    private function buildEmbedSnippet(Form $form): string
    {
        return "<div id=\"pw-form-{$form->slug}\"></div>\n"
            .'<script src="'.$form->embed_url.'" async></script>';
    }

    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    private function log(Request $request, string $action, int $entityId, ?array $before = null, ?array $after = null): void
    {
        ActivityLog::create([
            'user_id' => $request->user()->id,
            'user_role' => $request->user()->role,
            'action' => $action,
            'entity_type' => 'form',
            'entity_id' => $entityId,
            'before' => $before,
            'after' => $after,
            'ip_address' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 500),
        ]);
    }
}
