<script setup>
import { computed, ref } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import {
    IconCalendar,
    IconCreditCard,
    IconPlus,
    IconAlertCircle,
} from '@tabler/icons-vue';
import PortalLayout from '@/Layouts/PortalLayout.vue';
import ConfirmModal from '@/Components/UI/ConfirmModal.vue';

const props = defineProps({
    subscriptions: { type: Array, default: () => [] },
    available: { type: Array, default: () => [] },
});

const counts = computed(() => ({
    subscriptions: props.subscriptions.length,
}));

function gbp(n) {
    return `£${Number(n).toLocaleString('en-GB', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
}

function statusBadge(status) {
    if (status === 'active') return { cls: 'badge-active', label: 'Active' };
    if (status === 'trial') return { cls: 'badge-info', label: 'Trial' };
    if (status === 'pending') return { cls: 'badge-pending', label: 'Pending approval' };
    if (status === 'suspended') return { cls: 'badge-soon', label: 'Suspended' };
    return { cls: 'badge-soon', label: status };
}

/* ── CANCEL ───────────────────────────────────────────────────── */
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

/* ── SUBSCRIBE TO A NEW PRODUCT ─────────────────────────────── */
const subscribeOpen = ref(false);
const selectedProduct = ref(null);
const selectedPlanId = ref(null);
const selectedPriceId = ref(null);

const subscribeForm = useForm({
    product_id: null,
    plan_id: null,
    price_id: null,
});

function openSubscribe(product) {
    selectedProduct.value = product;
    // Auto-select the first plan + its default (or first) price so the
    // customer can submit in one click for the common case.
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

    subscribeForm.post('/portal/subscriptions', {
        preserveScroll: true,
        onSuccess: closeSubscribe,
    });
}
</script>

<template>
    <Head title="Subscriptions · Whitedash" />
    <PortalLayout title="Subscriptions" active-nav="subscriptions" :counts="counts">
        <div class="portal-section-head">
            <div class="col-l">
                <h2>Active subscriptions</h2>
                <div class="desc">{{ subscriptions.length }} {{ subscriptions.length === 1 ? 'subscription' : 'subscriptions' }} on your account.</div>
            </div>
        </div>

        <div v-if="subscriptions.length === 0" class="portal-empty">
            <IconCreditCard :size="32" stroke-width="1.5" />
            <div class="portal-empty-title">No subscriptions yet</div>
            <div class="portal-empty-sub">Browse the catalogue below to add your first product.</div>
        </div>

        <div v-else class="portal-sub-list">
            <article v-for="sub in subscriptions" :key="sub.id" class="portal-sub-card">
                <div class="portal-sub-left">
                    <div
                        class="pc-logo"
                        :style="{ background: sub.icon_colour || 'var(--accent)' }"
                    >{{ (sub.product_name || '?').charAt(0).toUpperCase() }}</div>
                </div>
                <div class="portal-sub-main">
                    <div class="portal-sub-head">
                        <h3>{{ sub.product_name }}</h3>
                        <span class="badge badge-sm" :class="statusBadge(sub.status).cls">{{ statusBadge(sub.status).label }}</span>
                    </div>
                    <div class="portal-sub-meta">
                        {{ sub.plan_name }} plan · <strong>{{ gbp(sub.price) }}</strong> · {{ sub.interval_label }}
                    </div>
                    <div class="portal-sub-facts">
                        <span v-if="sub.next_billing_date" class="pc-fact">
                            <IconCalendar :size="13" stroke-width="1.75" />
                            Next billing <strong>{{ sub.next_billing_date }}</strong>
                        </span>
                        <span v-if="sub.cancels_at" class="pc-fact" style="color: var(--danger);">
                            Cancels <strong>{{ sub.cancels_at }}</strong>
                        </span>
                        <span v-if="sub.trial_ends_at && sub.status === 'trial'" class="pc-fact">
                            Trial ends <strong>{{ sub.trial_ends_at }}</strong>
                        </span>
                    </div>
                </div>
                <div class="portal-sub-actions">
                    <button
                        v-if="sub.status === 'active' || sub.status === 'trial'"
                        type="button"
                        class="ghost-link danger"
                        @click="askCancel(sub)"
                    >
                        Cancel subscription
                    </button>
                </div>
            </article>
        </div>

        <!-- AVAILABLE PRODUCTS -->
        <div class="portal-section-head" style="margin-top: 40px;">
            <div class="col-l">
                <h2>Add a product</h2>
                <div class="desc">Sign up for additional Whitedash products — we'll activate within one business day.</div>
            </div>
        </div>

        <div v-if="available.length === 0" class="portal-empty-inline">
            <span>You've subscribed to every product available — well done.</span>
        </div>

        <div v-else class="portal-product-grid">
            <article v-for="product in available" :key="product.id" class="portal-product-card">
                <div class="pc-top">
                    <div
                        class="pc-logo"
                        :style="{ background: product.icon_colour || 'var(--accent)' }"
                    >{{ (product.name || '?').charAt(0).toUpperCase() }}</div>
                </div>
                <div class="pc-name">{{ product.name }}</div>
                <div class="pc-desc">
                    {{ product.plans.length }} {{ product.plans.length === 1 ? 'plan' : 'plans' }} available
                </div>
                <div class="pc-divider" />
                <div class="pc-card-foot">
                    <button
                        type="button"
                        class="btn btn-primary btn-block"
                        :disabled="product.plans.length === 0"
                        @click="openSubscribe(product)"
                    >
                        <IconPlus :size="14" stroke-width="1.75" />
                        Subscribe
                    </button>
                </div>
            </article>
        </div>

        <!-- SUBSCRIBE DIALOG -->
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
                                <input
                                    type="radio"
                                    :value="plan.id"
                                    :checked="selectedPlanId === plan.id"
                                    @change="selectPlan(plan)"
                                >
                                <div class="portal-plan-info">
                                    <div class="portal-plan-name">{{ plan.name }}</div>
                                    <div v-if="plan.description" class="portal-plan-desc">{{ plan.description }}</div>
                                </div>
                            </label>
                        </div>

                        <div v-if="currentPlan && currentPlan.prices.length > 0" class="portal-modal-section-title" style="margin-top: 20px;">
                            Choose billing interval
                        </div>
                        <div v-if="currentPlan" class="portal-price-list">
                            <label
                                v-for="price in currentPlan.prices"
                                :key="price.id"
                                class="portal-price-row"
                                :class="{ active: selectedPriceId === price.id }"
                            >
                                <input
                                    type="radio"
                                    :value="price.id"
                                    :checked="selectedPriceId === price.id"
                                    @change="selectedPriceId = price.id"
                                >
                                <div class="portal-price-amount">{{ gbp(price.price) }}</div>
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
                    <button
                        type="button"
                        class="btn btn-primary"
                        :disabled="! selectedPlanId || ! selectedPriceId || subscribeForm.processing"
                        @click="submitSubscribe"
                    >
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
    </PortalLayout>
</template>
