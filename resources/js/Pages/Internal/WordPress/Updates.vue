<script setup>
/**
 * WordPress bulk plugin updates.
 *
 * Server hands us every MainWP-linked active site plus its outstanding
 * plugin-update count (from the websites:sync-wordpress sweep). The
 * operator selects sites and hits "Update plugins"; the page then loops
 * the selection, POSTing one site per request to /wordpress/updates/site/{id}
 * so each call stays inside the HTTP timeout and we can show live per-site
 * progress. super_admin only — this mutates live customer sites.
 */
import { computed, ref } from 'vue';
import { Head } from '@inertiajs/vue3';
import { IconBrandWordpress, IconRefresh, IconSearch, IconCircleCheck, IconAlertTriangle, IconLoader2 } from '@tabler/icons-vue';
import InternalLayout from '@/Layouts/InternalLayout.vue';

const props = defineProps({
    configured: { type: Boolean, default: false },
    sites: { type: Array, default: () => [] },
    customers: { type: Array, default: () => [] },
});

/* Local working copy so we can mutate counts/status as updates land. */
const rows = ref(props.sites.map((s) => ({ ...s, status: 'idle', message: '' })));
const search = ref('');
const customerFilter = ref('all');
const running = ref(false);

const filtered = computed(() => {
    const q = search.value.trim().toLowerCase();
    return rows.value.filter((r) => {
        if (customerFilter.value !== 'all' && String(r.customer_id) !== String(customerFilter.value)) return false;
        if (! q) return true;
        return (r.url ?? '').toLowerCase().includes(q)
            || (r.name ?? '').toLowerCase().includes(q)
            || (r.customer_name ?? '').toLowerCase().includes(q);
    });
});

/* Selection — keyed by website id. */
const selected = ref(new Set());
function toggle(id) {
    const next = new Set(selected.value);
    next.has(id) ? next.delete(id) : next.add(id);
    selected.value = next;
}
const visibleSelectableIds = computed(() => filtered.value.filter((r) => r.plugins_outdated > 0).map((r) => r.id));
const allVisibleSelected = computed(() =>
    visibleSelectableIds.value.length > 0 && visibleSelectableIds.value.every((id) => selected.value.has(id)),
);
function toggleAll() {
    const next = new Set(selected.value);
    if (allVisibleSelected.value) {
        visibleSelectableIds.value.forEach((id) => next.delete(id));
    } else {
        visibleSelectableIds.value.forEach((id) => next.add(id));
    }
    selected.value = next;
}

const selectedCount = computed(() => selected.value.size);
const pendingTotal = computed(() => rows.value.reduce((n, r) => n + (r.plugins_outdated || 0), 0));
const sitesNeeding = computed(() => rows.value.filter((r) => r.plugins_outdated > 0).length);

function csrf() {
    return document.querySelector('meta[name=csrf-token]')?.content ?? '';
}

async function updateOne(row) {
    row.status = 'running';
    row.message = '';
    try {
        const res = await fetch(`/wordpress/updates/site/${row.id}`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': csrf(),
            },
            credentials: 'same-origin',
        });
        const data = await res.json();
        if (res.ok && data.ok) {
            row.status = 'done';
            row.message = data.message ?? 'Updated';
            if (typeof data.plugins_outdated === 'number') row.plugins_outdated = data.plugins_outdated;
            if (typeof data.plugins_total === 'number') row.plugins_total = data.plugins_total;
        } else {
            row.status = 'error';
            row.message = data.message ?? `Failed (${res.status})`;
        }
    } catch (e) {
        row.status = 'error';
        row.message = e?.message ?? 'Network error';
    }
}

async function runUpdates() {
    if (running.value || selectedCount.value === 0) return;
    running.value = true;
    // Sequential — one site at a time keeps load on the MainWP dashboard
    // sane and makes per-site progress legible.
    for (const row of rows.value) {
        if (selected.value.has(row.id)) {
            await updateOne(row);
        }
    }
    running.value = false;
}

const STATUS = {
    idle: { cls: '', icon: null, label: '' },
    running: { cls: 'wpu-running', icon: IconLoader2, label: 'Updating…' },
    done: { cls: 'wpu-done', icon: IconCircleCheck, label: 'Done' },
    error: { cls: 'wpu-error', icon: IconAlertTriangle, label: 'Error' },
};
</script>

<template>
    <Head title="WP Updates" />

    <InternalLayout
        title="WordPress Updates"
        active-nav="wp-updates"
        :breadcrumbs="[{ label: 'Powerhouse', href: '/' }, { label: 'WordPress Updates' }]"
    >
        <div class="wp-updates">
            <div v-if="! configured" class="wpu-notice">
                <IconAlertTriangle :size="16" stroke-width="2" />
                MainWP is not configured. Add credentials in Settings → Integrations to enable plugin updates.
            </div>

            <!-- Summary -->
            <div class="summary-strip">
                <div class="stat-pill">
                    <span class="d blue"></span>
                    <span class="n">{{ rows.length }}</span>
                    <span class="l">Linked sites</span>
                </div>
                <div class="stat-pill">
                    <span class="d amber"></span>
                    <span class="n">{{ sitesNeeding }}</span>
                    <span class="l">Need updates</span>
                </div>
                <div class="stat-pill">
                    <span class="d grey"></span>
                    <span class="n">{{ pendingTotal }}</span>
                    <span class="l">Pending plugins</span>
                </div>
            </div>

            <!-- Toolbar -->
            <div class="wpu-toolbar">
                <div class="wpu-search">
                    <IconSearch :size="15" stroke-width="1.75" />
                    <input v-model="search" type="text" placeholder="Search sites or customers…" />
                </div>
                <select v-model="customerFilter" class="field-input wpu-cust">
                    <option value="all">All customers</option>
                    <option v-for="c in customers" :key="c.id" :value="c.id">{{ c.name }}</option>
                </select>
                <div class="wpu-spacer"></div>
                <button
                    type="button"
                    class="btn btn-primary"
                    :disabled="! configured || running || selectedCount === 0"
                    @click="runUpdates"
                >
                    <IconRefresh :size="15" stroke-width="2" :class="{ 'wpu-spin': running }" />
                    {{ running ? 'Updating…' : `Update plugins${selectedCount ? ' (' + selectedCount + ')' : ''}` }}
                </button>
            </div>

            <!-- Site table -->
            <div class="table-card">
                <table class="tbl wpu-table">
                    <thead>
                        <tr>
                            <th class="wpu-check">
                                <input type="checkbox" :checked="allVisibleSelected" :disabled="visibleSelectableIds.length === 0" @change="toggleAll" />
                            </th>
                            <th>Site</th>
                            <th style="width: 160px;">Customer</th>
                            <th style="width: 90px;">WP</th>
                            <th style="width: 130px;">Plugins</th>
                            <th style="width: 200px;">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="row in filtered" :key="row.id" :class="{ 'wpu-sel': selected.has(row.id) }">
                            <td class="wpu-check">
                                <input
                                    type="checkbox"
                                    :checked="selected.has(row.id)"
                                    :disabled="row.plugins_outdated === 0"
                                    @change="toggle(row.id)"
                                />
                            </td>
                            <td>
                                <div class="wpu-site-name">{{ row.name }}</div>
                                <a :href="row.url" target="_blank" rel="noopener" class="wpu-site-url">{{ row.url }}</a>
                            </td>
                            <td class="wpu-muted">{{ row.customer_name ?? '—' }}</td>
                            <td class="wpu-muted">{{ row.wp_version ?? '—' }}</td>
                            <td>
                                <span v-if="row.plugins_outdated > 0" class="badge badge-sm badge-pending">
                                    {{ row.plugins_outdated }} / {{ row.plugins_total }} outdated
                                </span>
                                <span v-else class="badge badge-sm badge-active">Up to date</span>
                            </td>
                            <td>
                                <span v-if="row.status !== 'idle'" class="wpu-status" :class="STATUS[row.status].cls">
                                    <component :is="STATUS[row.status].icon" :size="14" stroke-width="2" :class="{ 'wpu-spin': row.status === 'running' }" />
                                    {{ row.message || STATUS[row.status].label }}
                                </span>
                                <span v-else class="wpu-muted">—</span>
                            </td>
                        </tr>
                        <tr v-if="filtered.length === 0">
                            <td colspan="6" class="wpu-empty">
                                <IconBrandWordpress :size="22" stroke-width="1.5" />
                                <p>No linked sites match.</p>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </InternalLayout>
</template>

<style scoped>
.wp-updates { display: flex; flex-direction: column; gap: 16px; }
.wpu-notice {
    display: flex; align-items: center; gap: 8px;
    padding: 10px 14px; border-radius: var(--radius-md);
    background: var(--warning-bg); color: #B45309;
    border: 1px solid #FDE68A; font: 500 13px/1.4 'Inter', sans-serif;
}
.wpu-toolbar { display: flex; align-items: center; gap: 10px; }
.wpu-search {
    display: flex; align-items: center; gap: 7px;
    padding: 0 10px; height: 36px; min-width: 260px;
    background: #fff; border: 1px solid var(--border); border-radius: var(--radius-md);
    color: var(--text-tertiary);
}
.wpu-search input { border: 0; outline: 0; flex: 1; font: 400 13px/1 'Inter', sans-serif; color: var(--text-primary); background: transparent; }
.wpu-cust { max-width: 200px; height: 36px; }
.wpu-spacer { flex: 1; }
.wpu-spin { animation: wpu-rot 0.8s linear infinite; }
@keyframes wpu-rot { to { transform: rotate(360deg); } }
.wpu-table th.wpu-check, .wpu-table td.wpu-check { width: 36px; text-align: center; }
.wpu-table tbody tr.wpu-sel { background: var(--accent-soft, #F1F5FF); }
.wpu-site-name { font: 600 13.5px/1.3 'Inter', sans-serif; color: var(--text-primary); }
.wpu-site-url { font: 400 12px/1.3 'Inter', sans-serif; color: var(--info); text-decoration: none; word-break: break-all; }
.wpu-site-url:hover { text-decoration: underline; }
.wpu-muted { color: var(--text-tertiary); font: 400 12.5px/1.3 'Inter', sans-serif; }
.wpu-status { display: inline-flex; align-items: center; gap: 5px; font: 500 12.5px/1.3 'Inter', sans-serif; }
.wpu-status.wpu-running { color: var(--info); }
.wpu-status.wpu-done { color: #047857; }
.wpu-status.wpu-error { color: #B91C1C; }
.wpu-empty { text-align: center; padding: 40px 0; color: var(--text-tertiary); }
.wpu-empty p { margin: 8px 0 0; font: 500 13px/1.4 'Inter', sans-serif; }
</style>
