<script setup>
import { computed, ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { IconChevronDown, IconExternalLink } from '@tabler/icons-vue';
import SettingsLayout from '@/Layouts/SettingsLayout.vue';

const props = defineProps({
    entries: { type: Array, default: () => [] },
});

const rows = computed(() => props.entries);

// Per-row disclosure state for the before/after diff. Keying on
// log id keeps the open set stable across Vue rerenders.
const expanded = ref(new Set());
function toggleDiff(id) {
    if (expanded.value.has(id)) expanded.value.delete(id);
    else expanded.value.add(id);
    // Re-assign so Vue picks up the mutation on the Set instance.
    expanded.value = new Set(expanded.value);
}
function isExpanded(id) {
    return expanded.value.has(id);
}

function formatDate(iso) {
    if (! iso) return '—';
    return new Date(iso).toLocaleString('en-GB', {
        day: 'numeric', month: 'short', year: 'numeric',
        hour: '2-digit', minute: '2-digit',
    });
}

function roleLabel(role) {
    if (! role) return 'System';
    const map = { super_admin: 'Super Admin', staff: 'Staff', customer: 'Customer', referrer: 'Referrer', system: 'System' };
    return map[role] || role;
}

function jsonPreview(payload) {
    if (! payload) return '—';
    try {
        return JSON.stringify(payload, null, 2);
    } catch {
        return String(payload);
    }
}

function openRow(row) {
    if (row.url) router.visit(row.url);
}
</script>

<template>
    <Head title="Audit log" />

    <SettingsLayout title="Audit log" active-section="audit-log">
        <h1 class="set-title">Audit log</h1>
        <p style="color: var(--text-secondary); font: 400 13px/1.6 'Inter', sans-serif; margin-bottom: 14px;">
            Latest 200 events from <code>activity_log</code>. Rows with a linked entity are clickable; tap the chevron to inspect the before/after diff.
        </p>

        <div class="audit-table-wrap">
            <table class="audit-table">
                <thead>
                    <tr>
                        <th style="width: 150px;">When</th>
                        <th style="width: 110px;">Who</th>
                        <th>Event</th>
                        <th style="width: 60px;" />
                    </tr>
                </thead>
                <tbody>
                    <tr v-if="! rows.length">
                        <td colspan="4" class="audit-empty">No activity yet.</td>
                    </tr>
                    <template v-for="row in rows" :key="row.id">
                        <tr class="audit-row" :class="{ clickable: !! row.url }" @click="openRow(row)">
                            <td class="audit-when">
                                <div>{{ formatDate(row.created_at) }}</div>
                                <div class="audit-time-ago">{{ row.time_ago }}</div>
                            </td>
                            <td class="audit-who">
                                <div class="audit-user">{{ row.user_name }}</div>
                                <div class="audit-role">{{ roleLabel(row.user_role) }}</div>
                            </td>
                            <td class="audit-event">
                                <div class="audit-label">{{ row.label }}</div>
                                <div class="audit-action-code">{{ row.action }}</div>
                            </td>
                            <td class="audit-actions" @click.stop>
                                <button
                                    v-if="row.has_diff"
                                    type="button"
                                    class="icon-btn"
                                    :aria-label="isExpanded(row.id) ? 'Hide diff' : 'Show diff'"
                                    @click="toggleDiff(row.id)"
                                >
                                    <IconChevronDown
                                        :size="14"
                                        stroke-width="1.75"
                                        :class="{ 'audit-chevron-open': isExpanded(row.id) }"
                                    />
                                </button>
                                <Link v-if="row.url" :href="row.url" class="audit-view">
                                    <IconExternalLink :size="13" stroke-width="1.75" />
                                </Link>
                            </td>
                        </tr>
                        <tr v-if="row.has_diff && isExpanded(row.id)" class="audit-diff-row">
                            <td colspan="4">
                                <div class="audit-diff">
                                    <div>
                                        <div class="audit-diff-label">Before</div>
                                        <pre>{{ jsonPreview(row.before) }}</pre>
                                    </div>
                                    <div>
                                        <div class="audit-diff-label">After</div>
                                        <pre>{{ jsonPreview(row.after) }}</pre>
                                    </div>
                                </div>
                            </td>
                        </tr>
                    </template>
                </tbody>
            </table>
        </div>
    </SettingsLayout>
</template>
