<script setup>
/**
 * Full-page notification history. The bell dropdown shows the latest 15;
 * this is the paginated archive. Opening this page marks everything read
 * server-side (NotificationController@index), but the payload is built
 * before that flip so this first render still highlights what was unread.
 *
 * The All / Unread toggle filters the current page's rows client-side.
 */
import { computed, ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import {
    IconBell, IconCheckbox, IconClock, IconFlag, IconAlertTriangle,
    IconUserPlus, IconHeadset, IconCheck, IconExternalLink,
    IconChevronLeft, IconChevronRight,
} from '@tabler/icons-vue';
import InternalLayout from '@/Layouts/InternalLayout.vue';

const props = defineProps({
    notifications: { type: Object, required: true },
});

const filter = ref('all');
const rows = computed(() => {
    const data = props.notifications.data ?? [];
    return filter.value === 'unread' ? data.filter((n) => !n.read) : data;
});

const NOTIF_ICONS = {
    'ti-checkbox': IconCheckbox,
    'ti-clock': IconClock,
    'ti-flag': IconFlag,
    'ti-alert-triangle': IconAlertTriangle,
    'ti-user-plus': IconUserPlus,
    'ti-headset': IconHeadset,
    'ti-check': IconCheck,
    'ti-bell': IconBell,
};
function notifIcon(name) {
    return NOTIF_ICONS[name] ?? IconBell;
}

function navigateToLink(url) {
    if (url) {
        router.get(url, {}, { preserveScroll: true, preserveState: true });
    }
}
</script>

<template>
    <Head title="Notifications" />

    <InternalLayout
        title="Notifications"
        :breadcrumbs="[{ label: 'Powerhouse', href: '/' }, { label: 'Notifications' }]"
    >
        <div class="notifications-page">
            <div class="notif-filter">
                <button type="button" :class="{ active: filter === 'all' }" @click="filter = 'all'">All</button>
                <button type="button" :class="{ active: filter === 'unread' }" @click="filter = 'unread'">Unread</button>
            </div>

            <div class="notif-list-card">
                <div v-if="rows.length === 0" class="notif-empty">
                    {{ filter === 'unread' ? 'No unread notifications.' : 'No notifications yet.' }}
                </div>

                <div
                    v-for="n in rows"
                    :key="n.id"
                    class="notif-page-row"
                    :class="{ unread: !n.read }"
                >
                    <span class="notif-icon" :style="{ background: n.colour + '22', color: n.colour }">
                        <component :is="notifIcon(n.icon)" :size="16" stroke-width="2" />
                    </span>
                    <div class="notif-body">
                        <div class="notif-title" :class="{ strong: !n.read }">{{ n.title }}</div>
                        <div class="notif-message">{{ n.message }}</div>
                        <div class="notif-time">{{ n.created_at }} · {{ n.time_ago }}</div>
                    </div>
                    <Link v-if="n.url" :href="n.url" class="notif-open-link" title="Open">
                        <IconExternalLink :size="16" stroke-width="2" />
                    </Link>
                </div>
            </div>

            <div v-if="notifications.last_page > 1" class="notif-pagination">
                <template v-for="(link, i) in notifications.links" :key="i">
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
                    <span v-else-if="link.label === '...'" class="pg-ellipsis">…</span>
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
    </InternalLayout>
</template>
