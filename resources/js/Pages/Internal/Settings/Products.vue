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
    IconX,
    IconAlertCircle,
    IconLock,
    IconUsers,
    IconArrowRight,
    IconTag,
} from '@tabler/icons-vue';
import SettingsLayout from '@/Layouts/SettingsLayout.vue';
import ConfirmModal from '@/Components/UI/ConfirmModal.vue';

const props = defineProps({
    products: { type: Array, default: () => [] },
});

/* ─── Colour palette presets ─── */
const PRESETS = [
    { hex: '#0D9488', name: 'Teal' },
    { hex: '#3B82F6', name: 'Blue' },
    { hex: '#7C3AED', name: 'Purple' },
    { hex: '#8B5CF6', name: 'Violet' },
    { hex: '#F59E0B', name: 'Amber' },
    { hex: '#EF4444', name: 'Red' },
    { hex: '#0F172A', name: 'Navy' },
    { hex: '#10B981', name: 'Green' },
];

/* ─── Selection ─── */
const selectedId = ref(props.products[0]?.id ?? null);
const selectedProduct = computed(() => props.products.find((p) => p.id === selectedId.value) ?? null);

const activeCount = computed(() => props.products.filter((p) => p.is_active).length);

function initial(name) { return (name?.[0] ?? '?').toUpperCase(); }

/* ─── Detail form ─── */
function buildDefaults(p) {
    return {
        name: p?.name ?? '',
        slug: p?.slug ?? '',
        description: p?.description ?? '',
        icon_colour: p?.icon_colour ?? '#0D9488',
        is_active: p?.is_active ?? true,
        is_coming_soon: p?.is_coming_soon ?? false,
        sort_order: p?.sort_order ?? 0,
    };
}

const form = useForm(buildDefaults(selectedProduct.value));

watch(selectedProduct, (next) => {
    form.defaults(buildDefaults(next));
    form.reset();
    form.clearErrors();
});

/* ─── Switch product (ConfirmModal when dirty) ─── */
const showSwitchModal = ref(false);
const pendingSwitchId = ref(null);

function selectProduct(id) {
    if (id === selectedId.value) return;
    if (form.isDirty) {
        pendingSwitchId.value = id;
        showSwitchModal.value = true;

        return;
    }
    selectedId.value = id;
}

function handleSwitchConfirm() {
    const id = pendingSwitchId.value;
    showSwitchModal.value = false;
    pendingSwitchId.value = null;
    if (id !== null) selectedId.value = id;
}

function saveDetail() {
    if (!selectedProduct.value) return;
    form.put(`/settings/products/${selectedProduct.value.id}`, {
        preserveScroll: true,
    });
}

function discardChanges() {
    form.reset();
    form.clearErrors();
}

function toggleActive() {
    if (!selectedProduct.value) return;
    router.post(`/settings/products/${selectedProduct.value.id}/toggle`, {}, {
        preserveScroll: true,
    });
}

/* ─── Create slide-over ─── */
const showCreate = ref(false);
const createForm = useForm(buildDefaults(null));

function openCreate() {
    createForm.reset();
    createForm.clearErrors();
    showCreate.value = true;
}

function submitCreate() {
    createForm.post('/settings/products', {
        onSuccess: () => { showCreate.value = false; },
        preserveScroll: true,
    });
}

/* ─── Slug auto-derive (only while empty) ─── */
function slugFromName(name) {
    return String(name || '')
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/^-+|-+$/g, '')
        .slice(0, 50);
}

watch(() => createForm.name, (n) => {
    if (!createForm.slug || createForm.slug === slugFromName(createForm.__lastName ?? '')) {
        createForm.slug = slugFromName(n);
    }
    createForm.__lastName = n;
});

const slugLocked = computed(() => (selectedProduct.value?.active_customers ?? 0) > 0);

/* Jump from the product detail page into the dedicated plan builder.
 * All plan/category/price management lives on that page now. */
function goToPlans() {
    if (! selectedProduct.value) return;
    router.visit(`/settings/products/${selectedProduct.value.id}/plans`);
}
</script>


<template>
    <Head title="Products" />

    <SettingsLayout title="Products" active-section="products">
        <template #topbar-actions>
            <button type="button" class="btn btn-primary" @click="openCreate">
                <IconPlus :size="15" stroke-width="1.75" />
                Add product
            </button>
        </template>

        <div class="billing-entities">
            <!-- LEFT — product list -->
            <aside class="ent-list-card">
                <div class="ent-list-head">
                    <span class="title">Products</span>
                    <span class="badge badge-active badge-sm right">{{ activeCount }} active</span>
                </div>

                <div
                    v-for="p in products"
                    :key="p.id"
                    class="ent-row"
                    :class="{ selected: p.id === selectedId }"
                    @click="selectProduct(p.id)"
                >
                    <div class="top">
                        <div
                            class="ent-mark"
                            :style="{ background: p.icon_colour, color: '#fff' }"
                        >{{ initial(p.name) }}</div>
                        <div>
                            <div class="ent-name">{{ p.name }}</div>
                            <div class="ent-co">{{ p.slug }}</div>
                        </div>
                        <div class="right">
                            <span
                                v-if="p.is_coming_soon"
                                class="badge badge-pending badge-sm"
                            >Coming soon</span>
                            <span
                                v-else
                                class="badge badge-sm"
                                :class="p.is_active ? 'badge-active' : 'badge-inactive'"
                            >{{ p.is_active ? 'Active' : 'Inactive' }}</span>
                        </div>
                    </div>
                    <div class="ent-desc">
                        {{ p.active_customers }} active customer{{ p.active_customers === 1 ? '' : 's' }}
                    </div>
                </div>

                <div class="ent-add-divider" />
                <button type="button" class="ent-add" @click="openCreate">
                    <IconPlus :size="16" stroke-width="1.75" />
                    Add new product
                </button>
            </aside>

            <!-- RIGHT — detail form -->
            <section v-if="selectedProduct" class="form-card">
                <div class="form-head">
                    <div
                        class="ent-mark lg"
                        :style="{ background: form.icon_colour, color: '#fff' }"
                    >{{ initial(form.name) }}</div>
                    <div class="form-title">{{ selectedProduct.name }}</div>
                    <span
                        v-if="selectedProduct.is_coming_soon"
                        class="badge badge-pending"
                    >Coming soon</span>
                    <span
                        v-else
                        class="badge"
                        :class="selectedProduct.is_active ? 'badge-active' : 'badge-inactive'"
                    >{{ selectedProduct.is_active ? 'Active' : 'Inactive' }}</span>
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
                    <!-- PRODUCT DETAILS -->
                    <div class="sec-label">Product details</div>
                    <div class="form-grid-2">
                        <div class="field">
                            <label class="field-label">Name</label>
                            <input
                                v-model="form.name"
                                class="field-input"
                                :class="{ 'has-err': form.errors.name }"
                                type="text"
                                maxlength="100"
                            >
                            <div v-if="form.errors.name" class="field-err">{{ form.errors.name }}</div>
                        </div>
                        <div class="field">
                            <label class="field-label">
                                Slug
                                <IconLock v-if="slugLocked" :size="11" stroke-width="2" style="margin-left: 4px; display: inline; vertical-align: middle;" />
                            </label>
                            <input
                                v-model="form.slug"
                                class="field-input mono"
                                :class="{ 'has-err': form.errors.slug }"
                                type="text"
                                maxlength="50"
                                :readonly="slugLocked"
                                :style="slugLocked ? 'background: var(--neutral-bg); cursor: not-allowed;' : ''"
                            >
                            <div class="field-help">
                                {{ slugLocked
                                    ? 'Locked — product has active customers.'
                                    : 'Used in URLs and API. Avoid changing after launch.' }}
                            </div>
                            <div v-if="form.errors.slug" class="field-err">{{ form.errors.slug }}</div>
                        </div>
                        <div class="field full">
                            <label class="field-label">Description</label>
                            <textarea
                                v-model="form.description"
                                class="field-input"
                                rows="3"
                                maxlength="500"
                                placeholder="Brief description visible to staff and on dashboards"
                            />
                            <div v-if="form.errors.description" class="field-err">{{ form.errors.description }}</div>
                        </div>
                    </div>

                    <!-- DISPLAY -->
                    <div class="sec-label">Display</div>
                    <div class="form-grid-2">
                        <div class="field full">
                            <label class="field-label">Icon colour</label>
                            <div class="colour-row">
                                <button
                                    v-for="c in PRESETS"
                                    :key="c.hex"
                                    type="button"
                                    class="colour-swatch"
                                    :class="{ active: form.icon_colour.toLowerCase() === c.hex.toLowerCase() }"
                                    :style="{ background: c.hex }"
                                    :title="c.name"
                                    @click="form.icon_colour = c.hex"
                                />
                                <input
                                    v-model="form.icon_colour"
                                    class="field-input mono colour-hex"
                                    :class="{ 'has-err': form.errors.icon_colour }"
                                    type="text"
                                    maxlength="7"
                                    pattern="^#[0-9A-Fa-f]{6}$"
                                >
                                <div
                                    class="ent-mark lg"
                                    :style="{ background: form.icon_colour, color: '#fff' }"
                                >{{ initial(form.name) }}</div>
                            </div>
                            <div v-if="form.errors.icon_colour" class="field-err">{{ form.errors.icon_colour }}</div>
                        </div>
                    </div>

                    <!-- STATUS -->
                    <div class="sec-label">Status</div>
                    <div class="status-rows">
                        <div class="set-row">
                            <div>
                                <div class="nm">Active</div>
                                <div class="sb">Inactive products are hidden from provisioning.</div>
                            </div>
                            <button
                                type="button"
                                class="toggle"
                                :class="{ on: form.is_active }"
                                aria-label="Toggle active"
                                @click="form.is_active = !form.is_active"
                            />
                        </div>
                        <div class="set-row">
                            <div>
                                <div class="nm">Coming soon</div>
                                <div class="sb">Shows a "Coming soon" badge instead of the product card.</div>
                            </div>
                            <button
                                type="button"
                                class="toggle"
                                :class="{ on: form.is_coming_soon }"
                                aria-label="Toggle coming soon"
                                @click="form.is_coming_soon = !form.is_coming_soon"
                            />
                        </div>
                        <div
                            v-if="!form.is_active && selectedProduct.active_customers > 0"
                            class="warn-pill"
                            style="margin-top: 10px;"
                        >
                            Has {{ selectedProduct.active_customers }} active customer{{ selectedProduct.active_customers === 1 ? '' : 's' }} — suspend subscriptions before deactivating.
                        </div>
                    </div>

                    <!-- STATS -->
                    <div class="sec-label">Stats</div>
                    <div class="stats-card">
                        <div class="stats-row">
                            <span class="k">Active customers</span>
                            <span class="v">{{ selectedProduct.active_customers }}</span>
                        </div>
                        <div class="stats-row">
                            <span class="k">Total customers ever</span>
                            <span class="v">{{ selectedProduct.total_customers }}</span>
                        </div>
                        <a
                            :href="`/customers?product=${selectedProduct.slug}`"
                            class="stats-link"
                        >
                            <IconUsers :size="14" stroke-width="1.75" />
                            View customers
                            <IconArrowRight :size="14" stroke-width="1.75" />
                        </a>
                    </div>

                    <!-- Save row -->
                    <div v-if="form.isDirty" class="save-row">
                        <button type="button" class="btn btn-ghost" :disabled="form.processing" @click="discardChanges">Discard changes</button>
                        <button type="submit" class="btn btn-primary" :disabled="form.processing">
                            <IconDeviceFloppy :size="15" stroke-width="1.75" />
                            {{ form.processing ? 'Saving…' : 'Save changes' }}
                        </button>
                    </div>

                    <!-- Toggle row (operator action, separate from form save) -->
                    <div
                        style="margin-top: 16px; padding-top: 16px; border-top: 1px solid var(--border-soft); display: flex; justify-content: flex-end;"
                    >
                        <button
                            type="button"
                            class="btn btn-ghost"
                            :class="{ danger: selectedProduct.is_active }"
                            @click="toggleActive"
                        >
                            {{ selectedProduct.is_active ? 'Deactivate' : 'Activate' }}
                        </button>
                    </div>

                    <!-- Link card to the dedicated plan builder. All
                         plan/category/price management lives there now. -->
                    <button type="button" class="plans-link-card" @click="goToPlans">
                        <div class="plc-left">
                            <div class="plc-icon"><IconTag :size="18" stroke-width="1.75" /></div>
                            <div style="text-align: left;">
                                <div class="plc-title">Plans &amp; Pricing</div>
                                <div class="plc-sub">
                                    {{ selectedProduct.plans?.length ?? 0 }} plans · manage tiers, pricing and categories
                                </div>
                            </div>
                        </div>
                        <IconArrowRight :size="16" stroke-width="1.75" class="plc-arrow" />
                    </button>
                </form>
            </section>

            <section v-else class="form-card">
                <div class="form-body" style="padding: 48px 24px; text-align: center; color: var(--text-secondary);">
                    Select a product from the list, or add a new one.
                </div>
            </section>
        </div>

        <!-- ═══ Add product slide-over ═══ -->
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
                                <h2>New product</h2>
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

                                <div class="sec-label" style="padding-top: 0;">Product details</div>
                                <div class="form-grid-2">
                                    <div class="field">
                                        <label class="field-label">Name</label>
                                        <input v-model="createForm.name" class="field-input" :class="{ 'has-err': createForm.errors.name }" type="text" maxlength="100">
                                        <div v-if="createForm.errors.name" class="field-err">{{ createForm.errors.name }}</div>
                                    </div>
                                    <div class="field">
                                        <label class="field-label">Slug</label>
                                        <input v-model="createForm.slug" class="field-input mono" :class="{ 'has-err': createForm.errors.slug }" type="text" maxlength="50">
                                        <div class="field-help">Used in URLs and API — auto-filled from name.</div>
                                        <div v-if="createForm.errors.slug" class="field-err">{{ createForm.errors.slug }}</div>
                                    </div>
                                    <div class="field full">
                                        <label class="field-label">Description</label>
                                        <textarea v-model="createForm.description" class="field-input" rows="3" maxlength="500" />
                                        <div v-if="createForm.errors.description" class="field-err">{{ createForm.errors.description }}</div>
                                    </div>
                                </div>

                                <div class="sec-label">Display</div>
                                <div class="form-grid-2">
                                    <div class="field full">
                                        <label class="field-label">Icon colour</label>
                                        <div class="colour-row">
                                            <button
                                                v-for="c in PRESETS"
                                                :key="c.hex"
                                                type="button"
                                                class="colour-swatch"
                                                :class="{ active: createForm.icon_colour.toLowerCase() === c.hex.toLowerCase() }"
                                                :style="{ background: c.hex }"
                                                :title="c.name"
                                                @click="createForm.icon_colour = c.hex"
                                            />
                                            <input
                                                v-model="createForm.icon_colour"
                                                class="field-input mono colour-hex"
                                                :class="{ 'has-err': createForm.errors.icon_colour }"
                                                type="text"
                                                maxlength="7"
                                            >
                                            <div
                                                class="ent-mark lg"
                                                :style="{ background: createForm.icon_colour, color: '#fff' }"
                                            >{{ initial(createForm.name) }}</div>
                                        </div>
                                        <div v-if="createForm.errors.icon_colour" class="field-err">{{ createForm.errors.icon_colour }}</div>
                                    </div>
                                </div>

                                <div class="sec-label">Status</div>
                                <div class="status-rows">
                                    <div class="set-row">
                                        <div>
                                            <div class="nm">Active</div>
                                            <div class="sb">Available for new subscriptions.</div>
                                        </div>
                                        <button
                                            type="button"
                                            class="toggle"
                                            :class="{ on: createForm.is_active }"
                                            aria-label="Toggle active"
                                            @click="createForm.is_active = !createForm.is_active"
                                        />
                                    </div>
                                    <div class="set-row">
                                        <div>
                                            <div class="nm">Coming soon</div>
                                            <div class="sb">Pre-launch marker for the dashboard.</div>
                                        </div>
                                        <button
                                            type="button"
                                            class="toggle"
                                            :class="{ on: createForm.is_coming_soon }"
                                            aria-label="Toggle coming soon"
                                            @click="createForm.is_coming_soon = !createForm.is_coming_soon"
                                        />
                                    </div>
                                </div>
                            </div>

                            <footer class="slide-over-footer">
                                <button type="button" class="btn btn-secondary" @click="showCreate = false">Cancel</button>
                                <button type="submit" class="btn btn-primary" :disabled="createForm.processing">
                                    <IconPlus :size="15" stroke-width="1.75" />
                                    {{ createForm.processing ? 'Creating…' : 'Create product' }}
                                </button>
                            </footer>
                        </form>
                    </DialogPanel>
                </TransitionChild>
            </Dialog>
        </TransitionRoot>

        <ConfirmModal
            v-model:show="showSwitchModal"
            title="Discard unsaved changes?"
            message="You have unsaved changes on the current product. Switching will discard them."
            confirm-label="Discard and switch"
            variant="warning"
            @confirm="handleSwitchConfirm"
        />

    </SettingsLayout>
</template>
