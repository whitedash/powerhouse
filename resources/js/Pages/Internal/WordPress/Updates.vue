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
import { Head, router } from '@inertiajs/vue3';
import {
    IconBrandWordpress, IconRefresh, IconSearch, IconChevronRight, IconChevronDown, IconChevronUp,
    IconCircleCheck, IconAlertTriangle, IconLoader2, IconChecks,
} from '@tabler/icons-vue';
import InternalLayout from '@/Layouts/InternalLayout.vue';

const props = defineProps({
    configured: { type: Boolean, default: false },
    sites: { type: Array, default: () => [] },
    customers: { type: Array, default: () => [] },
    plugins_with_updates: { type: Array, default: () => [] },
    toggle_supported: { type: Boolean, default: false },
});

const tab = ref('by-site');

/* Local working copy so counts / detail / status mutate as updates land. */
const rows = ref(props.sites.map((s) => ({ ...s, status: 'idle', message: '', actionError: '' })));
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

/* ─── By Plugin (per-plugin overview + actions) ───
 * Now that per-plugin updates work (?slug=), this tab can update a plugin on
 * one site or across every affected site. */
const pluginSearch = ref('');
const expandedPlugins = ref({});
function togglePlugin(slug) {
    expandedPlugins.value[slug] = ! expandedPlugins.value[slug];
}
const filteredPlugins = computed(() => {
    const q = pluginSearch.value.trim().toLowerCase();
    if (! q) return props.plugins_with_updates;
    return props.plugins_with_updates.filter((p) =>
        (p.name ?? '').toLowerCase().includes(q) || (p.slug ?? '').toLowerCase().includes(q),
    );
});

async function updateOne(row) {
    if (! props.configured || ! row.plugins_outdated) return;
    row.status = 'running';
    row.message = '';
    const { ok, status, data } = await postJson(`/wordpress/updates/site/${row.id}`, {});
    applyResult(row, data);
    if (ok) {
        row.status = 'done';
        row.message = data.message ?? 'Updated';
    } else if (data.partial) {
        // Some plugins updated, some failed — amber, not a hard error.
        row.status = 'partial';
        row.message = data.message ?? 'Partially updated';
    } else {
        row.status = 'error';
        row.message = data.message ?? `Failed (${status})`;
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

/* ─── Per-row actions (By Site detail) ───
 * Each posts one targeted action, then merges the fresh telemetry back into
 * the reactive site row so the table updates in place (no full reload). */
const busy = ref({});
const coreKey = (s) => `core:${s.id}`;
const rowKey = (s, slug) => `row:${s.id}:${slug}`;
function rowBusy(s, slug) {
    return !! busy.value[rowKey(s, slug)];
}

/* Read the live XSRF-TOKEN cookie Laravel sets on every response. Sending it
 * back as X-XSRF-TOKEN (which Laravel decrypts) is more robust than a meta-tag
 * token — it's always current and survives session rotation. */
function xsrfToken() {
    const m = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);
    return m ? decodeURIComponent(m[1]) : '';
}

async function postJson(url, body) {
    try {
        const res = await fetch(url, {
            method: 'POST',
            // 'include' (not 'same-origin') so the session cookie is sent even
            // when the app is served through a proxy/preview origin — without
            // it a fresh guest session is created and the request 419s/401s.
            credentials: 'include',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-XSRF-TOKEN': xsrfToken(),
            },
            body: JSON.stringify(body ?? {}),
        });
        let data = {};
        try { data = await res.json(); } catch { /* non-JSON */ }
        return { ok: res.ok && data.ok, status: res.status, data };
    } catch (e) {
        return { ok: false, status: 0, data: { message: e?.message ?? 'Network error' } };
    }
}

function applyResult(row, data) {
    if (typeof data.plugins_outdated === 'number') row.plugins_outdated = data.plugins_outdated;
    if (typeof data.plugins_total === 'number') row.plugins_total = data.plugins_total;
    if (typeof data.themes_outdated === 'number') row.themes_outdated = data.themes_outdated;
    if (Array.isArray(data.plugin_updates)) row.plugin_updates = data.plugin_updates;
    if (Array.isArray(data.theme_updates)) row.theme_updates = data.theme_updates;
    if (typeof data.wp_version === 'string') row.wp_version = data.wp_version;
    if (typeof data.wp_core_update_available === 'boolean') row.wp_core_update_available = data.wp_core_update_available;
    if ('wp_latest_version' in data) row.wp_latest_version = data.wp_latest_version;
}

async function updateCore(site) {
    if (! props.configured) return;
    const key = coreKey(site);
    busy.value[key] = true;
    site.actionError = '';
    const { ok, data } = await postJson('/wordpress/updates/core', { website_id: site.id });
    applyResult(site, data); // refresh from the synced state regardless of outcome
    if (! ok) site.actionError = data.message ?? 'Core update failed';
    busy.value[key] = false;
}

async function updateOnePlugin(site, plugin) {
    if (! props.configured) return;
    const key = rowKey(site, plugin.slug);
    busy.value[key] = true;
    site.actionError = '';
    const { ok, data } = await postJson('/wordpress/updates/plugin/update', { website_id: site.id, plugin_slug: plugin.slug });
    applyResult(site, data);
    if (! ok) site.actionError = data.message ?? 'Plugin update failed';
    busy.value[key] = false;
}

async function updateOneTheme(site, theme) {
    if (! props.configured) return;
    const key = rowKey(site, theme.slug);
    busy.value[key] = true;
    site.actionError = '';
    const { ok, data } = await postJson('/wordpress/updates/theme/update', { website_id: site.id, theme_slug: theme.slug });
    applyResult(site, data);
    if (! ok) site.actionError = data.message ?? 'Theme update failed';
    busy.value[key] = false;
}

async function togglePluginState(site, plugin) {
    if (! props.configured) return;
    const key = rowKey(site, plugin.slug);
    const action = plugin.active === false ? 'activate' : 'deactivate';
    busy.value[key] = true;
    site.actionError = '';
    const { ok, data } = await postJson('/wordpress/updates/plugin/toggle', { website_id: site.id, plugin_slug: plugin.slug, action });
    if (ok && Array.isArray(data.plugin_updates)) site.plugin_updates = data.plugin_updates;
    else if (! ok) site.actionError = data.message ?? 'Toggle failed';
    busy.value[key] = false;
}

/* ─── By Plugin tab actions ───
 * After a mutation we partial-reload the plugins_with_updates prop so the
 * per-plugin view reflects the new state (rows drop off as sites update). */
const bulkUpdating = ref({});
const siteUpdating = ref({});
const siteUpdateStatus = ref({});
const pluginMsg = ref({});

async function updatePluginAllSites(plugin) {
    if (! props.configured || bulkUpdating.value[plugin.slug]) return;
    bulkUpdating.value[plugin.slug] = true;
    pluginMsg.value[plugin.slug] = '';
    const siteIds = plugin.sites.map((s) => s.website_id);
    const { data } = await postJson('/wordpress/updates/plugin/bulk', { plugin_slug: plugin.slug, site_ids: siteIds });
    pluginMsg.value[plugin.slug] = data.message ?? '';
    bulkUpdating.value[plugin.slug] = false;
    router.reload({ only: ['sites', 'plugins_with_updates'] });
}

async function updatePluginOneSite(plugin, site) {
    if (! props.configured) return;
    const key = plugin.slug + '_' + site.website_id;
    siteUpdating.value[key] = true;
    siteUpdateStatus.value[key] = '';
    const { ok } = await postJson('/wordpress/updates/plugin/update', { website_id: site.website_id, plugin_slug: plugin.slug });
    siteUpdateStatus.value[key] = ok ? '✓ Updated' : '✗ Failed';
    siteUpdating.value[key] = false;
    if (ok) router.reload({ only: ['sites', 'plugins_with_updates'] });
}

const STATUS = {
    running: { cls: 'wu-running', icon: IconLoader2 },
    done: { cls: 'wu-done', icon: IconCircleCheck },
    partial: { cls: 'wu-partial', icon: IconAlertTriangle },
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

            <!-- Tabs -->
            <div class="wu-tabs">
                <button type="button" :class="['wu-tab', { active: tab === 'by-site' }]" @click="tab = 'by-site'">
                    By Site
                </button>
                <button type="button" :class="['wu-tab', { active: tab === 'by-plugin' }]" @click="tab = 'by-plugin'">
                    By Plugin
                    <span class="wu-tab-count">{{ plugins_with_updates.length }}</span>
                </button>
            </div>

            <!-- ─── BY SITE ─── -->
            <div v-show="tab === 'by-site'">
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
                        <div v-if="site.actionError" class="wu-action-error">
                            <IconAlertTriangle :size="14" stroke-width="2" /> {{ site.actionError }}
                        </div>

                        <!-- WordPress core -->
                        <div v-if="site.wp_core_update_available" class="wu-core-section">
                            <div class="wu-detail-label">WordPress Core</div>
                            <div class="wu-core-row">
                                <span class="wu-version wu-version--old">{{ site.wp_version ?? '—' }}</span>
                                <span class="wu-arrow">→</span>
                                <span class="wu-version wu-version--new">{{ site.wp_latest_version ?? 'latest' }}</span>
                                <button type="button" class="wu-btn-warning" :disabled="! configured || busy[coreKey(site)]" @click="updateCore(site)">
                                    <component :is="busy[coreKey(site)] ? IconLoader2 : IconBrandWordpress" :size="14" stroke-width="2" :class="{ 'wu-spin': busy[coreKey(site)] }" />
                                    Update WordPress Core
                                </button>
                            </div>
                        </div>

                        <!-- Plugins -->
                        <div v-if="site.plugin_updates?.length" class="wu-detail-section">
                            <div class="wu-detail-header">
                                <span class="wu-detail-label">Plugins</span>
                                <span class="wu-detail-count">{{ site.plugin_updates.length }} update{{ site.plugin_updates.length === 1 ? '' : 's' }} available</span>
                                <button type="button" class="btn btn-ghost btn-sm wu-ml-auto" :disabled="! configured || site.status === 'running'" @click="updateOne(site)">
                                    <IconRefresh :size="14" stroke-width="2" :class="{ 'wu-spin': site.status === 'running' }" />
                                    Update all plugins
                                </button>
                            </div>
                            <table class="wu-detail-table">
                                <colgroup><col style="width: 34%"><col style="width: 15%"><col style="width: 4%"><col style="width: 15%"><col style="width: 32%"></colgroup>
                                <thead>
                                    <tr><th>Plugin</th><th>Current</th><th></th><th>Latest</th><th>Actions</th></tr>
                                </thead>
                                <tbody>
                                    <tr v-for="p in site.plugin_updates" :key="p.slug || p.name" class="wu-row">
                                        <td class="wu-plugin-name">{{ p.name }}<span v-if="p.active === false" class="wu-inactive-tag">inactive</span></td>
                                        <td><span class="wu-version wu-version--old">{{ p.current_version }}</span></td>
                                        <td class="wu-arrow">→</td>
                                        <td><span class="wu-version wu-version--new">{{ p.new_version }}</span></td>
                                        <td class="wu-row-actions">
                                            <button type="button" class="btn btn-primary wu-btn-xs" :disabled="! configured || rowBusy(site, p.slug)" @click="updateOnePlugin(site, p)">
                                                <IconRefresh :size="12" stroke-width="2" :class="{ 'wu-spin': rowBusy(site, p.slug) }" />
                                                Update
                                            </button>
                                            <button v-if="toggle_supported" type="button" class="btn btn-ghost wu-btn-xs" :disabled="! configured || rowBusy(site, p.slug)" @click="togglePluginState(site, p)">
                                                {{ p.active === false ? 'Activate' : 'Deactivate' }}
                                            </button>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <!-- Themes -->
                        <div v-if="site.theme_updates?.length" class="wu-detail-section">
                            <div class="wu-detail-header">
                                <span class="wu-detail-label">Themes</span>
                                <span class="wu-detail-count">{{ site.theme_updates.length }} update{{ site.theme_updates.length === 1 ? '' : 's' }} available</span>
                            </div>
                            <table class="wu-detail-table">
                                <colgroup><col style="width: 34%"><col style="width: 15%"><col style="width: 4%"><col style="width: 15%"><col style="width: 32%"></colgroup>
                                <thead>
                                    <tr><th>Theme</th><th>Current</th><th></th><th>Latest</th><th>Actions</th></tr>
                                </thead>
                                <tbody>
                                    <tr v-for="t in site.theme_updates" :key="t.slug || t.name" class="wu-row">
                                        <td class="wu-plugin-name">{{ t.name }}</td>
                                        <td><span class="wu-version wu-version--old">{{ t.current_version }}</span></td>
                                        <td class="wu-arrow">→</td>
                                        <td><span class="wu-version wu-version--new">{{ t.new_version }}</span></td>
                                        <td class="wu-row-actions">
                                            <button type="button" class="btn btn-primary wu-btn-xs" :disabled="! configured || rowBusy(site, t.slug)" @click="updateOneTheme(site, t)">
                                                <IconRefresh :size="12" stroke-width="2" :class="{ 'wu-spin': rowBusy(site, t.slug) }" />
                                                Update
                                            </button>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <div v-if="! site.plugin_updates?.length && ! site.theme_updates?.length && ! site.wp_core_update_available" class="wu-all-good">
                            <IconCircleCheck :size="16" stroke-width="2" /> Everything up to date
                        </div>
                    </div>
                </div>

                <div v-if="filtered.length === 0" class="wu-empty">
                    <IconBrandWordpress :size="22" stroke-width="1.5" />
                    <p>No linked sites match.</p>
                </div>
            </div>
            </div>
            <!-- ─── /BY SITE ─── -->

            <!-- ─── BY PLUGIN ─── -->
            <div v-show="tab === 'by-plugin'">
                <div class="wu-toolbar">
                    <div class="wu-search">
                        <IconSearch :size="15" stroke-width="1.75" />
                        <input v-model="pluginSearch" type="text" placeholder="Search plugins…" />
                    </div>
                    <div class="wu-spacer"></div>
                </div>

                <div class="wu-list">
                    <div v-for="plugin in filteredPlugins" :key="plugin.slug" class="wu-plugin-card">
                        <div class="wu-plugin-header">
                            <div class="wu-plugin-info">
                                <span class="wu-plugin-name">{{ plugin.name }}</span>
                                <span class="wu-plugin-meta">
                                    <span class="wu-version-tag">Latest: {{ plugin.new_version }}</span>
                                    <span class="wu-site-count">
                                        {{ plugin.site_count }} {{ plugin.site_count === 1 ? 'site needs' : 'sites need' }} this update
                                    </span>
                                </span>
                            </div>
                            <div class="wu-plugin-actions">
                                <span v-if="pluginMsg[plugin.slug]" class="wu-bulk-msg">{{ pluginMsg[plugin.slug] }}</span>
                                <button type="button" class="btn btn-ghost btn-sm" @click="togglePlugin(plugin.slug)">
                                    <component :is="expandedPlugins[plugin.slug] ? IconChevronUp : IconChevronDown" :size="14" stroke-width="2" />
                                    {{ expandedPlugins[plugin.slug] ? 'Hide sites' : 'Show sites' }}
                                </button>
                                <button
                                    type="button"
                                    class="btn btn-primary btn-sm"
                                    :disabled="! configured || bulkUpdating[plugin.slug]"
                                    @click="updatePluginAllSites(plugin)"
                                >
                                    <IconRefresh :size="14" stroke-width="2" :class="{ 'wu-spin': bulkUpdating[plugin.slug] }" />
                                    Update on all {{ plugin.site_count }} {{ plugin.site_count === 1 ? 'site' : 'sites' }}
                                </button>
                            </div>
                        </div>

                        <div v-if="expandedPlugins[plugin.slug]" class="wu-plugin-sites">
                            <table class="wu-sites-table">
                                <colgroup><col style="width: 35%"><col style="width: 20%"><col style="width: 13%"><col style="width: 4%"><col style="width: 13%"><col style="width: 15%"></colgroup>
                                <thead>
                                    <tr><th>Site</th><th>Customer</th><th>Current</th><th></th><th>Latest</th><th>Action</th></tr>
                                </thead>
                                <tbody>
                                    <tr v-for="site in plugin.sites" :key="site.website_id">
                                        <td class="wu-site-url">{{ site.url }}</td>
                                        <td class="wu-customer">{{ site.customer_name ?? '—' }}</td>
                                        <td class="wu-version wu-version--old">{{ site.current_version }}</td>
                                        <td class="wu-arrow">→</td>
                                        <td class="wu-version wu-version--new">{{ plugin.new_version }}</td>
                                        <td class="wu-row-actions">
                                            <span v-if="siteUpdateStatus[plugin.slug + '_' + site.website_id]" class="wu-site-status">{{ siteUpdateStatus[plugin.slug + '_' + site.website_id] }}</span>
                                            <button
                                                type="button"
                                                class="btn btn-primary wu-btn-xs"
                                                :disabled="! configured || siteUpdating[plugin.slug + '_' + site.website_id]"
                                                @click="updatePluginOneSite(plugin, site)"
                                            >
                                                <IconRefresh :size="12" stroke-width="2" :class="{ 'wu-spin': siteUpdating[plugin.slug + '_' + site.website_id] }" />
                                                Update
                                            </button>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div v-if="filteredPlugins.length === 0" class="wu-empty">
                        <IconChecks :size="22" stroke-width="1.5" />
                        <p>{{ pluginSearch ? 'No plugins match.' : 'All plugins up to date.' }}</p>
                    </div>
                </div>
            </div>
            <!-- ─── /BY PLUGIN ─── -->
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
.wu-status.wu-partial { color: #B45309; }
.wu-status.wu-error { color: #B91C1C; }
.wu-bulk-msg { font: 500 12px/1 'Inter', sans-serif; color: var(--text-secondary); }
.wu-site-status { font: 600 11px/1 'Inter', sans-serif; margin-right: 6px; }
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

/* ─── By Site detail (redesigned) ─── */
.wu-action-error {
    display: flex; align-items: center; gap: 6px; margin-bottom: 12px;
    padding: 8px 12px; border-radius: var(--radius-md);
    background: var(--danger-bg); color: #B91C1C; border: 1px solid #FECACA;
    font: 500 12.5px/1.4 'Inter', sans-serif;
}
.wu-detail-section { margin-bottom: 18px; }
.wu-detail-section:last-child { margin-bottom: 0; }
.wu-detail-header { display: flex; align-items: center; gap: 10px; padding: 0 0 7px; border-bottom: 1px solid var(--border); }
.wu-detail-label { font: 700 10px/1 'Inter', sans-serif; text-transform: uppercase; letter-spacing: .09em; color: var(--text-tertiary); }
.wu-detail-count { font: 400 12px/1 'Inter', sans-serif; color: var(--text-secondary); }
.wu-ml-auto { margin-left: auto; }
.wu-detail-table { width: 100%; border-collapse: collapse; table-layout: fixed; font-size: 13px; }
.wu-detail-table th {
    font: 600 10px/1 'Inter', sans-serif; text-transform: uppercase; letter-spacing: .06em;
    color: var(--text-tertiary); padding: 7px 10px; text-align: left;
    background: var(--neutral-bg); border-bottom: 2px solid var(--border);
}
.wu-detail-table th:last-child, .wu-detail-table td.wu-row-actions { text-align: left; }
.wu-row { transition: background .1s; }
.wu-row:hover { background: var(--neutral-bg); }
.wu-row td { padding: 8px 10px; vertical-align: middle; border-bottom: 1px solid var(--border-soft); }
.wu-row:last-child td { border-bottom: none; }
.wu-detail-table .wu-plugin-name { font: 500 13px/1.3 'Inter', sans-serif; color: var(--text-primary); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.wu-inactive-tag { margin-left: 6px; padding: 1px 6px; border-radius: 999px; background: var(--neutral-bg); border: 1px solid var(--border); color: var(--text-tertiary); font: 600 9.5px/1.5 'Inter', sans-serif; text-transform: uppercase; letter-spacing: .04em; }
.wu-row-actions { display: flex; gap: 5px; align-items: center; }
.wu-btn-xs { padding: 3px 9px !important; height: 25px; font-size: 11px !important; }

/* Core update banner */
.wu-core-section {
    background: var(--warning-bg); border: 1px solid #FDE68A;
    border-radius: var(--radius-md); padding: 12px 14px; margin-bottom: 16px;
}
.wu-core-row { display: flex; align-items: center; gap: 10px; margin-top: 7px; }
.wu-btn-warning {
    display: inline-flex; align-items: center; gap: 5px; margin-left: auto;
    padding: 6px 12px; border: none; border-radius: var(--radius-md); cursor: pointer;
    background: #D97706; color: #fff; font: 600 12px/1 'Inter', sans-serif;
}
.wu-btn-warning:hover { background: #B45309; }
.wu-btn-warning:disabled { opacity: .6; cursor: default; }
.wu-all-good { display: flex; align-items: center; gap: 8px; padding: 8px 0; color: var(--success); font: 500 13px/1 'Inter', sans-serif; }

.wu-empty { text-align: center; padding: 40px 0; color: var(--text-tertiary); }
.wu-empty p { margin: 8px 0 0; font: 500 13px/1.4 'Inter', sans-serif; }

/* ─── Tabs ─── */
.wu-tabs { display: flex; gap: 4px; border-bottom: 1px solid var(--border); }
.wu-tab {
    display: flex; align-items: center; gap: 6px;
    padding: 8px 16px; margin-bottom: -1px;
    background: none; border: none; border-bottom: 2px solid transparent;
    cursor: pointer; font: 500 14px/1 'Inter', sans-serif; color: var(--text-secondary);
    transition: color .15s, border-color .15s;
}
.wu-tab:hover { color: var(--text-primary); }
.wu-tab.active { color: var(--accent); border-bottom-color: var(--accent); }
.wu-tab-count { font: 600 11px/1 'Inter', sans-serif; padding: 2px 7px; border-radius: 999px; background: var(--neutral-bg); color: var(--text-secondary); }
.wu-tab.active .wu-tab-count { background: var(--accent-soft, #F1F5FF); color: var(--accent); }

/* ─── By Plugin cards ─── */
.wu-plugin-card { background: var(--card-bg); border: 1px solid var(--border); border-radius: var(--radius-lg); overflow: hidden; }
.wu-plugin-header { display: flex; align-items: center; gap: 12px; padding: 14px 16px; }
.wu-plugin-info { flex: 1; min-width: 0; }
.wu-plugin-info .wu-plugin-name { display: block; font: 600 14px/1.2 'Inter', sans-serif; color: var(--text-primary); }
.wu-plugin-meta { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; margin-top: 4px; }
.wu-version-tag { font: 500 12px/1 'Inter', sans-serif; color: var(--success); }
.wu-site-count { font: 400 12px/1 'Inter', sans-serif; color: var(--text-tertiary); }
.wu-plugin-actions { flex-shrink: 0; }
.wu-plugin-sites { border-top: 1px solid var(--border); padding: 12px 16px; background: var(--neutral-bg); }
.wu-sites-table { width: 100%; border-collapse: collapse; font-size: 13px; }
.wu-sites-table th { font: 500 11px/1 'Inter', sans-serif; text-transform: uppercase; letter-spacing: .05em; color: var(--text-tertiary); padding: 4px 8px; border-bottom: 1px solid var(--border); text-align: left; }
.wu-sites-table td { padding: 8px; border-bottom: 1px solid var(--border-soft); }
.wu-sites-table tr:last-child td { border-bottom: none; }
.wu-site-url { font-weight: 500; color: var(--text-primary); word-break: break-all; }
.wu-customer { color: var(--text-secondary); font-size: 12px; }
</style>
