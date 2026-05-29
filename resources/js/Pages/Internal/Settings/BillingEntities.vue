<script setup>
import { computed, ref, watch } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import {
    Dialog,
    DialogPanel,
    TransitionChild,
    TransitionRoot,
} from '@headlessui/vue';
import {
    IconPlus,
    IconDeviceFloppy,
    IconLock,
    IconUpload,
    IconPlugConnected,
    IconX,
    IconAlertCircle,
    IconCheck,
    IconTrash,
} from '@tabler/icons-vue';
import InternalLayout from '@/Layouts/InternalLayout.vue';

const props = defineProps({
    entities: { type: Array, required: true },
    selected_id: { type: Number, default: null },
});

const breadcrumbs = [
    { label: 'Settings', href: '/settings' },
    { label: 'Billing entities' },
];

/* ─── Selection ─── */
const selectedId = ref(props.selected_id ?? props.entities[0]?.id ?? null);
const selectedEntity = computed(() =>
    props.entities.find((e) => e.id === selectedId.value) ?? null,
);

const activeCount = computed(() => props.entities.filter((e) => e.is_active).length);

/* ─── Brand-mark colour by name ─── */
const PALETTE = ['maa', 'kon', 'teal', 'purple'];
function markClass(entity) {
    if (!entity) return 'maa';
    const i = props.entities.findIndex((e) => e.id === entity.id);

    return PALETTE[(i < 0 ? 0 : i) % PALETTE.length];
}
function initial(name) {
    return (name?.[0] ?? '?').toUpperCase();
}

/* ─── Address helpers ─── */
function addressShape(addr) {
    return {
        line1: addr?.line1 ?? addr?.address_line1 ?? '',
        line2: addr?.line2 ?? addr?.address_line2 ?? '',
        city: addr?.city ?? '',
        postcode: addr?.postcode ?? '',
        country: addr?.country ?? 'GB',
    };
}

/* ─── Detail form ─── */
function buildDefaults(entity) {
    const addr = addressShape(entity?.address ?? {});

    return {
        name: entity?.name ?? '',
        legal_name: entity?.legal_name ?? '',
        company_number: entity?.company_number ?? '',
        vat_number: entity?.vat_number ?? '',
        address_line1: addr.line1,
        address_line2: addr.line2,
        city: addr.city,
        postcode: addr.postcode,
        country: addr.country,
        bank_name: entity?.bank_name ?? '',
        sort_code: entity?.sort_code ?? '',
        account_number: entity?.account_number ?? '',
        account_name: entity?.account_name ?? '',
        postmark_sender_email: entity?.postmark_sender_email ?? '',
        postmark_sender_name: entity?.postmark_sender_name ?? '',
        postmark_domain: entity?.postmark_domain ?? '',
        is_active: entity?.is_active ?? true,
    };
}

const form = useForm(buildDefaults(selectedEntity.value));

watch(selectedEntity, (next) => {
    form.defaults(buildDefaults(next));
    form.reset();
    form.clearErrors();
});

function selectEntity(id) {
    if (id === selectedId.value) return;
    if (form.isDirty && !confirm('Discard unsaved changes?')) return;
    selectedId.value = id;
    router.get(
        '/settings/billing-entities',
        { entity: id },
        { preserveState: true, preserveScroll: true, replace: true, only: [] },
    );
}

function saveDetail() {
    if (!selectedEntity.value) return;
    form.put(`/settings/billing-entities/${selectedEntity.value.id}`, {
        preserveScroll: true,
    });
}

function discardChanges() {
    form.reset();
    form.clearErrors();
}

function deleteEntity() {
    if (!selectedEntity.value) return;
    const name = selectedEntity.value.name;
    if (!confirm(`Delete ${name}? This cannot be undone.`)) return;
    router.delete(`/settings/billing-entities/${selectedEntity.value.id}`, {
        preserveScroll: true,
    });
}

/* ─── Slide-over (create) ─── */
const showCreate = ref(false);
const createForm = useForm(buildDefaults(null));

function openCreate() {
    createForm.reset();
    createForm.clearErrors();
    showCreate.value = true;
}

function submitCreate() {
    createForm.post('/settings/billing-entities', {
        onSuccess: () => {
            showCreate.value = false;
        },
        preserveScroll: true,
    });
}

/* ─── QBO state (placeholder; controller exposes qbo_realm_id) ─── */
const qboConnected = computed(() => !!selectedEntity.value?.qbo_realm_id);
</script>

<template>
    <Head title="Billing entities" />

    <InternalLayout title="Billing entities" :breadcrumbs="breadcrumbs" active-nav="settings">
        <template #topbar-actions>
            <button type="button" class="btn btn-primary" @click="openCreate">
                <IconPlus :size="15" stroke-width="1.75" />
                Add entity
            </button>
        </template>

        <!-- Flash banners -->
        <div
            v-if="$page.props.flash?.success"
            style="margin-bottom: 12px; padding: 10px 14px; background: var(--success-bg); color: #047857; border: 1px solid #A7F3D0; border-radius: var(--radius-md); font: 500 13px/1.4 'Inter', sans-serif; display: flex; align-items: center; gap: 8px;"
        >
            <IconCheck :size="16" stroke-width="2" />
            {{ $page.props.flash.success }}
        </div>
        <div
            v-if="$page.props.flash?.error"
            style="margin-bottom: 12px; padding: 10px 14px; background: var(--danger-bg); color: var(--danger); border: 1px solid #FECACA; border-radius: var(--radius-md); font: 500 13px/1.4 'Inter', sans-serif; display: flex; align-items: center; gap: 8px;"
        >
            <IconAlertCircle :size="16" stroke-width="2" />
            {{ $page.props.flash.error }}
        </div>

        <div class="billing-entities">
            <!-- ═══════════ LEFT — Entity list ═══════════ -->
            <aside class="ent-list-card">
                <div class="ent-list-head">
                    <span class="title">Entities</span>
                    <span class="badge badge-active badge-sm right">{{ activeCount }} active</span>
                </div>

                <div
                    v-for="e in entities"
                    :key="e.id"
                    class="ent-row"
                    :class="{ selected: e.id === selectedId }"
                    @click="selectEntity(e.id)"
                >
                    <div class="top">
                        <div class="ent-mark" :class="markClass(e)">{{ initial(e.name) }}</div>
                        <div>
                            <div class="ent-name">{{ e.name }}</div>
                            <div v-if="e.company_number" class="ent-co">Company No. {{ e.company_number }}</div>
                        </div>
                        <div class="right">
                            <span
                                class="badge badge-sm"
                                :class="e.is_active ? 'badge-active' : 'badge-inactive'"
                            >{{ e.is_active ? 'Active' : 'Inactive' }}</span>
                        </div>
                    </div>
                    <div class="ent-desc">
                        {{ e.invoice_count }} {{ e.invoice_count === 1 ? 'invoice' : 'invoices' }}
                    </div>
                </div>

                <div class="ent-add-divider" />
                <button type="button" class="ent-add" @click="openCreate">
                    <IconPlus :size="16" stroke-width="1.75" />
                    Add new billing entity
                </button>
            </aside>

            <!-- ═══════════ RIGHT — Detail form ═══════════ -->
            <section v-if="selectedEntity" class="form-card">
                <div class="form-head">
                    <div class="ent-mark lg" :class="markClass(selectedEntity)">{{ initial(selectedEntity.name) }}</div>
                    <div class="form-title">{{ selectedEntity.name }}</div>
                    <span
                        class="badge"
                        :class="selectedEntity.is_active ? 'badge-active' : 'badge-inactive'"
                    >{{ selectedEntity.is_active ? 'Active' : 'Inactive' }}</span>
                    <div class="right">
                        <button
                            type="button"
                            class="btn btn-primary"
                            :class="{ disabled: !form.isDirty }"
                            :disabled="!form.isDirty || form.processing"
                            @click="saveDetail"
                        >
                            <IconDeviceFloppy :size="15" stroke-width="1.75" />
                            {{ form.processing ? 'Saving…' : 'Save changes' }}
                        </button>
                    </div>
                </div>

                <form class="form-body" @submit.prevent="saveDetail">
                    <!-- LEGAL -->
                    <div class="sec-label">Legal details</div>

                    <div class="form-grid-2">
                        <div class="field">
                            <label class="field-label" for="legal_name">Legal name</label>
                            <input
                                id="legal_name"
                                v-model="form.legal_name"
                                class="field-input"
                                :class="{ 'has-err': form.errors.legal_name }"
                                type="text"
                            >
                            <div v-if="form.errors.legal_name" class="field-err">{{ form.errors.legal_name }}</div>
                        </div>
                        <div class="field">
                            <label class="field-label" for="name">Trading name</label>
                            <input
                                id="name"
                                v-model="form.name"
                                class="field-input"
                                :class="{ 'has-err': form.errors.name }"
                                type="text"
                            >
                            <div v-if="form.errors.name" class="field-err">{{ form.errors.name }}</div>
                        </div>
                        <div class="field">
                            <label class="field-label" for="company_number">Company number</label>
                            <input
                                id="company_number"
                                v-model="form.company_number"
                                class="field-input mono"
                                :class="{ 'has-err': form.errors.company_number }"
                                type="text"
                            >
                            <div v-if="form.errors.company_number" class="field-err">{{ form.errors.company_number }}</div>
                        </div>
                        <div class="field">
                            <label class="field-label" for="vat_number">VAT number</label>
                            <input
                                id="vat_number"
                                v-model="form.vat_number"
                                class="field-input mono"
                                :class="{ 'has-err': form.errors.vat_number }"
                                type="text"
                            >
                            <div v-if="form.errors.vat_number" class="field-err">{{ form.errors.vat_number }}</div>
                        </div>
                        <div class="field full">
                            <label class="field-label" for="address_line1">Address line 1</label>
                            <input
                                id="address_line1"
                                v-model="form.address_line1"
                                class="field-input"
                                :class="{ 'has-err': form.errors.address_line1 }"
                                type="text"
                            >
                            <div v-if="form.errors.address_line1" class="field-err">{{ form.errors.address_line1 }}</div>
                        </div>
                        <div class="field full">
                            <label class="field-label" for="address_line2">Address line 2</label>
                            <input
                                id="address_line2"
                                v-model="form.address_line2"
                                class="field-input"
                                type="text"
                            >
                        </div>
                        <div class="field">
                            <label class="field-label" for="city">City</label>
                            <input
                                id="city"
                                v-model="form.city"
                                class="field-input"
                                :class="{ 'has-err': form.errors.city }"
                                type="text"
                            >
                            <div v-if="form.errors.city" class="field-err">{{ form.errors.city }}</div>
                        </div>
                        <div class="field">
                            <label class="field-label" for="postcode">Postcode</label>
                            <input
                                id="postcode"
                                v-model="form.postcode"
                                class="field-input mono"
                                :class="{ 'has-err': form.errors.postcode }"
                                type="text"
                            >
                            <div v-if="form.errors.postcode" class="field-err">{{ form.errors.postcode }}</div>
                        </div>
                        <div class="field full">
                            <label class="field-label" for="country">Country</label>
                            <input
                                id="country"
                                v-model="form.country"
                                class="field-input"
                                :class="{ 'has-err': form.errors.country }"
                                type="text"
                            >
                            <div v-if="form.errors.country" class="field-err">{{ form.errors.country }}</div>
                        </div>
                    </div>

                    <!-- BANK -->
                    <div class="sec-label">Bank details</div>

                    <div class="form-grid-3">
                        <div class="field">
                            <label class="field-label" for="bank_name">Bank name</label>
                            <input
                                id="bank_name"
                                v-model="form.bank_name"
                                class="field-input"
                                :class="{ 'has-err': form.errors.bank_name }"
                                type="text"
                            >
                            <div v-if="form.errors.bank_name" class="field-err">{{ form.errors.bank_name }}</div>
                        </div>
                        <div class="field">
                            <label class="field-label" for="sort_code">Sort code</label>
                            <input
                                id="sort_code"
                                v-model="form.sort_code"
                                class="field-input mono"
                                :class="{ 'has-err': form.errors.sort_code }"
                                type="text"
                                placeholder="20-00-00"
                            >
                            <div v-if="form.errors.sort_code" class="field-err">{{ form.errors.sort_code }}</div>
                        </div>
                        <div class="field">
                            <label class="field-label" for="account_number">Account number</label>
                            <input
                                id="account_number"
                                v-model="form.account_number"
                                class="field-input mono"
                                :class="{ 'has-err': form.errors.account_number }"
                                type="text"
                            >
                            <div v-if="form.errors.account_number" class="field-err">{{ form.errors.account_number }}</div>
                        </div>
                        <div class="field full">
                            <label class="field-label" for="account_name">Account name</label>
                            <input
                                id="account_name"
                                v-model="form.account_name"
                                class="field-input"
                                :class="{ 'has-err': form.errors.account_name }"
                                type="text"
                            >
                            <div v-if="form.errors.account_name" class="field-err">{{ form.errors.account_name }}</div>
                        </div>
                    </div>

                    <div class="sec-note">
                        <IconLock :size="14" stroke-width="1.75" />
                        Bank details are encrypted at rest and only shown to super admins.
                    </div>

                    <!-- BRANDING -->
                    <div class="sec-label">Branding &amp; email</div>

                    <div class="upload" role="button" tabindex="0" aria-disabled="true">
                        <div class="ic"><IconUpload :size="20" stroke-width="1.75" /></div>
                        <div class="lbl"><strong>Upload logo</strong> or drag a file here</div>
                        <div class="hint">PNG or SVG · max 1MB</div>
                    </div>

                    <div class="form-grid-2 field-margin">
                        <div class="field">
                            <label class="field-label" for="postmark_sender_name">Email sender name</label>
                            <input
                                id="postmark_sender_name"
                                v-model="form.postmark_sender_name"
                                class="field-input"
                                :class="{ 'has-err': form.errors.postmark_sender_name }"
                                type="text"
                            >
                            <div v-if="form.errors.postmark_sender_name" class="field-err">{{ form.errors.postmark_sender_name }}</div>
                        </div>
                        <div class="field">
                            <label class="field-label" for="postmark_sender_email">Reply-to email</label>
                            <input
                                id="postmark_sender_email"
                                v-model="form.postmark_sender_email"
                                class="field-input"
                                :class="{ 'has-err': form.errors.postmark_sender_email }"
                                type="email"
                            >
                            <div v-if="form.errors.postmark_sender_email" class="field-err">{{ form.errors.postmark_sender_email }}</div>
                        </div>
                        <div class="field full">
                            <label class="field-label" for="postmark_domain">Postmark sending domain</label>
                            <div class="field-suffix">
                                <input
                                    id="postmark_domain"
                                    v-model="form.postmark_domain"
                                    class="field-input mono"
                                    type="text"
                                    placeholder="example.co.uk"
                                >
                                <span v-if="form.postmark_domain" class="suffix-badge">
                                    <span class="badge badge-active badge-sm">Verified</span>
                                </span>
                            </div>
                            <div v-if="form.errors.postmark_domain" class="field-err">{{ form.errors.postmark_domain }}</div>
                        </div>
                    </div>

                    <!-- QBO -->
                    <div class="sec-label">QuickBooks Online</div>

                    <div class="qbo-card">
                        <div class="qbo-logo">QBO</div>
                        <div>
                            <div class="qbo-status">{{ qboConnected ? 'Connected' : 'Not connected' }}</div>
                            <div class="qbo-sub">Invoices will sync automatically once connected.</div>
                        </div>
                        <div class="right">
                            <span v-if="qboConnected" class="badge badge-active">Connected</span>
                            <button v-else type="button" class="btn btn-primary disabled" disabled>
                                <IconPlugConnected :size="15" stroke-width="1.75" />
                                Connect QuickBooks Online
                            </button>
                        </div>
                    </div>

                    <!-- Save row (visible when dirty) -->
                    <div v-if="form.isDirty" class="save-row">
                        <button type="button" class="btn btn-ghost" :disabled="form.processing" @click="discardChanges">
                            Discard changes
                        </button>
                        <button type="submit" class="btn btn-primary" :disabled="form.processing">
                            <IconDeviceFloppy :size="15" stroke-width="1.75" />
                            {{ form.processing ? 'Saving…' : 'Save changes' }}
                        </button>
                    </div>

                    <!-- Delete (only when the entity has no invoices) -->
                    <div
                        v-if="selectedEntity.invoice_count === 0"
                        style="margin-top: 16px; padding-top: 16px; border-top: 1px solid var(--border-soft); display: flex; justify-content: flex-end;"
                    >
                        <button
                            type="button"
                            class="btn btn-ghost danger"
                            @click="deleteEntity"
                        >
                            <IconTrash :size="15" stroke-width="1.75" />
                            Delete entity
                        </button>
                    </div>
                </form>
            </section>

            <!-- empty-state when no entity selected -->
            <section v-else class="form-card">
                <div class="form-body" style="padding: 48px 24px; text-align: center; color: var(--text-secondary);">
                    Select an entity from the list, or add a new one.
                </div>
            </section>
        </div>

        <!-- ═══════════ SLIDE-OVER — Add entity ═══════════ -->
        <TransitionRoot as="template" :show="showCreate">
            <Dialog as="div" class="slide-over-dialog" @close="showCreate = false">
                <TransitionChild
                    as="template"
                    enter="transition-opacity ease-out duration-200"
                    enter-from="opacity-0"
                    enter-to="opacity-100"
                    leave="transition-opacity ease-in duration-150"
                    leave-from="opacity-100"
                    leave-to="opacity-0"
                >
                    <div class="slide-over-backdrop" />
                </TransitionChild>

                <TransitionChild
                    as="template"
                    enter="transform transition ease-out duration-200"
                    enter-from="translate-x-full"
                    enter-to="translate-x-0"
                    leave="transform transition ease-in duration-150"
                    leave-from="translate-x-0"
                    leave-to="translate-x-full"
                >
                    <DialogPanel class="slide-over-panel">
                        <form class="slide-over-form" @submit.prevent="submitCreate">
                            <header class="slide-over-header">
                                <h2>New billing entity</h2>
                                <button type="button" class="icon-btn" aria-label="Close" @click="showCreate = false">
                                    <IconX :size="18" stroke-width="1.75" />
                                </button>
                            </header>

                            <div class="slide-over-body billing-entities">
                                <div
                                    v-if="createForm.hasErrors"
                                    style="margin-bottom: 12px; padding: 10px 14px; background: var(--danger-bg); color: var(--danger); border: 1px solid #FECACA; border-radius: var(--radius-md); font: 500 13px/1.4 'Inter', sans-serif; display: flex; gap: 8px; align-items: center;"
                                >
                                    <IconAlertCircle :size="16" stroke-width="2" />
                                    Please check the fields below.
                                </div>

                                <div class="sec-label" style="padding-top: 0;">Legal details</div>
                                <div class="form-grid-2">
                                    <div class="field">
                                        <label class="field-label">Legal name</label>
                                        <input v-model="createForm.legal_name" class="field-input" :class="{ 'has-err': createForm.errors.legal_name }" type="text">
                                        <div v-if="createForm.errors.legal_name" class="field-err">{{ createForm.errors.legal_name }}</div>
                                    </div>
                                    <div class="field">
                                        <label class="field-label">Trading name</label>
                                        <input v-model="createForm.name" class="field-input" :class="{ 'has-err': createForm.errors.name }" type="text">
                                        <div v-if="createForm.errors.name" class="field-err">{{ createForm.errors.name }}</div>
                                    </div>
                                    <div class="field">
                                        <label class="field-label">Company number</label>
                                        <input v-model="createForm.company_number" class="field-input mono" :class="{ 'has-err': createForm.errors.company_number }" type="text">
                                        <div v-if="createForm.errors.company_number" class="field-err">{{ createForm.errors.company_number }}</div>
                                    </div>
                                    <div class="field">
                                        <label class="field-label">VAT number</label>
                                        <input v-model="createForm.vat_number" class="field-input mono" :class="{ 'has-err': createForm.errors.vat_number }" type="text">
                                        <div v-if="createForm.errors.vat_number" class="field-err">{{ createForm.errors.vat_number }}</div>
                                    </div>
                                    <div class="field full">
                                        <label class="field-label">Address line 1</label>
                                        <input v-model="createForm.address_line1" class="field-input" :class="{ 'has-err': createForm.errors.address_line1 }" type="text">
                                        <div v-if="createForm.errors.address_line1" class="field-err">{{ createForm.errors.address_line1 }}</div>
                                    </div>
                                    <div class="field full">
                                        <label class="field-label">Address line 2</label>
                                        <input v-model="createForm.address_line2" class="field-input" type="text">
                                    </div>
                                    <div class="field">
                                        <label class="field-label">City</label>
                                        <input v-model="createForm.city" class="field-input" :class="{ 'has-err': createForm.errors.city }" type="text">
                                        <div v-if="createForm.errors.city" class="field-err">{{ createForm.errors.city }}</div>
                                    </div>
                                    <div class="field">
                                        <label class="field-label">Postcode</label>
                                        <input v-model="createForm.postcode" class="field-input mono" :class="{ 'has-err': createForm.errors.postcode }" type="text">
                                        <div v-if="createForm.errors.postcode" class="field-err">{{ createForm.errors.postcode }}</div>
                                    </div>
                                    <div class="field full">
                                        <label class="field-label">Country</label>
                                        <input v-model="createForm.country" class="field-input" type="text">
                                    </div>
                                </div>

                                <div class="sec-label">Bank details</div>
                                <div class="form-grid-3">
                                    <div class="field">
                                        <label class="field-label">Bank name</label>
                                        <input v-model="createForm.bank_name" class="field-input" :class="{ 'has-err': createForm.errors.bank_name }" type="text">
                                        <div v-if="createForm.errors.bank_name" class="field-err">{{ createForm.errors.bank_name }}</div>
                                    </div>
                                    <div class="field">
                                        <label class="field-label">Sort code</label>
                                        <input v-model="createForm.sort_code" class="field-input mono" :class="{ 'has-err': createForm.errors.sort_code }" type="text" placeholder="20-00-00">
                                        <div v-if="createForm.errors.sort_code" class="field-err">{{ createForm.errors.sort_code }}</div>
                                    </div>
                                    <div class="field">
                                        <label class="field-label">Account number</label>
                                        <input v-model="createForm.account_number" class="field-input mono" :class="{ 'has-err': createForm.errors.account_number }" type="text">
                                        <div v-if="createForm.errors.account_number" class="field-err">{{ createForm.errors.account_number }}</div>
                                    </div>
                                    <div class="field full">
                                        <label class="field-label">Account name</label>
                                        <input v-model="createForm.account_name" class="field-input" :class="{ 'has-err': createForm.errors.account_name }" type="text">
                                        <div v-if="createForm.errors.account_name" class="field-err">{{ createForm.errors.account_name }}</div>
                                    </div>
                                </div>

                                <div class="sec-note">
                                    <IconLock :size="14" stroke-width="1.75" />
                                    Bank details are encrypted at rest and only shown to super admins.
                                </div>

                                <div class="sec-label">Email</div>
                                <div class="form-grid-2">
                                    <div class="field">
                                        <label class="field-label">Email sender name</label>
                                        <input v-model="createForm.postmark_sender_name" class="field-input" :class="{ 'has-err': createForm.errors.postmark_sender_name }" type="text">
                                        <div v-if="createForm.errors.postmark_sender_name" class="field-err">{{ createForm.errors.postmark_sender_name }}</div>
                                    </div>
                                    <div class="field">
                                        <label class="field-label">Reply-to email</label>
                                        <input v-model="createForm.postmark_sender_email" class="field-input" :class="{ 'has-err': createForm.errors.postmark_sender_email }" type="email">
                                        <div v-if="createForm.errors.postmark_sender_email" class="field-err">{{ createForm.errors.postmark_sender_email }}</div>
                                    </div>
                                    <div class="field full">
                                        <label class="field-label">Postmark sending domain</label>
                                        <input v-model="createForm.postmark_domain" class="field-input mono" type="text" placeholder="example.co.uk">
                                        <div v-if="createForm.errors.postmark_domain" class="field-err">{{ createForm.errors.postmark_domain }}</div>
                                    </div>
                                </div>
                            </div>

                            <footer class="slide-over-footer">
                                <button type="button" class="btn btn-secondary" @click="showCreate = false">Cancel</button>
                                <button type="submit" class="btn btn-primary" :disabled="createForm.processing">
                                    <IconPlus :size="15" stroke-width="1.75" />
                                    {{ createForm.processing ? 'Creating…' : 'Create entity' }}
                                </button>
                            </footer>
                        </form>
                    </DialogPanel>
                </TransitionChild>
            </Dialog>
        </TransitionRoot>
    </InternalLayout>
</template>
