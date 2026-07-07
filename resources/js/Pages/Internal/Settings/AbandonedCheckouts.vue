<script setup>
import { Head, router } from '@inertiajs/vue3';
import SettingsLayout from '@/Layouts/SettingsLayout.vue';

const props = defineProps({
    attempts: { type: Object, required: true },
});

function formatDate(iso) {
    if (! iso) return '—';
    return new Date(iso).toLocaleString('en-GB', {
        day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit',
    });
}

function goTo(url) {
    if (url) router.visit(url, { preserveScroll: true });
}
</script>

<template>
    <Head title="Abandoned checkouts" />

    <SettingsLayout active-section="abandoned-checkouts">
        <section class="table-card">
            <table>
                <thead>
                    <tr>
                        <th>Plan</th>
                        <th>Visitor</th>
                        <th>Started</th>
                        <th>Marked abandoned</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="a in attempts.data" :key="a.id">
                        <td>
                            <span style="font-weight: 600;">{{ a.product ?? '—' }}</span>
                            <span v-if="a.plan" style="color: var(--text-secondary);"> / {{ a.plan }}</span>
                        </td>
                        <td>
                            {{ a.purchaser_name }}
                            <span style="color: var(--text-tertiary);">&lt;{{ a.purchaser_email }}&gt;</span>
                            <div v-if="a.purchaser_company || a.purchaser_phone" style="font-size: 12px; color: var(--text-tertiary); margin-top: 2px;">
                                <span v-if="a.purchaser_company">{{ a.purchaser_company }}</span>
                                <span v-if="a.purchaser_company && a.purchaser_phone"> · </span>
                                <span v-if="a.purchaser_phone">{{ a.purchaser_phone }}</span>
                            </div>
                        </td>
                        <td>{{ formatDate(a.started_at) }}</td>
                        <td>{{ formatDate(a.abandoned_at) }}</td>
                    </tr>
                    <tr v-if="attempts.data.length === 0">
                        <td colspan="4" style="text-align: center; padding: 32px; color: var(--text-tertiary);">
                            No abandoned checkouts — every started purchase either completed or is still within its 24-hour window.
                        </td>
                    </tr>
                </tbody>
            </table>
            <div v-if="attempts.last_page > 1" style="display: flex; gap: 8px; justify-content: flex-end; padding: 12px 16px;">
                <button type="button" class="btn btn-ghost btn-sm" :disabled="! attempts.prev_page_url" @click="goTo(attempts.prev_page_url)">
                    ← Previous
                </button>
                <button type="button" class="btn btn-ghost btn-sm" :disabled="! attempts.next_page_url" @click="goTo(attempts.next_page_url)">
                    Next →
                </button>
            </div>
        </section>
    </SettingsLayout>
</template>
