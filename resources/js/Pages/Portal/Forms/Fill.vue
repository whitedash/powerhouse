<script setup>
/**
 * Authenticated Portal respondent page for a multi-step form. Drafts resume by
 * portal_user_id (no token) — the controller seeds the `draft` prop. Draft ops
 * hit JSON endpoints, so they use native fetch + the X-CSRF-TOKEN meta header
 * (NOT Inertia useForm, which expects an Inertia response).
 *
 * Follows the Portal conventions (PortalLayout #desktop/#mobile slots, .wd card
 * family, ti webfont icons) rather than the design's hub-shell mockup.
 */
import { ref, computed, onMounted } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import PortalLayout from '@/Layouts/PortalLayout.vue';
import FormFieldRenderer from '@/Components/Forms/FormFieldRenderer.vue';

const props = defineProps({
    form: { type: Object, required: true },   // { id, name, steps[], fields[], submit_button_text, success_message, redirect_url }
    draft: { type: Object, default: null },   // null | { current_step, data }
});

const currentStep = ref(props.draft?.current_step ?? 0);
const answers = ref({ ...(props.draft?.data ?? {}) });
const saving = ref(false);
const submitting = ref(false);
const saveMessage = ref('');
const errors = ref({});

const steps = computed(() => props.form.steps ?? []);
const totalSteps = computed(() => steps.value.length);
const activeStep = computed(() => steps.value[currentStep.value]);
const activeFields = computed(() =>
    props.form.fields.filter(f => f.form_step_id === activeStep.value?.id),
);
const isLastStep = computed(() => currentStep.value === totalSteps.value - 1);

const csrfToken = () => document.querySelector('meta[name="csrf-token"]')?.content ?? '';

function draftHeaders() {
    return {
        'Content-Type': 'application/json',
        Accept: 'application/json',
        'X-CSRF-TOKEN': csrfToken(),
    };
}

onMounted(() => {
    // The controller resumes by portal_user_id; only initialise a fresh draft
    // when none was seeded into props.
    if (props.draft === null) initDraft();
});

async function initDraft() {
    await fetch(`/portal/forms/${props.form.id}/draft`, {
        method: 'POST',
        headers: draftHeaders(),
        credentials: 'same-origin',
    });
}

async function persistStep(advance = false) {
    const body = { step: currentStep.value + 1, answers: answers.value };
    // Only the forward advance (Next) opts into the server's per-step gate;
    // save-for-later and the final-step persist stay unvalidated (the whole-form
    // check runs at submit). Backward-compatible — the flag is absent otherwise.
    if (advance) body.advance = true;
    const res = await fetch(`/portal/forms/${props.form.id}/draft`, {
        method: 'PUT',
        headers: draftHeaders(),
        credentials: 'same-origin',
        body: JSON.stringify(body),
    });
    return res;
}

async function nextStep() {
    saving.value = true;
    errors.value = {};
    try {
        const res = await persistStep(true);
        // A 422 carries field-keyed errors for the departing step; surface them
        // on the fields and stay put (local answers are untouched, so input is
        // preserved). The advance happens only on an ok response.
        if (!res.ok) {
            const d = await res.json().catch(() => ({}));
            errors.value = d.errors ?? {};
            return;
        }
        currentStep.value++;
    } finally {
        saving.value = false;
    }
}

function prevStep() {
    // No save needed going backward — answers are already in local state.
    if (currentStep.value > 0) currentStep.value--;
}

async function saveLater() {
    saving.value = true;
    try {
        await persistStep();
        saveMessage.value = 'Progress saved.';
        setTimeout(() => { router.visit('/portal/dashboard'); }, 1500);
    } finally {
        saving.value = false;
    }
}

async function submitForm() {
    submitting.value = true;
    errors.value = {};
    try {
        // Persist the final step's answers before promoting the draft.
        const saveRes = await persistStep();
        if (!saveRes.ok) {
            const sd = await saveRes.json().catch(() => ({}));
            errors.value = sd.errors ?? {};
            return;
        }
        const res = await fetch(`/portal/forms/${props.form.id}/draft/submit`, {
            method: 'POST',
            headers: draftHeaders(),
            credentials: 'same-origin',
        });
        const data = await res.json().catch(() => ({}));
        if (!res.ok) {
            errors.value = data.errors ?? {};
            return;
        }
        if (data.redirect) {
            window.location.href = data.redirect;
            return;
        }
        router.visit('/portal/dashboard');
    } finally {
        submitting.value = false;
    }
}
</script>

<template>
    <Head :title="form.name" />
    <PortalLayout active-nav="forms" :mobile-tabbar="false">
        <!-- ═══════════ DESKTOP ═══════════ -->
        <template #desktop>
            <div class="content narrow">
                <div class="page-head">
                    <div class="ph-l">
                        <h1>{{ form.name }}</h1>
                        <div class="ph-sub">Tell us about your project. You can save and finish later.</div>
                    </div>
                </div>

                <!-- Stepper -->
                <div v-if="totalSteps > 1" class="ms-stepper">
                    <div
                        v-for="(step, i) in steps"
                        :key="step.id"
                        class="ms-step"
                        :class="i < currentStep ? 'ms-step-done' : i === currentStep ? 'ms-step-active' : ''"
                    >
                        <div class="ms-step-num">
                            <i v-if="i < currentStep" class="ti ti-check" />
                            <span v-else>{{ i + 1 }}</span>
                        </div>
                        <span class="ms-step-label">{{ step.label }}</span>
                    </div>
                </div>

                <!-- Form card -->
                <section class="card">
                    <header class="card-head">
                        <div>
                            <h3>{{ activeStep?.label || form.name }}</h3>
                            <div v-if="totalSteps > 1" class="sub">Step {{ currentStep + 1 }} of {{ totalSteps }}</div>
                        </div>
                        <span v-if="totalSteps > 1" class="badge badge-open">In progress</span>
                    </header>
                    <div class="card-pad">
                        <div class="ffr-grid">
                            <FormFieldRenderer
                                v-for="field in activeFields"
                                :key="field.field_key"
                                :field="field"
                                :error="errors[field.field_key]"
                                v-model="answers[field.field_key]"
                            />
                        </div>
                        <p v-if="saveMessage" class="ms-save-message">{{ saveMessage }}</p>
                    </div>
                    <footer class="ms-form-footer">
                        <div class="ms-footer-left">
                            <button
                                v-if="!isLastStep"
                                type="button"
                                class="ms-text-link"
                                :disabled="saving"
                                @click="saveLater"
                            >
                                <i class="ti ti-device-floppy" /> Save and continue later
                            </button>
                        </div>
                        <div class="ms-footer-right">
                            <button
                                v-if="currentStep > 0"
                                type="button"
                                class="btn btn-secondary"
                                :disabled="saving || submitting"
                                @click="prevStep"
                            >
                                <i class="ti ti-arrow-left" /> Back
                            </button>
                            <button
                                v-if="!isLastStep"
                                type="button"
                                class="btn btn-primary"
                                :disabled="saving"
                                @click="nextStep"
                            >
                                {{ saving ? 'Saving…' : 'Next' }} <i class="ti ti-arrow-right" />
                            </button>
                            <button
                                v-else
                                type="button"
                                class="btn btn-primary"
                                :disabled="submitting"
                                @click="submitForm"
                            >
                                {{ submitting ? 'Submitting…' : (form.submit_button_text ?? 'Submit') }}
                            </button>
                        </div>
                    </footer>
                </section>
            </div>
        </template>

        <!-- ═══════════ MOBILE ═══════════ -->
        <template #mobile>
            <div class="m-appbar">
                <div class="title" style="font-size:16px">{{ form.name }}</div>
            </div>
            <div class="m-body" style="padding:16px;gap:16px">
                <div v-if="totalSteps > 1" class="ms-stepper">
                    <div
                        v-for="(step, i) in steps"
                        :key="step.id"
                        class="ms-step"
                        :class="i < currentStep ? 'ms-step-done' : i === currentStep ? 'ms-step-active' : ''"
                    >
                        <div class="ms-step-num">
                            <i v-if="i < currentStep" class="ti ti-check" />
                            <span v-else>{{ i + 1 }}</span>
                        </div>
                        <span class="ms-step-label">{{ step.label }}</span>
                    </div>
                </div>

                <section class="card">
                    <header class="card-head">
                        <div>
                            <h3>{{ activeStep?.label || form.name }}</h3>
                            <div v-if="totalSteps > 1" class="sub">Step {{ currentStep + 1 }} of {{ totalSteps }}</div>
                        </div>
                    </header>
                    <div class="card-pad">
                        <div class="ffr-grid">
                            <FormFieldRenderer
                                v-for="field in activeFields"
                                :key="field.field_key"
                                :field="field"
                                :error="errors[field.field_key]"
                                v-model="answers[field.field_key]"
                            />
                        </div>
                        <p v-if="saveMessage" class="ms-save-message">{{ saveMessage }}</p>
                    </div>
                    <footer class="ms-form-footer ms-form-footer-stack">
                        <div class="ms-footer-right">
                            <button v-if="currentStep > 0" type="button" class="btn btn-secondary" :disabled="saving || submitting" @click="prevStep">
                                <i class="ti ti-arrow-left" /> Back
                            </button>
                            <button v-if="!isLastStep" type="button" class="btn btn-primary btn-block" :disabled="saving" @click="nextStep">
                                {{ saving ? 'Saving…' : 'Next' }} <i class="ti ti-arrow-right" />
                            </button>
                            <button v-else type="button" class="btn btn-primary btn-block" :disabled="submitting" @click="submitForm">
                                {{ submitting ? 'Submitting…' : (form.submit_button_text ?? 'Submit') }}
                            </button>
                        </div>
                        <button v-if="!isLastStep" type="button" class="ms-text-link" :disabled="saving" @click="saveLater">
                            <i class="ti ti-device-floppy" /> Save and continue later
                        </button>
                    </footer>
                </section>
            </div>
        </template>
    </PortalLayout>
</template>

<style scoped>
/* Numbered stepper (design GAP G2) — token-only. */
.ms-stepper { display: flex; align-items: center; gap: 18px; flex-wrap: wrap; margin-bottom: 20px; }
.ms-step { display: flex; align-items: center; gap: 10px; }
.ms-step-num { width: 28px; height: 28px; border-radius: 50%; display: grid; place-items: center; font: 600 13px/1 'Inter', sans-serif; background: var(--neutral-bg); color: var(--text-secondary); border: 1px solid var(--border); flex-shrink: 0; }
.ms-step-num .ti { font-size: 15px; }
.ms-step.ms-step-done .ms-step-num { background: var(--accent); color: var(--bg-navy); border-color: var(--accent); }
.ms-step.ms-step-active .ms-step-num { background: #fff; color: var(--accent); border-color: var(--accent); box-shadow: 0 0 0 3px color-mix(in srgb, var(--accent) 18%, transparent); }
.ms-step-label { font: 500 13px/1.2 'Inter', sans-serif; color: var(--text-secondary); white-space: nowrap; }
.ms-step.ms-step-active .ms-step-label { color: var(--text-primary); font-weight: 600; }

.ffr-grid { display: grid; grid-template-columns: repeat(12, 1fr); gap: 0 16px; }

.ms-form-footer { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 16px 24px; border-top: 1px solid var(--border-soft); }
.ms-form-footer-stack { flex-direction: column-reverse; align-items: stretch; gap: 12px; }
.ms-footer-right { display: flex; align-items: center; gap: 10px; }

.ms-text-link { background: transparent; border: 0; cursor: pointer; font: 400 13px/1.4 'Inter', sans-serif; color: var(--text-secondary); display: inline-flex; align-items: center; gap: 6px; }
.ms-text-link:hover:not(:disabled) { color: var(--accent); }
.ms-text-link:disabled { opacity: .5; cursor: default; }
.ms-text-link .ti { font-size: 15px; }

.ms-save-message { margin: 14px 0 0; font: 500 13px/1.4 'Inter', sans-serif; color: var(--success); }
</style>
