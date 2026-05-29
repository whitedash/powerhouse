<script setup>
import { computed, ref } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import {
    Dialog,
    DialogPanel,
    TransitionChild,
    TransitionRoot,
} from '@headlessui/vue';
import {
    IconUsers,
    IconCurrencyPound,
    IconReceipt,
    IconHeadset,
    IconCalendarX,
    IconTrendingUp,
    IconAlertTriangle,
    IconAlertCircle,
    IconAlarm,
    IconLayoutGrid,
    IconActivity,
    IconArrowRight,
    IconCheckbox,
    IconChartBar,
    IconUsersGroup,
    IconServer,
    IconDownload,
    IconPlus,
    IconDots,
    IconUserPlus,
    IconCircleCheck,
    IconCircleCheckFilled,
    IconBan,
    IconLock,
    IconShieldX,
    IconBuilding,
    IconMessage2,
    IconFilePlus,
    IconSend,
    IconX,
    IconSearch,
} from '@tabler/icons-vue';
import InternalLayout from '@/Layouts/InternalLayout.vue';

const props = defineProps({
    greeting: { type: String, required: true },
    today: { type: String, required: true },
    stats: { type: Object, required: true },
    products: { type: Array, default: () => [] },
    attention: { type: Array, default: () => [] },
    attention_count: { type: Number, default: 0 },
    activity: { type: Array, default: () => [] },
    tasks: { type: Array, default: () => [] },
    this_month: { type: Object, required: true },
    referrers: { type: Array, default: () => [] },
    total_pending_commissions: { type: Number, default: 0 },
    platform_health: { type: Array, default: () => [] },
    customers: { type: Array, default: () => [] },
    assignable_users: { type: Array, default: () => [] },
});

const breadcrumbs = [{ label: 'Overview' }];

/* ─── Formatting helpers ─── */
function gbp(n, dp = 0) {
    return '£' + Number(n || 0).toLocaleString('en-GB', { minimumFractionDigits: dp, maximumFractionDigits: dp });
}
function n(v) { return Number(v || 0).toLocaleString('en-GB'); }

/* ─── Greeting sub line ─── */
const subLine = computed(() => {
    const tail = props.attention_count > 0
        ? `${props.attention_count} thing${props.attention_count === 1 ? '' : 's'} need your attention today.`
        : 'Everything looks good today.';

    return `${props.today} · ${tail}`;
});

/* ─── KPI helpers ─── */
const newCustomersThisMonth = computed(() => props.this_month?.new_customers ?? 0);
const totalMrr = computed(() => props.products.reduce((sum, p) => sum + Number(p.mrr || 0), 0));

/* ─── Products ─── */
const PROD_CLASS = { maavelus: 'maa', myorderpad: 'mop', whitedash: 'wdb', smscube: 'sms' };
function prodClass(p) {
    return PROD_CLASS[p.slug] ?? 'maa';
}
function prodInitial(p) {
    return (p.name?.[0] ?? '?').toUpperCase();
}
function viewProductHref(p) {
    return p.is_coming_soon ? '/settings' : `/customers?product=${p.slug}`;
}

const activeProductCount = computed(() => props.products.filter((p) => !p.is_coming_soon).length);
const comingSoonCount = computed(() => props.products.filter((p) => p.is_coming_soon).length);

/* ─── Activity icon mapping ─── */
const ACT_ICON_MAP = {
    'customer.created': { icon: IconUserPlus, cls: 'gold' },
    'customer.updated': { icon: IconUsers, cls: 'neutral' },
    'customer.archived': { icon: IconBan, cls: 'neutral' },
    'customer.note_added': { icon: IconMessage2, cls: 'neutral' },
    'customer.task_added': { icon: IconCheckbox, cls: 'neutral' },
    'invoice.created': { icon: IconFilePlus, cls: 'blue' },
    'invoice.sent': { icon: IconSend, cls: 'blue' },
    'invoice.updated': { icon: IconReceipt, cls: 'neutral' },
    'invoice.marked_paid': { icon: IconCircleCheck, cls: 'green' },
    'invoice.voided': { icon: IconBan, cls: 'red' },
    'invoice.pdf_downloaded': { icon: IconDownload, cls: 'neutral' },
    'invoice.reminder_sent': { icon: IconSend, cls: 'blue' },
    'invoice.reminders_paused': { icon: IconReceipt, cls: 'neutral' },
    'invoice.reminders_resumed': { icon: IconReceipt, cls: 'neutral' },
    'invoice.pdf_previewed': { icon: IconReceipt, cls: 'neutral' },
    'auth.login': { icon: IconLock, cls: 'neutral' },
    'auth.logout': { icon: IconLock, cls: 'neutral' },
    'auth.failed': { icon: IconAlertCircle, cls: 'red' },
    'auth.password_reset': { icon: IconLock, cls: 'neutral' },
    'billing_entity.created': { icon: IconBuilding, cls: 'neutral' },
    'billing_entity.updated': { icon: IconBuilding, cls: 'neutral' },
    'security.mass_export_detected': { icon: IconShieldX, cls: 'red' },
};
const DEFAULT_ACT = { icon: IconActivity, cls: 'neutral' };
function actIcon(a) { return (ACT_ICON_MAP[a.action] ?? DEFAULT_ACT).icon; }
function actCls(a) { return (ACT_ICON_MAP[a.action] ?? DEFAULT_ACT).cls; }
function actSub(a) {
    const after = a.after ?? {};
    if (a.action === 'invoice.created' && after.total) return `£${Number(after.total).toFixed(2)}`;
    if (a.action === 'invoice.marked_paid' && after.amount) return `£${Number(after.amount).toFixed(2)}`;
    if (a.action === 'invoice.sent' && after.number) return after.number;
    if (a.action === 'customer.created' && after.name) return after.name;
    if (a.action === 'customer.archived' && after.name) return after.name;
    if (a.action === 'billing_entity.updated' && after.name) return after.name;

    return null;
}

/* ─── Tasks ─── */
function taskDueLabel(t) {
    if (!t.due_date) return { text: 'No date', cls: 'empty' };
    if (t.is_due_today) return { text: 'Due today', cls: 'amber' };
    if (t.is_overdue) return { text: 'Overdue', cls: 'red' };
    const d = new Date(t.due_date);

    return { text: d.toLocaleDateString('en-GB', { day: 'numeric', month: 'short' }), cls: 'muted' };
}
const tasksDueToday = computed(() => props.tasks.filter((t) => t.is_due_today).length);

/* ─── Referrer avatars ─── */
const AV_PALETTE = ['av-1', 'av-2', 'av-3', 'av-5', 'av-amber', 'av-teal'];
function avClass(i) { return AV_PALETTE[i % AV_PALETTE.length]; }
function initials(name) {
    const parts = String(name || '').trim().split(/\s+/);

    return ((parts[0]?.[0] ?? '') + (parts[1]?.[0] ?? '')).toUpperCase() || '?';
}

/* ─── Platform health ─── */
const healthOk = computed(() => props.platform_health.every((s) => s.is_coming_soon || (s.uptime ?? 0) >= 99));

/* ─── Topbar actions ─── */
function exportReport() {
    /* stub — wired in a later sprint */
}
function goNewCustomer() {
    router.visit('/customers');
}

/* ─── New-task slide-over ─── */
const showNewTask = ref(false);
const customerSearch = ref('');
const todayIso = new Date().toISOString().slice(0, 10);

const me = computed(() => {
    // The InternalLayout shares the auth user via $page.props; fall back
    // to the first assignable user so the select is never empty.
    const u = props.assignable_users.find((u) => u.role === 'super_admin') ?? props.assignable_users[0];

    return u?.id ?? null;
});

const taskForm = useForm({
    title: '',
    due_date: '',
    customer_id: null,
    assigned_to: null,
});

function openNewTask() {
    taskForm.reset();
    taskForm.clearErrors();
    taskForm.assigned_to = me.value;
    customerSearch.value = '';
    showNewTask.value = true;
}

function submitNewTask() {
    taskForm.post('/tasks', {
        preserveScroll: true,
        onSuccess: () => {
            showNewTask.value = false;
        },
    });
}

const filteredCustomers = computed(() => {
    const q = customerSearch.value.trim().toLowerCase();
    if (! q) return props.customers.slice(0, 8);

    return props.customers
        .filter((c) => c.name.toLowerCase().includes(q))
        .slice(0, 8);
});

function pickCustomer(c) {
    taskForm.customer_id = c.id;
    customerSearch.value = c.name;
}

function clearCustomer() {
    taskForm.customer_id = null;
    customerSearch.value = '';
}

/* ─── Complete a task (optimistic dim then refresh) ─── */
const completingId = ref(null);

function completeTask(taskId) {
    if (completingId.value === taskId) return;
    completingId.value = taskId;
    router.post(`/tasks/${taskId}/complete`, {}, {
        preserveScroll: true,
        onFinish: () => {
            completingId.value = null;
        },
    });
}
</script>

<template>
    <Head title="Overview" />

    <InternalLayout title="Overview" :breadcrumbs="breadcrumbs" active-nav="overview">
        <template #topbar-actions>
            <button type="button" class="btn btn-secondary" @click="exportReport">
                <IconDownload :size="15" stroke-width="1.75" />
                Export report
            </button>
            <button type="button" class="btn btn-primary" @click="goNewCustomer">
                <IconPlus :size="15" stroke-width="1.75" />
                New customer
            </button>
        </template>

        <div class="dashboard">
            <!-- ═══ Greeting ═══ -->
            <div class="greet">
                <div>
                    <h1>{{ greeting }}</h1>
                    <div class="sub">{{ subLine }}</div>
                </div>
            </div>

            <!-- ═══ KPI cards ═══ -->
            <div class="kpi-row">
                <!-- Active accounts -->
                <div class="kpi">
                    <div class="kpi-top">
                        <div class="kpi-icon"><IconUsers :size="18" stroke-width="1.75" /></div>
                    </div>
                    <div class="kpi-mid">
                        <div class="kpi-value">{{ n(stats.total_customers) }}</div>
                        <div class="kpi-label">Active accounts</div>
                    </div>
                    <div class="kpi-foot">
                        <span v-if="newCustomersThisMonth > 0" class="trend-pill up">
                            <IconTrendingUp :size="13" stroke-width="2" />+{{ newCustomersThisMonth }}
                        </span>
                        <span class="kpi-sub">{{ newCustomersThisMonth > 0 ? 'this month' : 'no new accounts this month' }}</span>
                    </div>
                </div>

                <!-- MRR -->
                <div class="kpi">
                    <div class="kpi-top">
                        <div class="kpi-icon"><IconCurrencyPound :size="18" stroke-width="1.75" /></div>
                    </div>
                    <div class="kpi-mid">
                        <div class="kpi-value">{{ gbp(stats.mrr) }}</div>
                        <div class="kpi-label">MRR · all products</div>
                    </div>
                    <div class="kpi-foot">
                        <span v-if="stats.mrr > 0" class="kpi-sub">across {{ activeProductCount }} active products</span>
                        <span v-else class="kpi-sub">no active subscriptions yet</span>
                    </div>
                </div>

                <!-- Awaiting payment -->
                <div class="kpi">
                    <div class="kpi-top">
                        <div class="kpi-icon"><IconReceipt :size="18" stroke-width="1.75" /></div>
                    </div>
                    <div class="kpi-mid">
                        <div class="kpi-value">{{ n(stats.pending_invoices_count) }}</div>
                        <div class="kpi-label">Awaiting payment</div>
                    </div>
                    <div class="kpi-foot">
                        <span
                            class="trend-pill"
                            :class="stats.pending_invoices_amount > 0 ? 'warn' : 'up'"
                        >
                            <IconAlertTriangle v-if="stats.pending_invoices_amount > 0" :size="13" stroke-width="2" />
                            <IconCircleCheck v-else :size="13" stroke-width="2" />
                            {{ gbp(stats.pending_invoices_amount) }}
                        </span>
                        <span class="kpi-sub">outstanding</span>
                    </div>
                </div>

                <!-- Need attention -->
                <div class="kpi">
                    <div class="kpi-top">
                        <div class="kpi-icon"><IconHeadset :size="18" stroke-width="1.75" /></div>
                    </div>
                    <div class="kpi-mid">
                        <div class="kpi-value">{{ n(stats.open_tickets_count) }}</div>
                        <div class="kpi-label">Need attention</div>
                    </div>
                    <div class="kpi-foot">
                        <span
                            class="trend-pill"
                            :class="stats.overdue_sla_count > 0 ? 'red' : 'up'"
                        >
                            <IconAlertCircle v-if="stats.overdue_sla_count > 0" :size="13" stroke-width="2" />
                            <IconCircleCheck v-else :size="13" stroke-width="2" />
                            {{ stats.overdue_sla_count > 0 ? `${stats.overdue_sla_count} overdue` : 'all in SLA' }}
                        </span>
                        <span class="kpi-sub">SLA</span>
                    </div>
                </div>

                <!-- Domains / SSL -->
                <div class="kpi">
                    <div class="kpi-top">
                        <div class="kpi-icon"><IconCalendarX :size="18" stroke-width="1.75" /></div>
                    </div>
                    <div class="kpi-mid">
                        <div class="kpi-value">{{ n(stats.expiring_30_count) }}</div>
                        <div class="kpi-label">Domains / SSL · 30d</div>
                    </div>
                    <div class="kpi-foot">
                        <span
                            class="trend-pill"
                            :class="stats.expiring_critical_count > 0 ? 'red' : 'up'"
                        >
                            <IconAlarm v-if="stats.expiring_critical_count > 0" :size="13" stroke-width="2" />
                            <IconCircleCheck v-else :size="13" stroke-width="2" />
                            {{ stats.expiring_critical_count > 0 ? `${stats.expiring_critical_count} critical` : 'all healthy' }}
                        </span>
                        <span class="kpi-sub">&lt; 7 days</span>
                    </div>
                </div>
            </div>

            <!-- ═══ Main 65/35 grid ═══ -->
            <div class="main-grid">
                <!-- LEFT COLUMN -->
                <div class="col">
                    <!-- Products -->
                    <div class="card">
                        <div class="card-header">
                            <div class="h-icon gold"><IconLayoutGrid :size="16" stroke-width="1.75" /></div>
                            <div>
                                <h3>Products</h3>
                                <div class="sub">{{ activeProductCount }} active<template v-if="comingSoonCount"> · {{ comingSoonCount }} in build</template></div>
                            </div>
                            <div class="right">
                                <span>Total MRR <strong style="color: var(--text-primary); font-weight: 600;">{{ gbp(totalMrr) }}</strong></span>
                                <button type="button" class="icon-btn" aria-label="More">
                                    <IconDots :size="16" stroke-width="1.75" />
                                </button>
                            </div>
                        </div>
                        <div class="prod-table">
                            <div
                                v-for="p in products"
                                :key="p.id"
                                class="prod-row"
                                :class="{ soon: p.is_coming_soon }"
                            >
                                <div class="prod-logo" :class="prodClass(p)">
                                    <IconMessage2 v-if="p.is_coming_soon" :size="18" stroke-width="1.75" />
                                    <template v-else>{{ prodInitial(p) }}</template>
                                </div>
                                <div class="prod-meta">
                                    <div class="pname">{{ p.name }}</div>
                                    <div v-if="p.description" class="pdesc">{{ p.description }}</div>
                                </div>
                                <div class="prod-stat" :class="{ muted: p.is_coming_soon }">
                                    <template v-if="p.is_coming_soon">—<span class="sub">no customers yet</span></template>
                                    <template v-else>{{ n(p.customer_count) }}<span class="sub">active</span></template>
                                </div>
                                <div class="prod-stat" :class="{ muted: p.is_coming_soon }">
                                    <template v-if="p.is_coming_soon">—<span class="sub">pre-revenue</span></template>
                                    <template v-else>{{ gbp(p.mrr) }}<span class="sub">MRR</span></template>
                                </div>
                                <div>
                                    <span
                                        class="badge"
                                        :class="p.is_coming_soon ? 'badge-soon' : 'badge-active'"
                                    >{{ p.is_coming_soon ? 'Coming soon' : 'Active' }}</span>
                                </div>
                                <Link :href="viewProductHref(p)" class="view-link">
                                    {{ p.is_coming_soon ? 'Setup' : 'View' }}
                                    <IconArrowRight :size="14" stroke-width="1.75" />
                                </Link>
                            </div>
                            <div v-if="products.length === 0" style="padding: 24px; text-align: center; color: var(--text-tertiary); font: 400 13px/1.4 'Inter', sans-serif;">
                                No products configured yet
                            </div>
                        </div>
                    </div>

                    <!-- Activity -->
                    <div class="card shadow-sm">
                        <div class="card-header">
                            <div class="h-icon"><IconActivity :size="16" stroke-width="1.75" /></div>
                            <div>
                                <h3>Activity</h3>
                                <div class="sub">Across all products</div>
                            </div>
                            <div class="right">Last 24 hours</div>
                        </div>
                        <div v-if="activity.length" class="activity">
                            <div v-for="(a, i) in activity" :key="i" class="act-row">
                                <div class="act-ic" :class="actCls(a)">
                                    <component :is="actIcon(a)" :size="16" stroke-width="1.75" />
                                </div>
                                <div class="act-text">
                                    <span class="em">{{ a.label }}</span>
                                    <template v-if="actSub(a)"> · <span class="muted">{{ actSub(a) }}</span></template>
                                    <template v-else-if="a.user_role"> · <span class="muted">{{ a.user_role }}</span></template>
                                </div>
                                <div class="act-time">{{ a.time_ago }}</div>
                            </div>
                        </div>
                        <div v-else style="padding: 36px 24px; text-align: center; color: var(--text-tertiary); font: 400 13px/1.4 'Inter', sans-serif; display: flex; flex-direction: column; align-items: center; gap: 8px;">
                            <IconActivity :size="22" stroke-width="1.5" />
                            No activity yet · actions will appear here
                        </div>
                        <div class="card-foot">
                            <a href="#" class="foot-link" @click.prevent>View all activity<IconArrowRight :size="14" stroke-width="1.75" /></a>
                        </div>
                    </div>
                </div>

                <!-- RIGHT COLUMN -->
                <div class="col">
                    <!-- Needs attention -->
                    <div class="card attention">
                        <div class="card-header">
                            <div class="h-icon red"><IconAlertTriangle :size="16" stroke-width="1.75" /></div>
                            <div>
                                <h3>Needs attention</h3>
                                <div class="sub">Sorted by priority</div>
                            </div>
                            <div class="right">
                                <span v-if="attention_count > 0" class="att-count">{{ attention_count }} {{ attention_count === 1 ? 'item' : 'items' }}</span>
                            </div>
                        </div>
                        <div v-if="attention.length" class="att-list">
                            <Link
                                v-for="(item, i) in attention"
                                :key="i"
                                :href="item.href"
                                class="att-row"
                            >
                                <span class="pri-dot" :class="item.priority" />
                                <div>
                                    <div class="att-title">{{ item.title }}</div>
                                    <div class="att-sub">{{ item.sub }}</div>
                                </div>
                                <span class="att-link">{{ item.action }}</span>
                            </Link>
                        </div>
                        <div v-else class="att-empty">
                            <IconCircleCheckFilled :size="28" stroke-width="1.5" />
                            All clear · nothing needs attention
                        </div>
                        <div v-if="attention_count > 0" class="card-foot">
                            <a href="#" class="foot-link" @click.prevent>View all {{ attention_count }}<IconArrowRight :size="14" stroke-width="1.75" /></a>
                        </div>
                    </div>

                    <!-- Tasks -->
                    <div class="card shadow-sm">
                        <div class="card-header">
                            <div class="h-icon"><IconCheckbox :size="16" stroke-width="1.75" /></div>
                            <div>
                                <h3>Tasks</h3>
                                <div class="sub">{{ tasks.length }} open<template v-if="tasksDueToday"> · {{ tasksDueToday }} due today</template></div>
                            </div>
                            <div class="right">
                                <button type="button" class="btn btn-ghost btn-sm" @click="openNewTask">
                                    <IconPlus :size="14" stroke-width="1.75" />New task
                                </button>
                            </div>
                        </div>
                        <div v-if="tasks.length" class="tasks">
                            <div
                                v-for="t in tasks"
                                :key="t.id"
                                class="task-row"
                                :class="{ completing: completingId === t.id }"
                            >
                                <button
                                    type="button"
                                    class="cb"
                                    :aria-label="`Complete task: ${t.title}`"
                                    :disabled="completingId === t.id"
                                    @click="completeTask(t.id)"
                                />
                                <div>
                                    <div class="task-text">{{ t.title }}</div>
                                    <div v-if="t.customer" class="task-meta">
                                        <span class="pill">{{ t.customer.name }}</span>
                                    </div>
                                </div>
                                <div class="due" :class="taskDueLabel(t).cls">{{ taskDueLabel(t).text }}</div>
                            </div>
                        </div>
                        <div v-else style="padding: 24px; text-align: center; color: var(--text-tertiary); font: 400 13px/1.4 'Inter', sans-serif;">
                            No tasks assigned to you
                        </div>
                    </div>

                    <!-- This month -->
                    <div class="card shadow-sm">
                        <div class="card-header">
                            <div class="h-icon"><IconChartBar :size="16" stroke-width="1.75" /></div>
                            <div>
                                <h3>This month</h3>
                                <div class="sub">Quick stats</div>
                            </div>
                        </div>
                        <div class="qstats">
                            <div class="qstat">
                                <div class="qstat-label">New customers</div>
                                <div class="qstat-value">
                                    {{ n(this_month.new_customers) }}
                                    <span
                                        v-if="this_month.new_customers_delta !== 0"
                                        class="delta"
                                        :class="this_month.new_customers_delta > 0 ? 'good' : 'bad'"
                                    >{{ this_month.new_customers_delta > 0 ? '↑ +' : '↓ −' }}{{ Math.abs(this_month.new_customers_delta) }}</span>
                                </div>
                            </div>
                            <div class="qstat">
                                <div class="qstat-label">Churned</div>
                                <div class="qstat-value">
                                    {{ n(this_month.churned) }}
                                    <span
                                        v-if="this_month.churned_delta !== 0"
                                        class="delta"
                                        :class="this_month.churned_delta < 0 ? 'good' : 'bad'"
                                    >{{ this_month.churned_delta < 0 ? '↓ −' : '↑ +' }}{{ Math.abs(this_month.churned_delta) }}</span>
                                </div>
                            </div>
                            <div class="qstat">
                                <div class="qstat-label">Invoices raised</div>
                                <div class="qstat-value">{{ n(this_month.invoices_raised) }}</div>
                            </div>
                            <div class="qstat">
                                <div class="qstat-label">Paid</div>
                                <div class="qstat-value">
                                    {{ n(this_month.invoices_paid) }}
                                    <span v-if="this_month.invoices_raised > 0" class="delta">{{ this_month.invoices_paid_pct }}%</span>
                                </div>
                            </div>
                            <div class="qstat">
                                <div class="qstat-label">Commissions due</div>
                                <div class="qstat-value">{{ gbp(this_month.commissions_due) }}</div>
                            </div>
                            <div class="qstat">
                                <div class="qstat-label">Avg resolution</div>
                                <div class="qstat-value">{{ this_month.avg_resolution_hours || '—' }}<span v-if="this_month.avg_resolution_hours" class="delta">h</span></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ═══ Footer row ═══ -->
            <div class="footer-row">
                <!-- Referral performance -->
                <div class="card shadow-sm">
                    <div class="card-header">
                        <div class="h-icon"><IconUsersGroup :size="16" stroke-width="1.75" /></div>
                        <div>
                            <h3>Referral performance</h3>
                            <div class="sub">
                                {{ referrers.length }} {{ referrers.length === 1 ? 'referrer' : 'referrers' }}
                                <template v-if="total_pending_commissions > 0"> · {{ gbp(total_pending_commissions) }} pending payout</template>
                            </div>
                        </div>
                    </div>
                    <template v-if="referrers.length">
                        <div class="ref-head">
                            <div />
                            <div>Referrer</div>
                            <div class="num">Customers</div>
                            <div class="num">Commission</div>
                            <div class="num">Pending</div>
                        </div>
                        <div v-for="(r, i) in referrers" :key="r.id" class="ref-row">
                            <div class="avatar" :class="avClass(i)">{{ initials(r.name) }}</div>
                            <div>
                                <div class="ref-name">{{ r.name }}</div>
                                <div class="ref-sub">{{ r.email }}</div>
                            </div>
                            <div class="ref-num">{{ n(r.customer_count) }}<span class="sub">referrals</span></div>
                            <div class="ref-num">{{ gbp(r.commission_this_month) }}<span class="sub">/month</span></div>
                            <div class="ref-num" :class="{ gold: r.pending_payout > 0 }">{{ gbp(r.pending_payout) }}<span class="sub">pending</span></div>
                        </div>
                    </template>
                    <div v-else style="padding: 24px; text-align: center; color: var(--text-tertiary); font: 400 13px/1.4 'Inter', sans-serif;">
                        No referrers yet
                    </div>
                    <div class="card-foot">
                        <a href="#" class="foot-link" @click.prevent>View all referrers<IconArrowRight :size="14" stroke-width="1.75" /></a>
                    </div>
                </div>

                <!-- Platform health -->
                <div class="card shadow-sm">
                    <div class="card-header">
                        <div class="h-icon"><IconServer :size="16" stroke-width="1.75" /></div>
                        <div>
                            <h3>Platform health</h3>
                            <div class="sub">All systems operational</div>
                        </div>
                        <div class="right">
                            <span
                                class="badge badge-sm"
                                :class="healthOk ? 'badge-active' : 'badge-overdue'"
                            >{{ healthOk ? 'All good' : 'Issues detected' }}</span>
                        </div>
                    </div>
                    <div class="health-head">
                        <div>Service</div>
                        <div>Uptime</div>
                        <div>Last check</div>
                        <div style="text-align: right;">Status</div>
                    </div>
                    <div v-for="(s, i) in platform_health" :key="i" class="health-row">
                        <div class="h-name">
                            <span class="dot" :class="{ neutral: s.is_coming_soon }" />{{ s.name }}
                        </div>
                        <div class="h-uptime" :class="{ 'h-muted': s.is_coming_soon }">{{ s.uptime !== null ? `${s.uptime.toFixed(2)}%` : '—' }}</div>
                        <div class="h-checked" :class="{ 'h-muted': s.is_coming_soon }">{{ s.last_check ?? '—' }}</div>
                        <div style="text-align: right;">
                            <span
                                class="badge badge-sm"
                                :class="s.is_coming_soon ? 'badge-soon' : 'badge-active'"
                            >{{ s.is_coming_soon ? 'Coming soon' : 'Operational' }}</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ═══ Page footer ═══ -->
            <div class="page-foot">
                <div>Powerhouse v1.0.0 · Whitedash</div>
                <div>Data refreshed <strong>just now</strong> · auto-syncs every 5 min</div>
            </div>
        </div>

        <!-- ═══ New-task slide-over ═══ -->
        <TransitionRoot as="template" :show="showNewTask">
            <Dialog as="div" class="slide-over-dialog" @close="showNewTask = false">
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
                        <form class="slide-over-form" @submit.prevent="submitNewTask">
                            <header class="slide-over-header">
                                <h2>New task</h2>
                                <button type="button" class="icon-btn" aria-label="Close" @click="showNewTask = false">
                                    <IconX :size="18" stroke-width="1.75" />
                                </button>
                            </header>

                            <div class="slide-over-body">
                                <div class="form-section">
                                    <div class="form-row single">
                                        <div class="form-field">
                                            <label for="task_title">Task<span class="req">*</span></label>
                                            <input
                                                id="task_title"
                                                v-model="taskForm.title"
                                                type="text"
                                                placeholder="What needs to be done?"
                                                maxlength="500"
                                                :class="{ 'has-err': taskForm.errors.title }"
                                                required
                                            >
                                            <div v-if="taskForm.errors.title" class="err">{{ taskForm.errors.title }}</div>
                                        </div>
                                    </div>
                                    <div class="form-row single">
                                        <div class="form-field">
                                            <label>Customer (optional)</label>
                                            <div v-if="taskForm.customer_id" class="cust-row selected" style="cursor: default;">
                                                <div class="meta">
                                                    <div class="nm">{{ customerSearch }}</div>
                                                </div>
                                                <button type="button" class="clear" aria-label="Clear customer" @click="clearCustomer">
                                                    <IconX :size="14" stroke-width="1.75" />
                                                </button>
                                            </div>
                                            <template v-else>
                                                <div class="cust-search">
                                                    <IconSearch :size="16" stroke-width="1.75" />
                                                    <input v-model="customerSearch" type="search" placeholder="Link to a customer…">
                                                </div>
                                                <div v-if="filteredCustomers.length" class="cust-list" style="margin-top: 6px; max-height: 200px; overflow-y: auto;">
                                                    <button
                                                        v-for="c in filteredCustomers"
                                                        :key="c.id"
                                                        type="button"
                                                        class="cust-row"
                                                        @click="pickCustomer(c)"
                                                    >
                                                        <div class="meta">
                                                            <div class="nm">{{ c.name }}</div>
                                                        </div>
                                                    </button>
                                                </div>
                                            </template>
                                        </div>
                                    </div>
                                    <div class="form-row">
                                        <div class="form-field">
                                            <label for="task_due">Due date (optional)</label>
                                            <input
                                                id="task_due"
                                                v-model="taskForm.due_date"
                                                type="date"
                                                :min="todayIso"
                                                :class="{ 'has-err': taskForm.errors.due_date }"
                                            >
                                            <div v-if="taskForm.errors.due_date" class="err">{{ taskForm.errors.due_date }}</div>
                                        </div>
                                        <div class="form-field">
                                            <label for="task_assigned">Assign to<span class="req">*</span></label>
                                            <select
                                                id="task_assigned"
                                                v-model="taskForm.assigned_to"
                                                :class="{ 'has-err': taskForm.errors.assigned_to }"
                                            >
                                                <option v-for="u in assignable_users" :key="u.id" :value="u.id">{{ u.name }}</option>
                                            </select>
                                            <div v-if="taskForm.errors.assigned_to" class="err">{{ taskForm.errors.assigned_to }}</div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <footer class="slide-over-footer">
                                <button type="button" class="btn btn-secondary" @click="showNewTask = false">Cancel</button>
                                <button type="submit" class="btn btn-primary" :disabled="taskForm.processing">
                                    <IconPlus :size="15" stroke-width="1.75" />
                                    {{ taskForm.processing ? 'Creating…' : 'Create task' }}
                                </button>
                            </footer>
                        </form>
                    </DialogPanel>
                </TransitionChild>
            </Dialog>
        </TransitionRoot>
    </InternalLayout>
</template>
