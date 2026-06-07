<script setup>
import { computed, ref } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import { IconAlertCircle } from '@tabler/icons-vue';
import PortalLayout from '@/Layouts/PortalLayout.vue';
import ConfirmModal from '@/Components/UI/ConfirmModal.vue';

const props = defineProps({
    subscriptions: { type: Array, default: () => [] },
    available: { type: Array, default: () => [] },
});

const counts = computed(() => ({
    invoices: undefined,
    support: undefined,
}));

function gbp(n) {
    return Number(n ?? 0).toLocaleString('en-GB', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function initial(name) {
    return (name || '?').charAt(0).toUpperCase();
}

// status → handoff badge class + label.
function statusBadge(status) {
    if (status === 'active') return { cls: 'badge-active', label: 'Active' };
    if (status === 'trial') return { cls: 'badge-open', label: 'Trial' };
    if (status === 'pending') return { cls: 'badge-awaiting', label: 'Pending approval' };
    if (status === 'suspended') return { cls: 'badge-suspended', label: 'Suspended' };
    return { cls: 'badge-neutral', label: status };
}

// Summary line: "3 active · £20.00 / month recurring · 1 suspended".
const activeCount = computed(() => props.subscriptions.filter((s) => ['active', 'trial'].includes(s.status)).length);
const suspendedCount = computed(() => props.subscriptions.filter((s) => s.status === 'suspended').length);
const recurringTotal = computed(() =>
    props.subscriptions
        .filter((s) => s.status === 'active')
        .reduce((sum, s) => sum + Number(s.price || 0), 0),
);

/* ── OPEN / LAUNCH (mirrors the dashboard) ── */
const launchingId = ref(null);
function launchSub(sub) {
    if (! sub.sso_enabled) {
        if (sub.sso_url) window.location.href = sub.sso_url;
        return;
    }
    launchingId.value = sub.id;
    router.post(`/portal/launch/${sub.product_slug}`, {}, {
        preserveState: true,
        preserveScroll: true,
        onFinish: () => { launchingId.value = null; },
    });
}

/* ── CANCEL ── */
const showCancelModal = ref(false);
const cancelTarget = ref(null);
const cancelProcessing = ref(false);

function askCancel(sub) {
    cancelTarget.value = sub;
    showCancelModal.value = true;
}
function performCancel() {
    if (! cancelTarget.value) return;
    cancelProcessing.value = true;
    router.post(`/portal/subscriptions/${cancelTarget.value.id}/cancel`, {}, {
        preserveScroll: true,
        onFinish: () => {
            cancelProcessing.value = false;
            showCancelModal.value = false;
            cancelTarget.value = null;
        },
    });
}
const cancelMessage = computed(() => {
    if (! cancelTarget.value) return '';
    return `'${cancelTarget.value.product_name}' will be cancelled at the end of your current billing period. You'll keep access until then.`;
});

/* ── SUBSCRIBE TO A NEW PRODUCT (kept from the existing flow) ── */
const subscribeOpen = ref(false);
const selectedProduct = ref(null);
const selectedPlanId = ref(null);
const selectedPriceId = ref(null);

const subscribeForm = useForm({ product_id: null, plan_id: null, price_id: null });

function openSubscribe(product) {
    selectedProduct.value = product;
    const firstPlan = product.plans?.[0];
    selectedPlanId.value = firstPlan?.id ?? null;
    const defaultPrice = firstPlan?.prices?.find((p) => p.is_default) ?? firstPlan?.prices?.[0];
    selectedPriceId.value = defaultPrice?.id ?? null;
    subscribeOpen.value = true;
}
function closeSubscribe() {
    subscribeOpen.value = false;
    selectedProduct.value = null;
    selectedPlanId.value = null;
    selectedPriceId.value = null;
    subscribeForm.clearErrors();
}
function selectPlan(plan) {
    selectedPlanId.value = plan.id;
    const def = plan.prices?.find((p) => p.is_default) ?? plan.prices?.[0];
    selectedPriceId.value = def?.id ?? null;
}
const currentPlan = computed(() =>
    selectedProduct.value?.plans?.find((p) => p.id === selectedPlanId.value) ?? null,
);
function submitSubscribe() {
    if (! selectedProduct.value || ! selectedPlanId.value || ! selectedPriceId.value) return;
    subscribeForm.product_id = selectedProduct.value.id;
    subscribeForm.plan_id = selectedPlanId.value;
    subscribeForm.price_id = selectedPriceId.value;
    subscribeForm.post('/portal/subscriptions', { preserveScroll: true, onSuccess: closeSubscribe });
}

function scrollToCatalogue() {
    document.getElementById('add-product')?.scrollIntoView({ behavior: 'smooth' });
}
</script>

<template>
    <Head title="Subscriptions · Whitedash" />
    <PortalLayout active-nav="subscriptions" :counts="counts">
        <!-- ═══════════ DESKTOP ═══════════ -->
        <template #desktop>
            <div class="content">
                <div class="page-head">
                    <div class="ph-l">
                        <h1>Subscriptions</h1>
                        <div class="ph-sub">
                            {{ activeCount }} active<span class="sep">·</span>£{{ gbp(recurringTotal) }} / month recurring<span v-if="suspendedCount" class="sep">·</span><template v-if="suspendedCount">{{ suspendedCount }} suspended</template>
                        </div>
                    </div>
                    <button v-if="available.length" class="btn btn-secondary" @click="scrollToCatalogue"><i class="ti ti-plus" />Add a product</button>
                </div>

                <div v-if="subscriptions.length === 0" class="empty">
                    <div class="ic"><i class="ti ti-credit-card" /></div>
                    <h4>No subscriptions yet</h4>
                    <p>Browse the catalogue below to add your first product.</p>
                </div>

                <div v-else class="prod-grid">
                    <div
                        v-for="sub in subscriptions"
                        :key="sub.id"
                        class="prod-card"
                        :class="{ suspended: sub.status === 'suspended' }"
                    >
                        <div class="pc-top">
                            <div class="pc-logo" :style="{ background: sub.icon_colour || 'var(--accent)' }">{{ initial(sub.product_name) }}</div>
                            <span class="badge" :class="statusBadge(sub.status).cls">{{ statusBadge(sub.status).label }}</span>
                        </div>
                        <div class="pc-name">{{ sub.product_name }}</div>
                        <div class="pc-plan">{{ sub.plan_name }}</div>
                        <div class="pc-divider" />

                        <div v-if="sub.status === 'suspended'" class="pc-warn">
                            <i class="ti ti-alert-circle" />Suspended — settle outstanding invoices to reinstate
                        </div>

                        <div class="pc-facts">
                            <div class="pc-fact"><i class="ti ti-credit-card" />{{ sub.interval_label || 'Recurring' }}<span class="cost">£{{ gbp(sub.price) }}</span></div>
                            <div v-if="sub.next_billing_date" class="pc-fact"><i class="ti ti-calendar" />Next billing <strong>{{ sub.next_billing_date }}</strong></div>
                            <div v-else-if="sub.started_at" class="pc-fact"><i class="ti ti-calendar-event" />Started <strong>{{ sub.started_at }}</strong></div>
                            <div v-if="sub.trial_ends_at && sub.status === 'trial'" class="pc-fact"><i class="ti ti-clock" />Trial ends <strong>{{ sub.trial_ends_at }}</strong></div>
                            <div v-if="sub.cancels_at" class="pc-fact" style="color:var(--danger)"><i class="ti ti-player-stop" />Cancels <strong>{{ sub.cancels_at }}</strong></div>
                        </div>

                        <div class="pc-foot">
                            <button v-if="sub.status === 'suspended'" class="btn btn-danger btn-block" @click="router.visit('/portal/invoices')">Pay to reinstate<i class="ti ti-arrow-right" /></button>
                            <template v-else-if="sub.status === 'pending'">
                                <div class="pc-fact" style="justify-content:center"><i class="ti ti-clock-hour-4" />Awaiting activation by our team</div>
                            </template>
                            <template v-else>
                                <button v-if="sub.sso_enabled || sub.sso_url" class="btn btn-secondary btn-block" :disabled="launchingId === sub.id" @click="launchSub(sub)">
                                    {{ launchingId === sub.id ? 'Opening…' : `Open ${sub.product_name}` }}<i class="ti ti-external-link" />
                                </button>
                                <button v-if="!sub.cancels_at" class="ghost-link" style="margin-top:10px;color:var(--danger)" @click="askCancel(sub)">Cancel subscription</button>
                            </template>
                        </div>
                    </div>
                </div>

                <!-- Add a product (catalogue) -->
                <template v-if="available.length">
                    <div id="add-product" class="section-head" style="margin-top:40px">
                        <div class="col-l">
                            <h2>Add a product</h2>
                            <div class="desc">Sign up for additional Whitedash products — we'll activate within one business day.</div>
                        </div>
                    </div>
                    <div class="prod-grid">
                        <div v-for="product in available" :key="product.id" class="prod-card">
                            <div class="pc-top">
                                <div class="pc-logo" :style="{ background: product.icon_colour || 'var(--accent)' }">{{ initial(product.name) }}</div>
                            </div>
                            <div class="pc-name">{{ product.name }}</div>
                            <div class="pc-plan">{{ product.plans.length }} {{ product.plans.length === 1 ? 'plan' : 'plans' }} available</div>
                            <div class="pc-divider" />
                            <div class="pc-foot">
                                <button class="btn btn-primary btn-block" :disabled="product.plans.length === 0" @click="openSubscribe(product)"><i class="ti ti-plus" />Subscribe</button>
                            </div>
                        </div>
                    </div>
                </template>
            </div>
        </template>

        <!-- ═══════════ MOBILE ═══════════ -->
        <template #mobile>
            <div class="m-appbar">
                <div class="title">Subscriptions</div>
                <div class="spacer" />
                <div class="bell"><i class="ti ti-bell" /></div>
            </div>
            <div class="m-body is-pb">
                <div style="font:400 13px/1.4 'Inter';color:var(--text-secondary);margin-top:-4px">
                    {{ activeCount }} active · £{{ gbp(recurringTotal) }}/month<template v-if="suspendedCount"> · {{ suspendedCount }} suspended</template>
                </div>

                <div v-for="sub in subscriptions" :key="sub.id" class="m-prod" :class="{ suspended: sub.status === 'suspended' }">
                    <div class="top">
                        <div class="logo" :style="{ background: sub.icon_colour || 'var(--accent)' }">{{ initial(sub.product_name) }}</div>
                        <div style="flex:1"><div class="nm">{{ sub.product_name }}</div><div class="pl">{{ sub.plan_name }}</div></div>
                        <span class="badge badge-sm" :class="statusBadge(sub.status).cls">{{ statusBadge(sub.status).label }}</span>
                    </div>
                    <div v-if="sub.status === 'suspended'" class="pc-warn" style="margin:14px 0 0"><i class="ti ti-alert-circle" />Suspended — settle invoices to reinstate</div>
                    <div v-else class="facts">
                        <div class="fact"><i class="ti ti-credit-card" />{{ sub.interval_label || 'Recurring' }}<span class="cost">£{{ gbp(sub.price) }}</span></div>
                        <div v-if="sub.next_billing_date" class="fact"><i class="ti ti-calendar" />Next billing {{ sub.next_billing_date }}</div>
                        <div v-else-if="sub.started_at" class="fact"><i class="ti ti-calendar-event" />Started {{ sub.started_at }}</div>
                    </div>
                    <button v-if="sub.status === 'suspended'" class="btn btn-danger btn-block btn-sm" style="margin-top:12px" @click="router.visit('/portal/invoices')">Pay to reinstate<i class="ti ti-arrow-right" /></button>
                    <button v-else-if="sub.status !== 'pending' && (sub.sso_enabled || sub.sso_url)" class="btn btn-secondary btn-block btn-sm" style="margin-top:12px" :disabled="launchingId === sub.id" @click="launchSub(sub)">Open {{ sub.product_name }}<i class="ti ti-external-link" /></button>
                </div>

                <template v-if="available.length">
                    <div class="m-section-title" style="margin-top:6px">Add a product</div>
                    <div v-for="product in available" :key="product.id" class="m-prod">
                        <div class="top">
                            <div class="logo" :style="{ background: product.icon_colour || 'var(--accent)' }">{{ initial(product.name) }}</div>
                            <div style="flex:1"><div class="nm">{{ product.name }}</div><div class="pl">{{ product.plans.length }} {{ product.plans.length === 1 ? 'plan' : 'plans' }}</div></div>
                        </div>
                        <button class="btn btn-primary btn-block btn-sm" style="margin-top:12px" :disabled="product.plans.length === 0" @click="openSubscribe(product)"><i class="ti ti-plus" />Subscribe</button>
                    </div>
                </template>
            </div>
        </template>
    </PortalLayout>

    <!-- SUBSCRIBE DIALOG (kept; teleported outside .wd) -->
    <Teleport to="body">
        <div v-if="subscribeOpen" class="portal-modal-backdrop" @click="closeSubscribe" />
        <div v-if="subscribeOpen" class="portal-modal" role="dialog" aria-modal="true">
            <header class="portal-modal-header">
                <div>
                    <div class="portal-modal-eyebrow">Subscribe to</div>
                    <h2>{{ selectedProduct?.name }}</h2>
                </div>
                <button type="button" class="icon-btn" @click="closeSubscribe">×</button>
            </header>
            <div class="portal-modal-body">
                <div v-if="(selectedProduct?.plans?.length ?? 0) === 0" class="portal-empty-inline">
                    <span>This product has no public plans yet — please contact support.</span>
                </div>
                <template v-else>
                    <div class="portal-modal-section-title">Choose a plan</div>
                    <div class="portal-plan-list">
                        <label
                            v-for="plan in selectedProduct.plans"
                            :key="plan.id"
                            class="portal-plan-row"
                            :class="{ active: selectedPlanId === plan.id }"
                        >
                            <input type="radio" :value="plan.id" :checked="selectedPlanId === plan.id" @change="selectPlan(plan)">
                            <div class="portal-plan-info">
                                <div class="portal-plan-name">{{ plan.name }}</div>
                                <div v-if="plan.description" class="portal-plan-desc">{{ plan.description }}</div>
                            </div>
                        </label>
                    </div>

                    <div v-if="currentPlan && currentPlan.prices.length > 0" class="portal-modal-section-title" style="margin-top:20px">Choose billing interval</div>
                    <div v-if="currentPlan" class="portal-price-list">
                        <label
                            v-for="price in currentPlan.prices"
                            :key="price.id"
                            class="portal-price-row"
                            :class="{ active: selectedPriceId === price.id }"
                        >
                            <input type="radio" :value="price.id" :checked="selectedPriceId === price.id" @change="selectedPriceId = price.id">
                            <div class="portal-price-amount">£{{ gbp(price.price) }}</div>
                            <div class="portal-price-interval">{{ price.interval_label }}</div>
                        </label>
                    </div>

                    <div v-if="subscribeForm.errors.product_id" class="portal-flash error">
                        <IconAlertCircle :size="16" stroke-width="2" />
                        {{ subscribeForm.errors.product_id }}
                    </div>
                </template>
            </div>
            <footer class="portal-modal-footer">
                <button type="button" class="btn btn-secondary" @click="closeSubscribe">Cancel</button>
                <button type="button" class="btn btn-primary" :disabled="! selectedPlanId || ! selectedPriceId || subscribeForm.processing" @click="submitSubscribe">
                    {{ subscribeForm.processing ? 'Submitting…' : 'Submit request' }}
                </button>
            </footer>
        </div>
    </Teleport>

    <ConfirmModal
        v-model:show="showCancelModal"
        :title="cancelTarget ? `Cancel ${cancelTarget.product_name}?` : 'Cancel subscription?'"
        :message="cancelMessage"
        confirm-label="Cancel subscription"
        variant="danger"
        :loading="cancelProcessing"
        @confirm="performCancel"
    />
</template>
