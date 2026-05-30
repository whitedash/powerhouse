<script setup>
/**
 * Per-form submissions list. Plain table — most recent 200,
 * expandable to reveal the raw JSON payload. The Lead column
 * deep-links to /leads/{id} if a workflow created one.
 */
import { ref } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import { IconArrowLeft, IconChevronDown, IconChevronRight, IconExternalLink } from '@tabler/icons-vue';
import InternalLayout from '@/Layouts/InternalLayout.vue';

const props = defineProps({
    form: { type: Object, required: true },
    submissions: { type: Array, required: true },
});

const expanded = ref(new Set());
function toggle(id) {
    if (expanded.value.has(id)) expanded.value.delete(id);
    else expanded.value.add(id);
    expanded.value = new Set(expanded.value);
}

function fmt(dt) {
    if (!dt) return '—';
    return new Date(dt).toLocaleString('en-GB', {
        day: '2-digit', month: 'short', year: 'numeric',
        hour: '2-digit', minute: '2-digit',
    });
}
</script>

<template>
    <InternalLayout :title="`${form.name} — submissions`" active-nav="forms">
        <Head :title="`${form.name} submissions`" />

        <div class="form-submissions page-shell">
            <div class="page-head">
                <div>
                    <Link href="/forms" class="ghost-link inline">
                        <IconArrowLeft :size="14" stroke-width="2" /> Forms
                    </Link>
                    <h1>{{ form.name }}</h1>
                    <p class="muted">Latest submissions to /{{ form.slug }}.</p>
                </div>
            </div>

            <div v-if="submissions.length === 0" class="empty-card">
                <h3>No submissions yet</h3>
                <p class="muted">Once someone posts to the form they'll appear here.</p>
            </div>

            <div v-else class="card">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th style="width: 32px"></th>
                            <th>Submitted</th>
                            <th>IP</th>
                            <th>Lead</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <template v-for="s in submissions" :key="s.id">
                            <tr class="clickable" @click="toggle(s.id)">
                                <td>
                                    <IconChevronDown v-if="expanded.has(s.id)" :size="14" stroke-width="2" />
                                    <IconChevronRight v-else :size="14" stroke-width="2" />
                                </td>
                                <td>{{ fmt(s.created_at) }}</td>
                                <td><code class="small">{{ s.ip_address || '—' }}</code></td>
                                <td>
                                    <Link v-if="s.lead" :href="`/leads/${s.lead.id}`" class="ghost-link inline" @click.stop>
                                        {{ s.lead.name }} <IconExternalLink :size="12" />
                                    </Link>
                                    <span v-else class="muted">—</span>
                                </td>
                                <td>
                                    <span :class="['status-chip', 'sc-sub-' + s.status]">{{ s.status }}</span>
                                </td>
                            </tr>
                            <tr v-if="expanded.has(s.id)" class="expanded-row">
                                <td colspan="5">
                                    <div class="submission-detail">
                                        <div v-if="s.referrer_url" class="submission-meta muted small">
                                            Referrer: <code>{{ s.referrer_url }}</code>
                                        </div>
                                        <pre class="submission-payload">{{ JSON.stringify(s.data, null, 2) }}</pre>
                                    </div>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>
        </div>
    </InternalLayout>
</template>
