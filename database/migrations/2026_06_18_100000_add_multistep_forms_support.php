<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Multi-step forms support.
 *
 * Three changes, one migration:
 *
 *   1. form_steps — ordered, labelled steps belonging to a form. The label
 *      drives the respondent progress indicator; sort_order orders the steps.
 *
 *   2. form_fields.form_step_id — assigns each field to a step (nullable so
 *      pre-existing single-step forms keep working with NULL = "no explicit
 *      step"). sort_order becomes STEP-scoped: ordering within a step, not the
 *      whole form. The old (form_id, sort_order) index is replaced by the
 *      composite (form_id, form_step_id, sort_order) so step-scoped reads are
 *      covered.
 *
 *   3. form_submission_drafts — per-step draft persistence + resume. A draft
 *      accumulates `data` across steps and is promoted to a form_submissions
 *      row (status='new') on final submit, after which the draft row is
 *      deleted. draft_token is the anonymous (localStorage-keyed) resume
 *      credential; portal_user_id keys authenticated Portal resume.
 *
 * Index-swap ordering note: the existing form_fields_form_id_sort_order_index
 * is the only index whose left-most column is form_id, so it currently backs
 * the form_fields_form_id_foreign FK. MySQL refuses to drop an index still
 * needed by a FK, so the new composite (also form_id-leftmost) is created
 * BEFORE the old one is dropped — and in down() the old index is restored
 * BEFORE the composite is dropped. End state matches the spec exactly; only
 * the intra-step ordering is FK-safe.
 */
return new class() extends Migration
{
    public function up(): void
    {
        Schema::create('form_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('form_id')->constrained('forms')->cascadeOnDelete();
            $table->string('label');
            $table->integer('sort_order')->default(0);
            $table->timestamps();
            $table->index(['form_id', 'sort_order'], 'form_steps_form_id_sort_order_index');
        });

        Schema::table('form_fields', function (Blueprint $table) {
            $table->unsignedBigInteger('form_step_id')->nullable()->after('form_id');
            $table->foreign('form_step_id')
                ->references('id')->on('form_steps')->cascadeOnDelete();

            // Create the replacement (form_id-leftmost) index BEFORE dropping
            // the old one, so the form_id FK is never left without a backing
            // index mid-migration.
            $table->index(['form_id', 'form_step_id', 'sort_order'], 'form_fields_form_id_step_sort_index');
            $table->dropIndex('form_fields_form_id_sort_order_index');
        });

        Schema::create('form_submission_drafts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('form_id')->constrained('forms')->cascadeOnDelete();

            // Always generated (anonymous or authenticated) — the resume
            // credential carried in the embed widget's localStorage.
            $table->string('draft_token', 64)->unique();

            // Authenticated Portal respondent (nullable = anonymous). The
            // ->constrained() call adds the single-column FK backing index.
            $table->foreignId('portal_user_id')->nullable()
                ->constrained('portal_users')->cascadeOnDelete();

            // Last completed step (0 = not started, 1 = step 1 done, …).
            $table->integer('current_step')->default(0);

            // Partial answers accumulated across steps, keyed by field_key.
            // Named `data` for symmetry with form_submissions.data so promotion
            // is a straight copy.
            $table->json('data')->nullable();

            // Abuse/audit parity with form_submissions.
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->string('referrer_url', 500)->nullable();

            // Draft TTL. Anonymous drafts expire; authenticated drafts may
            // leave this null.
            $table->timestamp('expires_at')->nullable();

            $table->timestamps();

            $table->index(['form_id', 'portal_user_id'], 'form_submission_drafts_form_portal_index');
            $table->index('expires_at', 'form_submission_drafts_expires_at_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('form_submission_drafts');

        Schema::table('form_fields', function (Blueprint $table) {
            // Drop the FK first so the column can be removed; this also drops
            // the FK's backing index on form_step_id.
            $table->dropForeign('form_fields_form_step_id_foreign');

            // Restore the original (form_id, sort_order) index BEFORE dropping
            // the composite, so the form_id FK keeps a backing index throughout.
            $table->index(['form_id', 'sort_order'], 'form_fields_form_id_sort_order_index');
            $table->dropIndex('form_fields_form_id_step_sort_index');

            $table->dropColumn('form_step_id');
        });

        Schema::dropIfExists('form_steps');
    }
};
