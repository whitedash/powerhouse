<script setup>
/**
 * Public proposal acceptance page — NO InternalLayout, no auth.
 *
 * The visitor arrives via a one-time token URL. The accept modal
 * collects (1) a printed name as legal confirmation of identity
 * and (2) a tick-box that "I have read and agree". Both are
 * required server-side via accepted_name + accepted_confirm.
 *
 * The acceptance creates a binding audit trail (timestamp, IP,
 * user agent) on the server side; the visitor only sees the
 * success page render once the POST returns.
 */
import { computed, ref } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import { IconCheck, IconX, IconFileDescription } from '@tabler/icons-vue';

const props = defineProps({
    proposal: { type: Object, required: true },
    token: { type: String, required: true },
    expires_at: { type: String, default: null },
});

function money(n) {
    return `£${Number(n || 0).toLocaleString('en-GB', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
}

const showModal = ref(false);
const form = useForm({
    accepted_name: '',
    accepted_confirm: false,
});

function openAccept() {
    form.reset();
    form.clearErrors();
    showModal.value = true;
}

const canSubmit = computed(() =>
    form.accepted_name.trim().length > 0 && form.accepted_confirm,
);

function submit() {
    if (! canSubmit.value) return;
    form.post(`/proposals/accept/${props.token}`, {
        preserveScroll: false,
        // Public page — no toast container, server returns
        // ProposalAccepted directly.
    });
}
</script>

<template>
    <Head :title="`Proposal ${proposal.reference}`" />

    <div class="public-proposal">
        <!-- Branded header -->
        <header class="pp-header">
            <div class="pp-header-inner">
                <span class="pp-brand-mark">W</span>
                <div>
                    <div class="pp-entity-name">{{ proposal.entity_name ?? 'Whitedash' }}</div>
                    <div class="pp-tagline">A proposal for {{ proposal.customer_name }}</div>
                </div>
            </div>
        </header>

        <main class="pp-doc">
            <div class="pp-doc-head">
                <div>
                    <h1>PROPOSAL</h1>
                    <div class="pp-ref"><strong>{{ proposal.reference }}</strong></div>
                </div>
                <div v-if="expires_at" class="pp-expiry muted small">
                    Valid until {{ expires_at }}
                </div>
            </div>

            <h2 class="pp-title">{{ proposal.title }}</h2>

            <div v-if="proposal.description" class="pp-overview">
                <h3>Overview</h3>
                <p>{{ proposal.description }}</p>
            </div>

            <h3>Items</h3>
            <table class="pp-lines">
                <thead>
                    <tr>
                        <th>Description</th>
                        <th class="num">Qty</th>
                        <th class="num">Unit</th>
                        <th class="num">Amount</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="(line, i) in proposal.lines" :key="i">
                        <td>
                            <strong>{{ line.description }}</strong>
                            <div v-if="line.note" class="muted small">{{ line.note }}</div>
                        </td>
                        <td class="num">{{ line.quantity }}</td>
                        <td class="num">{{ money(line.unit_price) }}</td>
                        <td class="num"><strong>{{ money(line.amount) }}</strong></td>
                    </tr>
                </tbody>
            </table>

            <div class="pp-totals">
                <div v-if="proposal.discount_amount > 0" class="pp-totals-row" style="color: var(--success);">
                    <span>Discounts</span><strong>-{{ money(proposal.discount_amount) }}</strong>
                </div>
                <div class="pp-totals-row"><span>Subtotal</span><strong>{{ money(proposal.subtotal) }}</strong></div>
                <div v-if="proposal.entity_vat_registered && proposal.vat_amount > 0" class="pp-totals-row">
                    <span>VAT ({{ proposal.vat_rate }}%)</span><strong>{{ money(proposal.vat_amount) }}</strong>
                </div>
                <div class="pp-totals-row grand"><span>TOTAL</span><strong>{{ money(proposal.total) }}</strong></div>
            </div>

            <div v-if="proposal.schedule" class="pp-schedule">
                <h3>Payment schedule</h3>
                <table class="pp-lines">
                    <thead>
                        <tr><th>Milestone / Stage</th><th class="num">Amount</th><th>Trigger</th></tr>
                    </thead>
                    <tbody>
                        <tr v-for="(item, i) in proposal.schedule.items" :key="i">
                            <td>{{ item.label }}</td>
                            <td class="num"><strong>{{ money(item.amount) }}</strong></td>
                            <td class="muted small">
                                <template v-if="item.trigger_type === 'immediate'">On acceptance</template>
                                <template v-else-if="item.trigger_type === 'on_date'">On {{ item.trigger_date }}</template>
                                <template v-else-if="item.trigger_type === 'on_milestone'">When {{ item.milestone_title ?? 'milestone' }} completes</template>
                                <template v-else>Manual invoice</template>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div v-if="proposal.terms" class="pp-terms">
                <h3>Terms &amp; Conditions</h3>
                <div class="pp-terms-body">{{ proposal.terms }}</div>
            </div>
        </main>

        <!-- Sticky accept banner -->
        <div class="pp-accept-banner">
            <div class="pp-ab-info">
                <strong>{{ proposal.reference }}</strong>
                <span class="muted">·</span>
                <strong>{{ money(proposal.total) }}</strong>
                <span v-if="expires_at" class="muted small"> · Valid until {{ expires_at }}</span>
            </div>
            <button type="button" class="btn-accept" @click="openAccept">
                <IconCheck :size="16" stroke-width="2" />
                Accept this proposal
            </button>
        </div>

        <!-- Accept modal -->
        <Teleport to="body">
            <div v-if="showModal" class="pp-modal-overlay" @click.self="showModal = false">
                <div class="pp-modal">
                    <div class="pp-modal-head">
                        <h2>Accept {{ proposal.title }}</h2>
                        <button type="button" class="icon-btn" @click="showModal = false">
                            <IconX :size="18" stroke-width="2" />
                        </button>
                    </div>
                    <form @submit.prevent="submit" class="pp-modal-body">
                        <p class="pp-modal-intro">
                            By accepting this proposal, you confirm your agreement to
                            the above scope of work, pricing, and terms &amp; conditions.
                        </p>

                        <div class="pp-modal-summary">
                            <div><span class="muted small">Total</span><strong>{{ money(proposal.total) }}</strong></div>
                            <div v-if="proposal.schedule">
                                <span class="muted small">Payment</span>
                                <strong>{{ proposal.schedule.items.length }} installment{{ proposal.schedule.items.length === 1 ? '' : 's' }}</strong>
                            </div>
                        </div>

                        <div class="form-section">
                            <label class="form-label">Your full name <span class="req">*</span></label>
                            <input
                                v-model="form.accepted_name"
                                type="text"
                                class="form-input lg"
                                placeholder="e.g. Jane Smith"
                                required
                                autofocus
                            />
                            <p v-if="form.errors.accepted_name" class="form-error">{{ form.errors.accepted_name }}</p>
                        </div>

                        <label class="pp-confirm">
                            <input type="checkbox" v-model="form.accepted_confirm" />
                            <span>I have read and agree to the terms and conditions above.</span>
                        </label>
                        <p v-if="form.errors.accepted_confirm" class="form-error">{{ form.errors.accepted_confirm }}</p>

                        <p class="muted small pp-legal">
                            Your acceptance will be recorded with your name, timestamp,
                            and IP address as legally binding confirmation.
                        </p>
                    </form>
                    <div class="pp-modal-foot">
                        <button type="button" class="btn btn-ghost" @click="showModal = false">Cancel</button>
                        <button type="button" class="btn-accept" :disabled="! canSubmit || form.processing" @click="submit">
                            {{ form.processing ? 'Accepting…' : 'Accept proposal' }}
                        </button>
                    </div>
                </div>
            </div>
        </Teleport>
    </div>
</template>
