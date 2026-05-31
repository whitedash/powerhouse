<script setup>
/**
 * Proposal detail — preview + actions + payment schedule editor.
 *
 * Layout: 60/40 split. Left pane renders the proposal document
 * (styled to echo the PDF without being a perfect pixel match);
 * right pane carries status + actions + the optional payment
 * schedule slide-over.
 */
import { computed, ref } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import {
    IconX, IconDownload, IconSend, IconCopy, IconCheck,
    IconCircleCheck, IconAlertTriangle, IconPlus, IconFileDescription,
    IconReceipt, IconExternalLink,
} from '@tabler/icons-vue';
import InternalLayout from '@/Layouts/InternalLayout.vue';

const props = defineProps({
    proposal: { type: Object, required: true },
    milestones: { type: Array, default: () => [] },
    billing_entities: { type: Array, default: () => [] },
});

const STATUS_BADGE = {
    draft: 'badge-inactive', sent: 'badge-pending',
    accepted: 'badge-active', rejected: 'badge-danger',
    expired: 'badge-inactive',
};

function money(n) {
    return `£${Number(n || 0).toLocaleString('en-GB', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
}

const validUntilStyle = computed(() => {
    if (! props.proposal.valid_until_raw) return '';
    const now = new Date();
    const until = new Date(props.proposal.valid_until_raw);
    const diffDays = Math.floor((until - now) / 86400000);
    if (diffDays < 0) return 'text-danger';
    if (diffDays <= 7) return 'text-warning';
    return '';
});

function downloadPdf() { window.open(`/proposals/${props.proposal.id}/pdf`, '_blank'); }
function downloadAcceptedPdf() { window.open(`/proposals/${props.proposal.id}/accepted-pdf`, '_blank'); }
function sendProposal() { router.post(`/proposals/${props.proposal.id}/send`, {}); }
function convertToContract() { router.post(`/proposals/${props.proposal.id}/convert`, {}); }

/* ─── Copy acceptance link ─── */
const linkCopied = ref(false);
function copyAcceptanceLink() {
    if (! props.proposal.acceptance_token) return;
    const url = `${window.location.origin}/proposals/accept/${props.proposal.acceptance_token}`;
    navigator.clipboard.writeText(url).then(() => {
        linkCopied.value = true;
        setTimeout(() => { linkCopied.value = false; }, 2000);
    });
}

/* ─── Payment schedule slide-over ─── */
const showSchedule = ref(false);
const schedule = props.proposal.payment_schedule;
const scheduleForm = useForm({
    name: schedule?.name ?? (props.proposal.title + ' — payment schedule'),
    proposal_id: props.proposal.id,
    project_id: props.proposal.project?.id ?? null,
    customer_id: props.proposal.customer.id,
    billing_entity_id: props.proposal.billing_entity?.id ?? null,
    total: schedule?.total ?? props.proposal.total,
    notes: '',
    items: schedule?.items?.map(i => ({
        label: i.label,
        percentage: i.percentage ?? '',
        amount: i.amount,
        trigger_type: i.trigger_type,
        trigger_date: '',
        milestone_id: i.milestone?.id ?? null,
    })) ?? [
        { label: 'Deposit on signing', percentage: 40, amount: Math.round(props.proposal.total * 0.4 * 100) / 100, trigger_type: 'immediate', trigger_date: '', milestone_id: null },
    ],
});

function openScheduleEditor() { showSchedule.value = true; }
function addScheduleItem() {
    scheduleForm.items.push({ label: '', percentage: '', amount: 0, trigger_type: 'manual', trigger_date: '', milestone_id: null });
}
function removeScheduleItem(i) {
    if (scheduleForm.items.length > 1) scheduleForm.items.splice(i, 1);
}
function onPercentageChange(i) {
    const pct = Number(scheduleForm.items[i].percentage);
    if (pct > 0) {
        scheduleForm.items[i].amount = Math.round(Number(scheduleForm.total) * (pct / 100) * 100) / 100;
    }
}

const itemsSum = computed(() => scheduleForm.items.reduce((s, i) => s + Number(i.amount || 0), 0));
const percentSum = computed(() => scheduleForm.items.reduce((s, i) => s + (Number(i.percentage) || 0), 0));

function submitSchedule() {
    scheduleForm.post('/payment-schedules', {
        preserveScroll: false,
        onSuccess: () => { showSchedule.value = false; },
    });
}

function triggerItem(itemId) {
    router.post(`/payment-schedules/items/${itemId}/trigger`, {}, { preserveScroll: false });
}
</script>

<template>
    <Head :title="proposal.reference" />

    <InternalLayout
        :title="proposal.reference"
        active-nav="proposals"
        :breadcrumbs="[
            { label: 'Powerhouse', href: '/' },
            { label: 'Proposals', href: '/proposals' },
            { label: proposal.reference },
        ]"
    >
        <div class="proposals-show">
            <div class="proposal-grid">
                <!-- LEFT — document preview -->
                <div class="proposal-doc">
                    <div class="proposal-doc-head">
                        <div>
                            <div class="muted small">{{ proposal.billing_entity?.name ?? '—' }}</div>
                            <h1 class="doc-title">PROPOSAL</h1>
                            <div class="doc-ref"><strong>{{ proposal.reference }}</strong> · {{ proposal.created_at }}</div>
                        </div>
                    </div>

                    <div class="prepared-for">
                        <div class="muted small">PREPARED FOR</div>
                        <div class="prepared-name">{{ proposal.customer.name }}</div>
                        <div v-if="proposal.customer.primary_contact" class="muted small">Attn: {{ proposal.customer.primary_contact }}</div>
                    </div>

                    <div v-if="proposal.description" class="doc-section">
                        <h3 class="doc-section-h">Overview</h3>
                        <p>{{ proposal.description }}</p>
                    </div>

                    <h3 class="doc-section-h">Items</h3>
                    <table class="doc-lines">
                        <thead>
                            <tr>
                                <th>Description</th>
                                <th class="num">Qty</th>
                                <th class="num">Unit</th>
                                <th class="num">Disc</th>
                                <th class="num">Amount</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="line in proposal.lines" :key="line.id">
                                <td>
                                    <strong>{{ line.description }}</strong>
                                    <div v-if="line.note" class="muted small">{{ line.note }}</div>
                                </td>
                                <td class="num">{{ line.quantity }}</td>
                                <td class="num">{{ money(line.unit_price) }}</td>
                                <td class="num">{{ line.discount_amount > 0 ? `-${money(line.discount_amount)}` : '—' }}</td>
                                <td class="num"><strong>{{ money(line.amount) }}</strong></td>
                            </tr>
                        </tbody>
                    </table>

                    <div class="doc-totals">
                        <div v-if="proposal.discount_amount > 0" class="doc-totals-row" style="color: var(--success);">
                            <span>Discounts</span><strong>-{{ money(proposal.discount_amount) }}</strong>
                        </div>
                        <div class="doc-totals-row"><span>Subtotal</span><strong>{{ money(proposal.subtotal) }}</strong></div>
                        <div v-if="proposal.vat_amount > 0" class="doc-totals-row"><span>VAT ({{ proposal.vat_rate }}%)</span><strong>{{ money(proposal.vat_amount) }}</strong></div>
                        <div class="doc-totals-row grand"><span>TOTAL</span><strong>{{ money(proposal.total) }}</strong></div>
                    </div>

                    <div v-if="proposal.terms" class="doc-section">
                        <h3 class="doc-section-h">Terms &amp; Conditions</h3>
                        <p class="muted small" style="white-space: pre-line;">{{ proposal.terms }}</p>
                    </div>

                    <div v-if="proposal.status === 'accepted'" class="accept-stamp-card">
                        <IconCircleCheck :size="22" stroke-width="2" />
                        <div>
                            <strong>Accepted by {{ proposal.accepted_by_name }}</strong>
                            <div class="muted small">{{ proposal.accepted_at }} from {{ proposal.accepted_ip }}</div>
                        </div>
                    </div>
                </div>

                <!-- RIGHT — actions panel -->
                <div class="proposal-side">
                    <!-- Status card -->
                    <div class="card">
                        <div class="card-head"><h3>Status</h3></div>
                        <div class="card-body">
                            <div class="status-stack">
                                <span class="badge lg" :class="STATUS_BADGE[proposal.status]">{{ proposal.status_label }}</span>
                            </div>
                            <div class="meta-stack">
                                <div><span class="muted small">Total:</span> <strong>{{ money(proposal.total) }}</strong></div>
                                <div><span class="muted small">Reference:</span> {{ proposal.reference }}</div>
                                <div v-if="proposal.valid_until"><span class="muted small">Valid until:</span> <span :class="validUntilStyle">{{ proposal.valid_until }}</span></div>
                            </div>

                            <div class="actions-stack">
                                <button v-if="proposal.status === 'draft'" type="button" class="btn btn-primary" @click="sendProposal">
                                    <IconSend :size="14" stroke-width="2" /> Send proposal
                                </button>
                                <button type="button" class="btn btn-ghost" @click="downloadPdf">
                                    <IconDownload :size="14" stroke-width="2" /> Download PDF
                                </button>
                                <button v-if="proposal.status === 'sent' && proposal.acceptance_token" type="button" class="btn btn-ghost" @click="copyAcceptanceLink">
                                    <IconCopy :size="14" stroke-width="2" />
                                    {{ linkCopied ? 'Copied!' : 'Copy acceptance link' }}
                                </button>
                                <button v-if="proposal.status === 'accepted' && proposal.has_accepted_pdf" type="button" class="btn btn-ghost" @click="downloadAcceptedPdf">
                                    <IconDownload :size="14" stroke-width="2" /> Signed PDF
                                </button>
                                <button v-if="proposal.status === 'accepted' && !proposal.contract" type="button" class="btn btn-primary" @click="convertToContract">
                                    <IconCheck :size="14" stroke-width="2" /> Convert to contract
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Payment schedule card -->
                    <div class="card">
                        <div class="card-head">
                            <h3>Payment schedule</h3>
                            <button type="button" class="ghost-link" @click="openScheduleEditor">
                                {{ proposal.payment_schedule ? 'Edit' : '+ Add' }}
                            </button>
                        </div>
                        <div v-if="proposal.payment_schedule" class="card-body">
                            <div v-for="item in proposal.payment_schedule.items" :key="item.id" class="sched-row">
                                <div class="sched-label">
                                    <div><strong>{{ item.label }}</strong></div>
                                    <div class="muted small">
                                        <template v-if="item.trigger_type === 'immediate'">On acceptance</template>
                                        <template v-else-if="item.trigger_type === 'on_milestone'">When {{ item.milestone?.title ?? 'milestone' }} completes</template>
                                        <template v-else-if="item.trigger_type === 'on_date'">On {{ item.trigger_date }}</template>
                                        <template v-else>Manual</template>
                                    </div>
                                </div>
                                <div class="sched-amount">{{ money(item.amount) }}</div>
                                <div class="sched-status">
                                    <Link v-if="item.invoice" :href="`/invoices/${item.invoice.id}`" class="ghost-link inline">
                                        <IconReceipt :size="13" stroke-width="2" />
                                        {{ item.invoice.number }}
                                    </Link>
                                    <button v-else-if="item.is_triggerable" type="button" class="btn btn-ghost btn-sm" @click="triggerItem(item.id)">
                                        Invoice now
                                    </button>
                                    <span v-else class="badge badge-inactive sm">{{ item.status }}</span>
                                </div>
                            </div>
                        </div>
                        <div v-else class="card-body muted center">
                            <IconReceipt :size="24" stroke-width="1.5" />
                            <div>No payment schedule attached.</div>
                        </div>
                    </div>

                    <!-- Linked records -->
                    <div class="card">
                        <div class="card-head"><h3>Linked</h3></div>
                        <div class="card-body links-stack">
                            <div>
                                <span class="muted small">Customer:</span>
                                <Link :href="`/customers/${proposal.customer.id}`" class="ghost-link inline">
                                    {{ proposal.customer.name }} <IconExternalLink :size="11" stroke-width="2" />
                                </Link>
                            </div>
                            <div v-if="proposal.project">
                                <span class="muted small">Project:</span>
                                <Link :href="`/projects/${proposal.project.id}`" class="ghost-link inline">
                                    {{ proposal.project.title }} <IconExternalLink :size="11" stroke-width="2" />
                                </Link>
                            </div>
                            <div v-if="proposal.contract">
                                <span class="muted small">Contract:</span>
                                <span class="ghost-link inline">{{ proposal.contract.title }} ({{ proposal.contract.status }})</span>
                            </div>
                            <div v-if="proposal.created_by">
                                <span class="muted small">Created by:</span> {{ proposal.created_by.name }}
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Payment schedule slide-over -->
        <Teleport to="body">
            <div v-if="showSchedule" class="slide-over-overlay" @click.self="showSchedule = false">
                <div class="slide-over" style="width: 600px;">
                    <div class="slide-over-head">
                        <h2>{{ proposal.payment_schedule ? 'Edit' : 'New' }} payment schedule</h2>
                        <button type="button" class="icon-btn" @click="showSchedule = false">
                            <IconX :size="18" stroke-width="2" />
                        </button>
                    </div>
                    <form @submit.prevent="submitSchedule" class="slide-over-body">
                        <div class="form-row-2">
                            <div class="form-section">
                                <label class="form-label">Name</label>
                                <input v-model="scheduleForm.name" type="text" class="form-input" required />
                            </div>
                            <div class="form-section">
                                <label class="form-label">Total</label>
                                <input v-model.number="scheduleForm.total" type="number" min="0" step="0.01" class="form-input" />
                            </div>
                        </div>

                        <div class="form-section">
                            <label class="form-label">Schedule items</label>
                            <div v-for="(item, i) in scheduleForm.items" :key="i" class="sched-edit-row">
                                <input v-model="item.label" type="text" class="form-input" placeholder="e.g. 40% Deposit on signing" />
                                <input v-model.number="item.percentage" type="number" min="0" max="100" step="0.01" class="form-input pct" placeholder="%" @input="onPercentageChange(i)" />
                                <input v-model.number="item.amount" type="number" min="0" step="0.01" class="form-input amt" />
                                <select v-model="item.trigger_type" class="form-input trig">
                                    <option value="immediate">On acceptance</option>
                                    <option value="on_date">On date</option>
                                    <option value="on_milestone">On milestone</option>
                                    <option value="manual">Manual</option>
                                </select>
                                <input v-if="item.trigger_type === 'on_date'" v-model="item.trigger_date" type="date" class="form-input" />
                                <select v-if="item.trigger_type === 'on_milestone'" v-model="item.milestone_id" class="form-input">
                                    <option :value="null">Pick milestone…</option>
                                    <option v-for="m in milestones" :key="m.id" :value="m.id">{{ m.title }}</option>
                                </select>
                                <button type="button" class="icon-btn xs danger" :disabled="scheduleForm.items.length <= 1" @click="removeScheduleItem(i)">
                                    <IconX :size="13" stroke-width="2" />
                                </button>
                            </div>
                            <button type="button" class="ghost-link" @click="addScheduleItem">
                                <IconPlus :size="13" stroke-width="2" /> Add item
                            </button>
                        </div>

                        <div class="sched-sum">
                            <div :class="{ 'text-danger': percentSum > 0 && Math.abs(percentSum - 100) > 0.01 }">
                                Percentages: <strong>{{ percentSum.toFixed(2) }}%</strong>
                                <span v-if="percentSum > 0 && Math.abs(percentSum - 100) > 0.01" class="muted small"> (should sum to 100%)</span>
                            </div>
                            <div :class="{ 'text-danger': Math.abs(itemsSum - Number(scheduleForm.total)) > 0.01 }">
                                Item total: <strong>{{ money(itemsSum) }}</strong> of {{ money(scheduleForm.total) }}
                            </div>
                        </div>
                    </form>
                    <div class="slide-over-foot">
                        <button type="button" class="btn btn-ghost" @click="showSchedule = false">Cancel</button>
                        <button type="button" class="btn btn-primary" :disabled="scheduleForm.processing" @click="submitSchedule">
                            Save schedule
                        </button>
                    </div>
                </div>
            </div>
        </Teleport>
    </InternalLayout>
</template>
