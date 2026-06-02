<script setup>
/**
 * WordPress bulk plugin updates.
 *
 * Server hands us every MainWP-linked active site plus its outstanding
 * plugin/theme update breakdown (name, current → new version) captured by
 * the websites:sync-wordpress sweep. Each site row expands to show that
 * breakdown. "Update all plugins" (per site, or for every filtered site)
 * POSTs one site per request to /wordpress/updates/site/{id} so each call
 * stays inside the HTTP timeout and progress is legible per site.
 * super_admin only — this mutates live customer sites.
 */
import { computed, ref } from 'vue';
import { Head } from '@inertiajs/vue3';
import {
    IconBrandWordpress, IconRefresh, IconSearch, IconChevronRight, IconChevronDown,
    IconCircleCheck, IconAlertTriangle, IconLoader2,
} from '@tabler/icons-vue';
import InternalLayout from '@/Layouts/InternalLayout.vue';

const props = defineProps({
    configured: { type: Boolean, default: false },
    sites: { type: Array, default: () => [] },
    customers: { type: Array, default: () => [] },
});

/* Local working copy so counts / detail / status mutate as updates land. */
const rows = ref(props.sites.map((s) => ({ ...s, status: 'idle', message: '' })));
const search = ref('');
const customerFilter = ref('all');
const running = ref(false);
const expanded = ref({});

function toggleSite(id) {
    expanded.value[id] = ! expanded.value[id];
}

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

const pendingTotal = computed(() => rows.value.reduce((n, r) => n + (r.plugins_outdated || 0), 0));
const sitesNeeding = computed(() => rows.value.filter((r) => r.plugins_outdated > 0).length);
const updatableVisible = computed(() => filtered.value.filter((r) => r.plugins_outdated > 0));

function csrf() {
    return document.querySelector('meta[name=csrf-token]')?.content ?? '';
}

async function updateOne(row) {
    if (! props.configured || ! row.plugins_outdated) return;
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
            if (typeof data.themes_outdated === 'number') row.themes_outdated = data.themes_outdated;
            if (Array.isArray(data.plugin_updates)) row.plugin_updates = data.plugin_updates;
            if (Array.isArray(data.theme_updates)) row.theme_updates = data.theme_updates;
        } else {
            row.status = 'error';
            row.message = data.message ?? `Failed (${res.status})`;
        }
    } catch (e) {
        row.status = 'error';
        row.message = e?.message ?? 'Network error';
    }
}

async function updateAll() {
    if (running.value) return;
    running.value = true;
    // Sequential — one site at a time keeps load on the MainWP dashboard
    // sane and makes per-site progress legible.
    for (const row of updatableVisible.value) {
        await updateOne(row);
    }
    running.value = false;
}

const STATUS = {
    running: { cls: 'wu-running', icon: IconLoader2 },
    done: { cls: 'wu-done', icon: IconCircleCheck },
    error: { cls: 'wu-error', icon: IconAlertTriangle },
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
            <div v-if="! configured" class="wu-notice">
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
            <div class="wu-toolbar">
                <div class="wu-search">
                    <IconSearch :size="15" stroke-width="1.75" />
                    <input v-model="search" type="text" placeholder="Search sites or customers…" />
                </div>
                <select v-model="customerFilter" class="field-input wu-cust">
                    <option value="all">All customers</option>
                    <option v-for="c in customers" :key="c.id" :value="c.id">{{ c.name }}</option>
                </select>
                <div class="wu-spacer"></div>
                <button
                    type="button"
                    class="btn btn-primary"
                    :disabled="! configured || running || updatableVisible.length === 0"
                    @click="updateAll"
                >
                    <IconRefresh :size="15" stroke-width="2" :class="{ 'wu-spin': running }" />
                    {{ running ? 'Updating…' : `Update all${updatableVisible.length ? ' (' + updatableVisible.length + ')' : ''}` }}
                </button>
            </div>

            <!-- Site cards -->
            <div class="wu-list">
                <div v-for="site in filtered" :key="site.id" class="wu-site-card">
                    <div class="wu-site-header" @click="toggleSite(site.id)">
                        <component :is="expanded[site.id] ? IconChevronDown : IconChevronRight" :size="16" stroke-width="2" class="wu-chevron" />
                        <div class="wu-site-info">
                            <span class="wu-site-name">{{ site.url }}</span>
                            <span class="wu-site-meta">
                                <span>WP {{ site.wp_version ?? '—' }}</span>
                                <span v-if="site.plugins_outdated" class="wu-badge-warning">{{ site.plugins_outdated }} plugin updates</span>
                                <span v-if="site.themes_outdated" class="wu-badge-warning">{{ site.themes_outdated }} theme updates</span>
                                <span v-if="! site.plugins_outdated && ! site.themes_outdated" class="wu-badge-ok">Up to date ✓</span>
                                <span v-if="site.status !== 'idle'" class="wu-status" :class="STATUS[site.status]?.cls">
                                    <component :is="STATUS[site.status]?.icon" :size="13" stroke-width="2" :class="{ 'wu-spin': site.status === 'running' }" />
                                    {{ site.message }}
                                </span>
                            </span>
                        </div>
                        <div class="wu-site-actions" @click.stop>
                            <button
                                v-if="site.plugins_outdated"
                                type="button"
                                class="btn btn-primary btn-sm"
                                :disabled="! configured || site.status === 'running'"
                                @click="updateOne(site)"
                            >
                                <IconRefresh :size="14" stroke-width="2" :class="{ 'wu-spin': site.status === 'running' }" />
                                Update all plugins
                            </button>
                        </div>
                    </div>

                    <div v-if="expanded[site.id]" class="wu-site-detail">
                        <div v-if="site.plugin_updates?.length" class="wu-updates-section">
                            <div class="wu-section-label">Plugins ({{ site.plugin_updates.length }})</div>
                            <table class="wu-updates-table">
                                <thead>
                                    <tr><th>Plugin</th><th>Current</th><th></th><th>Latest</th></tr>
                                </thead>
                                <tbody>
                                    <tr v-for="p in site.plugin_updates" :key="p.slug || p.name">
                                        <td class="wu-plugin-name">{{ p.name }}</td>
                                        <td class="wu-version wu-version--old">{{ p.current_version }}</td>
                                        <td class="wu-arrow">→</td>
                                        <td class="wu-version wu-version--new">{{ p.new_version }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <div v-if="site.theme_updates?.length" class="wu-updates-section">
                            <div class="wu-section-label">Themes ({{ site.theme_updates.length }})</div>
                            <table class="wu-updates-table">
                                <thead>
                                    <tr><th>Theme</th><th>Current</th><th></th><th>Latest</th></tr>
                                </thead>
                                <tbody>
                                    <tr v-for="t in site.theme_updates" :key="t.slug || t.name">
                                        <td class="wu-plugin-name">{{ t.name }}</td>
                                        <td class="wu-version wu-version--old">{{ t.current_version }}</td>
                                        <td class="wu-arrow">→</td>
                                        <td class="wu-version wu-version--new">{{ t.new_version }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <div v-if="! site.plugin_updates?.length && ! site.theme_updates?.length" class="wu-detail-empty">
                            No pending updates — this site is up to date.
                        </div>
                    </div>
                </div>

                <div v-if="filtered.length === 0" class="wu-empty">
                    <IconBrandWordpress :size="22" stroke-width="1.5" />
                    <p>No linked sites match.</p>
                </div>
            </div>
        </div>
    </InternalLayout>
</template>

<style scoped>
.wp-updates { display: flex; flex-direction: column; gap: 16px; }
.wu-notice {
    display: flex; align-items: center; gap: 8px;
    padding: 10px 14px; border-radius: var(--radius-md);
    background: var(--warning-bg); color: #B45309;
    border: 1px solid #FDE68A; font: 500 13px/1.4 'Inter', sans-serif;
}
.wu-toolbar { display: flex; align-items: center; gap: 10px; }
.wu-search {
    display: flex; align-items: center; gap: 7px;
    padding: 0 10px; height: 36px; min-width: 260px;
    background: #fff; border: 1px solid var(--border); border-radius: var(--radius-md);
    color: var(--text-tertiary);
}
.wu-search input { border: 0; outline: 0; flex: 1; font: 400 13px/1 'Inter', sans-serif; color: var(--text-primary); background: transparent; }
.wu-cust { max-width: 200px; height: 36px; }
.wu-spacer { flex: 1; }
.wu-spin { animation: wu-rot 0.8s linear infinite; }
@keyframes wu-rot { to { transform: rotate(360deg); } }

.wu-list { display: flex; flex-direction: column; gap: 8px; }
.wu-site-card {
    background: var(--card-bg); border: 1px solid var(--border);
    border-radius: var(--radius-lg); overflow: hidden;
}
.wu-site-header {
    display: flex; align-items: center; gap: 12px;
    padding: 14px 16px; cursor: pointer; transition: background .1s;
}
.wu-site-header:hover { background: var(--neutral-bg); }
.wu-chevron { color: var(--text-tertiary); flex-shrink: 0; }
.wu-site-info { flex: 1; min-width: 0; }
.wu-site-name { display: block; font: 600 14px/1.2 'Inter', sans-serif; color: var(--text-primary); word-break: break-all; }
.wu-site-meta { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; margin-top: 5px; font: 400 12px/1 'Inter', sans-serif; color: var(--text-secondary); }
.wu-badge-warning { padding: 2px 8px; border-radius: 999px; background: var(--warning-bg); color: #B45309; font: 600 11px/1.5 'Inter', sans-serif; }
.wu-badge-ok { color: var(--success); font: 500 11.5px/1 'Inter', sans-serif; }
.wu-status { display: inline-flex; align-items: center; gap: 4px; font: 500 12px/1 'Inter', sans-serif; }
.wu-status.wu-running { color: var(--info); }
.wu-status.wu-done { color: #047857; }
.wu-status.wu-error { color: #B91C1C; }
.wu-site-actions { flex-shrink: 0; }

.wu-site-detail { border-top: 1px solid var(--border); padding: 16px; background: var(--neutral-bg); }
.wu-updates-section { margin-bottom: 16px; }
.wu-updates-section:last-child { margin-bottom: 0; }
.wu-section-label { font: 600 10px/1 'Inter', sans-serif; text-transform: uppercase; letter-spacing: .08em; color: var(--text-tertiary); margin-bottom: 8px; }
.wu-updates-table { width: 100%; border-collapse: collapse; font-size: 13px; }
.wu-updates-table th { font: 500 11px/1 'Inter', sans-serif; text-transform: uppercase; letter-spacing: .05em; color: var(--text-tertiary); padding: 4px 8px; text-align: left; border-bottom: 1px solid var(--border); }
.wu-updates-table td { padding: 7px 8px; border-bottom: 1px solid var(--border-soft); }
.wu-plugin-name { font-weight: 500; color: var(--text-primary); }
.wu-version { font-family: 'SFMono-Regular', ui-monospace, monospace; }
.wu-version--old { color: var(--text-tertiary); }
.wu-version--new { color: var(--success); font-weight: 600; }
.wu-arrow { color: var(--text-tertiary); width: 20px; text-align: center; }
.wu-detail-empty { font: 400 12.5px/1.4 'Inter', sans-serif; color: var(--text-tertiary); }

.wu-empty { text-align: center; padding: 40px 0; color: var(--text-tertiary); }
.wu-empty p { margin: 8px 0 0; font: 500 13px/1.4 'Inter', sans-serif; }
</style>
