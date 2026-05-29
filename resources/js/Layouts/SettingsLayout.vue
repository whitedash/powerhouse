<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import {
    IconBuildingCog,
    IconUsers,
    IconCreditCard,
    IconShieldCheck,
    IconBell,
    IconPlug,
    IconFileText,
    IconLayoutGrid,
    IconTrash,
} from '@tabler/icons-vue';
import InternalLayout from '@/Layouts/InternalLayout.vue';

const props = defineProps({
    title: { type: String, default: 'Settings' },
    activeSection: { type: String, required: true },
});

const items = computed(() => [
    { key: 'general', label: 'General', href: '/settings', icon: IconBuildingCog },
    { key: 'team', label: 'Team members', href: '/settings/team', icon: IconUsers },
    { key: 'billing-entities', label: 'Billing entities', href: '/settings/billing-entities', icon: IconCreditCard },
    { key: 'security', label: 'Security', href: '/settings/security', icon: IconShieldCheck },
    { key: 'notifications', label: 'Notifications', href: '/settings/notifications', icon: IconBell },
    { key: 'integrations', label: 'Integrations', href: '/settings/integrations', icon: IconPlug },
    { key: 'audit-log', label: 'Audit log', href: '/settings/audit-log', icon: IconFileText },
    { key: 'products', label: 'Products', href: '/settings/products', icon: IconLayoutGrid },
    { key: 'danger', label: 'Danger zone', href: '/settings/danger', icon: IconTrash, danger: true },
]);

const breadcrumbs = computed(() => {
    const active = items.value.find((i) => i.key === props.activeSection);

    return [
        { label: 'Settings', href: '/settings' },
        ...(active && active.key !== 'general' ? [{ label: active.label }] : []),
    ];
});
</script>

<template>
    <InternalLayout :title="title" :breadcrumbs="breadcrumbs" active-nav="settings">
        <template #topbar-actions>
            <slot name="topbar-actions" />
        </template>

        <div class="settings-shell">
            <aside class="set-nav">
                <div class="lbl">Settings</div>
                <Link
                    v-for="item in items"
                    :key="item.key"
                    :href="item.href"
                    class="set-item"
                    :class="{ active: activeSection === item.key, danger: item.danger }"
                >
                    <component :is="item.icon" :size="17" stroke-width="1.75" />
                    <span>{{ item.label }}</span>
                    <span v-if="item.badge" class="ct">{{ item.badge }}</span>
                </Link>
            </aside>

            <section class="set-body">
                <slot />
            </section>
        </div>
    </InternalLayout>
</template>
