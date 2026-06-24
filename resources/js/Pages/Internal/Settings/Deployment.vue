<script setup>
/**
 * Settings → Deployment (super_admin). Preview pending migrations, then run
 * migrations / clear caches from the UI — the authenticated replacement for
 * the public migrate.php / clear-cache.php helper scripts. Every action is
 * confirmed, throttled, and logged server-side.
 */
import { ref, computed } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import { IconRefresh, IconDatabase, IconTrash, IconPlayerPlay, IconShieldLock, IconCircleCheck, IconAlertTriangle, IconChevronLeft, IconChevronRight } from '@tabler/icons-vue';
import SettingsLayout from '@/Layouts/SettingsLayout.vue';
import ConfirmModal from '@/Components/UI/ConfirmModal.vue';

const props = defineProps({
    status_output: { type: String, default: '' },
    // Paginated run history, newest-first (migrations.id desc), 10/page.
    ran_migrations: { type: Object, default: () => ({ data: [], links: [], from: 0, to: 0, total: 0 }) },
    pending: { type: Array, default: () => [] },
    pending_count: { type: Number, default: 0 },
    last_run: { type: Object, default: null },
});

/* ─── Pagination (run history) ─── */
const pageMeta = computed(() => ({
    from: props.ran_migrations.from ?? 0,
    to: props.ran_migrations.to ?? 0,
    total: props.ran_migrations.total ?? 0,
    links: props.ran_migrations.links ?? [],
}));
function navigateToLink(url) {
    if (!url) return;
    router.visit(url, { preserveScroll: true, preserveState: true });
}

const processing = ref(false);

/* ─── Confirm + run ─── */
const ACTIONS = {
    migrate: {
        url: '/settings/deployment/migrate',
        title: 'Run pending migrations?',
        message: 'This runs `php artisan migrate --force` against the live database. Make sure you have a recent backup.',
        confirmLabel: 'Run migrations',
        variant: 'warning',
    },
    'clear-cache': {
        url: '/settings/deployment/clear-cache',
        title: 'Clear all caches?',
        message: 'Runs `optimize:clear` (config, route, view, event, compiled, cache). Safe, but the next requests will be a little slower while caches rebuild.',
        confirmLabel: 'Clear caches',
        variant: 'primary',
    },
    'run-both': {
        url: '/settings/deployment/run-both',
        title: 'Run migrations and clear caches?',
        message: 'Runs migrations (--force) then clears all caches. The usual post-deploy step.',
        confirmLabel: 'Run both',
        variant: 'warning',
    },
    'seed-roles': {
        url: '/settings/deployment/seed-roles',
        title: 'Seed roles & permissions?',
        message: 'Runs RolesAndPermissionsSeeder — creates the staff/super_admin roles, permissions, and scope rows, and backfills existing users into Spatie. Idempotent: safe to re-run (it converges, never duplicates). Run this once after migrations during the roles cutover.',
        confirmLabel: 'Seed roles',
        variant: 'warning',
    },
};

const confirmOpen = ref(false);
const pendingAction = ref(null);
const activeConfig = computed(() => (pendingAction.value ? ACTIONS[pendingAction.value] : { title: '', message: '', confirmLabel: 'Confirm', variant: 'primary' }));

function ask(action) {
    pendingAction.value = action;
    confirmOpen.value = true;
}
function runConfirmed() {
    const cfg = ACTIONS[pendingAction.value];
    if (!cfg) return;
    router.post(cfg.url, {}, {
        preserveScroll: true,
        onStart: () => { processing.value = true; },
        onFinish: () => { processing.value = false; confirmOpen.value = false; pendingAction.value = null; },
    });
}

function refresh() {
    router.reload({ only: ['status_output', 'ran_migrations', 'pending', 'pending_count'] });
}
</script>

<template>
    <Head title="Deployment · Settings" />

    <SettingsLayout active-section="deployment">
        <div class="dep">
            <header class="dep-head">
                <div>
                    <h2>Deployment</h2>
                    <p class="muted">Run pending migrations and clear caches after a deploy. Super-admin only; every action is logged.</p>
                </div>
                <button type="button" class="btn btn-ghost btn-sm" :disabled="processing" @click="refresh">
                    <IconRefresh :size="14" stroke-width="1.75" /> Refresh
                </button>
            </header>

            <!-- Pending migrations summary -->
            <section class="card dep-card">
                <div class="dep-card-head">
                    <h3><IconDatabase :size="16" stroke-width="1.75" /> Migrations</h3>
                    <span class="badge" :class="pending_count > 0 ? 'badge-pending' : 'badge-active'">
                        {{ pending_count }} pending
                    </span>
                </div>

                <!-- Pending (not yet run) — what `Run migrations` will apply. -->
                <div v-if="pending.length" class="dep-pending">
                    <p class="muted small">Pending — applied on next run:</p>
                    <ul class="dep-pending-list">
                        <li v-for="p in pending" :key="p.name" class="mono">{{ p.name }}</li>
                    </ul>
                </div>

                <!-- Run history — newest-first by run order, 10 per page. -->
                <table v-if="ran_migrations.data.length" class="dep-tbl">
                    <thead><tr><th>Migration</th><th style="width:90px;">Batch</th><th style="width:90px;">Status</th></tr></thead>
                    <tbody>
                        <tr v-for="m in ran_migrations.data" :key="m.id">
                            <td class="mono">{{ m.name }}</td>
                            <td class="mono">{{ m.batch }}</td>
                            <td><span class="badge badge-sm badge-active">Ran</span></td>
                        </tr>
                    </tbody>
                </table>
                <p v-else class="muted small">No migrations have been run yet.</p>

                <!-- Pagination (run history) -->
                <div v-if="ran_migrations.data.length" class="tbl-foot">
                    <div class="info">
                        Showing <strong style="color: var(--text-primary); font-weight: 600;">{{ pageMeta.from }} – {{ pageMeta.to }}</strong>
                        of <strong style="color: var(--text-primary); font-weight: 600;">{{ pageMeta.total }}</strong> run
                    </div>
                    <div class="right">
                        <template v-for="(link, i) in pageMeta.links" :key="i">
                            <button
                                v-if="link.label.includes('Previous')"
                                type="button"
                                class="pg-btn"
                                :disabled="!link.url"
                                @click="navigateToLink(link.url)"
                            >
                                <IconChevronLeft :size="14" stroke-width="1.75" />
                                Previous
                            </button>
                            <button
                                v-else-if="link.label.includes('Next')"
                                type="button"
                                class="pg-btn"
                                :disabled="!link.url"
                                @click="navigateToLink(link.url)"
                            >
                                Next
                                <IconChevronRight :size="14" stroke-width="1.75" />
                            </button>
                            <span v-else-if="link.label === '...'" style="color: var(--text-tertiary); padding: 0 4px;">…</span>
                            <button
                                v-else
                                type="button"
                                class="pg-btn"
                                :class="{ active: link.active }"
                                :disabled="!link.url"
                                @click="navigateToLink(link.url)"
                            >{{ link.label }}</button>
                        </template>
                    </div>
                </div>
            </section>

            <!-- Actions -->
            <section class="card dep-card">
                <div class="dep-card-head"><h3>Actions</h3></div>
                <div class="dep-actions">
                    <button type="button" class="btn btn-primary" :disabled="processing" @click="ask('migrate')">
                        <IconDatabase :size="15" stroke-width="1.75" /> Run migrations
                    </button>
                    <button type="button" class="btn btn-secondary" :disabled="processing" @click="ask('clear-cache')">
                        <IconTrash :size="15" stroke-width="1.75" /> Clear caches
                    </button>
                    <button type="button" class="btn btn-secondary" :disabled="processing" @click="ask('run-both')">
                        <IconPlayerPlay :size="15" stroke-width="1.75" /> Run both
                    </button>
                    <button type="button" class="btn btn-secondary" :disabled="processing" @click="ask('seed-roles')">
                        <IconShieldLock :size="15" stroke-width="1.75" /> Seed roles
                    </button>
                </div>
                <p class="muted small dep-actions-note">
                    <strong>Seed roles</strong> runs RolesAndPermissionsSeeder (the roles cutover step — run once after migrations).
                    It is <strong>idempotent</strong>: safe to re-run, it converges without duplicating.
                </p>
            </section>

            <!-- Last run output -->
            <section v-if="last_run" class="card dep-card">
                <div class="dep-card-head">
                    <h3>
                        <IconCircleCheck v-if="last_run.ok" :size="16" stroke-width="1.75" class="ok" />
                        <IconAlertTriangle v-else :size="16" stroke-width="1.75" class="bad" />
                        Last run — {{ last_run.action }}
                    </h3>
                    <span class="muted small">{{ last_run.ran_by }}</span>
                </div>
                <pre class="dep-output">{{ last_run.output }}</pre>
            </section>

            <!-- Raw migrate:status preview -->
            <section v-if="status_output" class="card dep-card">
                <div class="dep-card-head"><h3>migrate:status</h3></div>
                <pre class="dep-output">{{ status_output }}</pre>
            </section>
        </div>

        <ConfirmModal
            v-model:show="confirmOpen"
            :title="activeConfig.title"
            :message="activeConfig.message"
            :confirm-label="activeConfig.confirmLabel"
            :variant="activeConfig.variant"
            @confirm="runConfirmed"
        />
    </SettingsLayout>
</template>

<style scoped>
.dep { display: flex; flex-direction: column; gap: 16px; }
.dep-head { display: flex; justify-content: space-between; align-items: flex-start; gap: 16px; }
.dep-head h2 { margin: 0 0 4px; }
.dep-card { padding: 16px 18px; }
.dep-card-head { display: flex; align-items: center; justify-content: space-between; gap: 12px; margin-bottom: 12px; }
.dep-card-head h3 { display: flex; align-items: center; gap: 8px; margin: 0; font-size: 14px; }
.dep-card-head .ok { color: var(--success, #10b981); }
.dep-card-head .bad { color: var(--warning, #b45309); }
.dep-tbl { width: 100%; border-collapse: collapse; }
.dep-tbl th { text-align: left; font-size: 12px; color: var(--text-secondary); padding: 6px 8px; border-bottom: 1px solid var(--border); }
.dep-tbl td { padding: 7px 8px; border-bottom: 1px solid var(--border); font-size: 13px; }
.dep-pending { margin-bottom: 14px; }
.dep-pending-list { margin: 6px 0 0; padding-left: 18px; display: flex; flex-direction: column; gap: 3px; }
.dep-pending-list li { font-weight: 600; color: var(--warning, #b45309); }
.mono { font-family: 'JetBrains Mono', ui-monospace, monospace; font-size: 12px; }
.dep-actions { display: flex; flex-wrap: wrap; gap: 10px; }
.dep-actions-note { margin: 10px 0 0; line-height: 1.5; }
.dep-output { background: var(--ink, #111b28); color: var(--on-dark, #eef2f7); border-radius: var(--radius-md, 8px); padding: 12px 14px; font-family: 'JetBrains Mono', ui-monospace, monospace; font-size: 12px; line-height: 1.5; overflow-x: auto; white-space: pre-wrap; word-break: break-word; max-height: 360px; overflow-y: auto; }
</style>
