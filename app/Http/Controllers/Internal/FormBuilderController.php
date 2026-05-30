<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Form;
use App\Models\FormField;
use App\Models\FormSubmission;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
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
        'date', 'hidden',
    ];

    public function index(): Response
    {
        $forms = Form::query()
            ->with(['fields', 'createdBy:id,name'])
            ->withCount('submissions')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (Form $f): array => $this->mapFormCard($f));

        return Inertia::render('Internal/Forms/Index', [
            'forms' => $forms,
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
                // 32 hex chars = 128 bits of entropy. The secret
                // is rotated by editing the form and saving with
                // a regenerate-on-save flag (future sprint);
                // for now it's set once at creation.
                'webhook_secret' => Str::random(32),
                'created_by' => $request->user()->id,
            ]);

            foreach ($data['fields'] as $i => $field) {
                FormField::create([
                    'form_id' => $form->id,
                    'label' => $field['label'],
                    'field_key' => $field['field_key'],
                    'type' => $field['type'],
                    'placeholder' => $field['placeholder'] ?? null,
                    'default_value' => $field['default_value'] ?? null,
                    'options' => $field['options'] ?? null,
                    'is_required' => (bool) ($field['is_required'] ?? false),
                    'sort_order' => $i,
                ]);
            }

            $this->log($request, 'form.created', $form->id, after: [
                'name' => $form->name,
                'slug' => $form->slug,
                'fields' => count($data['fields']),
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
            ]);

            // Field strategy: delete-and-recreate. Fields don't
            // own state (no submission references the field row,
            // submissions store raw key=value) so a wipe-and-add
            // is the simplest way to honour reorders + edits.
            $form->fields()->delete();
            foreach ($data['fields'] as $i => $field) {
                FormField::create([
                    'form_id' => $form->id,
                    'label' => $field['label'],
                    'field_key' => $field['field_key'],
                    'type' => $field['type'],
                    'placeholder' => $field['placeholder'] ?? null,
                    'default_value' => $field['default_value'] ?? null,
                    'options' => $field['options'] ?? null,
                    'is_required' => (bool) ($field['is_required'] ?? false),
                    'sort_order' => $i,
                ]);
            }

            $this->log($request, 'form.updated', $form->id, $before, [
                'name' => $form->name,
                'slug' => $form->slug,
                'status' => $form->status,
                'fields' => count($data['fields']),
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

        return $request->validate([
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

            'fields' => ['required', 'array', 'min:1'],
            'fields.*.label' => ['required', 'string', 'max:255'],
            'fields.*.field_key' => ['required', 'string', 'regex:/^[a-z][a-z0-9_]*$/', 'max:100'],
            'fields.*.type' => ['required', Rule::in(self::FIELD_TYPES)],
            'fields.*.placeholder' => ['nullable', 'string', 'max:255'],
            'fields.*.default_value' => ['nullable', 'string', 'max:255'],
            'fields.*.options' => ['nullable', 'array'],
            'fields.*.options.*' => ['string', 'max:255'],
            'fields.*.is_required' => ['nullable', 'boolean'],
        ]);
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
            'fields' => $f->fields->map(fn (FormField $field): array => [
                'id' => $field->id,
                'label' => $field->label,
                'field_key' => $field->field_key,
                'type' => $field->type,
                'placeholder' => $field->placeholder,
                'default_value' => $field->default_value,
                'options' => $field->options,
                'is_required' => $field->is_required,
                'sort_order' => $field->sort_order,
            ])->values(),
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
