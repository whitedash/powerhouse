<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import {
    IconLayoutDashboard,
    IconUsers,
    IconReceipt,
    IconCreditCard,
    IconChartLine,
    IconToolsKitchen2,
    IconClipboardList,
    IconBuildingStore,
    IconMessage2,
    IconHeadset,
    IconSettings,
    IconHelpCircle,
    IconDots,
    IconSearch,
    IconBell,
    IconChevronDown,
} from '@tabler/icons-vue';

const props = defineProps({
    title: { type: String, default: '' },
    breadcrumbs: { type: Array, default: () => [] },
    activeNav: { type: String, default: '' },
});

const sections = [
    {
        label: 'Workspace',
        items: [
            { key: 'overview',      label: 'Overview',      href: '/',           icon: IconLayoutDashboard },
            { key: 'customers',     label: 'Customers',     href: '/customers',  icon: IconUsers,         count: '847' },
            { key: 'invoices',      label: 'Invoices',      href: '/invoices',   icon: IconReceipt,       count: '7',  countClass: 'amber' },
            { key: 'subscriptions', label: 'Subscriptions', href: '#',           icon: IconCreditCard },
            { key: 'analytics',     label: 'Analytics',     href: '#',           icon: IconChartLine },
        ],
    },
    {
        label: 'Products',
        items: [
            { key: 'maavelus',     label: 'Maavelus',      href: '#', icon: IconToolsKitchen2 },
            { key: 'myorderpad',   label: 'MyOrderPad',    href: '#', icon: IconClipboardList },
            { key: 'whitedash_b2b', label: 'Whitedash B2B', href: '#', icon: IconBuildingStore },
            { key: 'smscube',      label: 'SMScube',       href: '#', icon: IconMessage2 },
        ],
    },
    {
        label: 'Account',
        items: [
            { key: 'support',  label: 'Support',      href: '/support',  icon: IconHeadset,    count: '3', countClass: 'red' },
            { key: 'settings', label: 'Settings',     href: '/settings', icon: IconSettings },
            { key: 'help',     label: 'Help & docs',  href: '#',         icon: IconHelpCircle },
        ],
    },
];

const me = {
    initials: 'AP',
    name: 'Apostolos P.',
    role: 'Super Admin',
};

const visibleCrumbs = computed(() => props.breadcrumbs ?? []);
const lastCrumbIndex = computed(() => visibleCrumbs.value.length - 1);
</script>

<template>
    <div class="app">
        <aside class="sidebar">
            <Link href="/" class="logo">
                <div class="brand-mark">W</div>
                <div>
                    <div class="logo-name">Powerhouse</div>
                    <div class="logo-sub">Whitedash</div>
                </div>
            </Link>

            <template v-for="section in sections" :key="section.label">
                <div class="nav-section">{{ section.label }}</div>
                <Link
                    v-for="item in section.items"
                    :key="item.key"
                    :href="item.href"
                    class="nav-item"
                    :class="{ active: activeNav === item.key }"
                >
                    <component :is="item.icon" :size="18" stroke-width="1.75" />
                    <span>{{ item.label }}</span>
                    <span
                        v-if="item.count"
                        class="count"
                        :class="item.countClass"
                    >{{ item.count }}</span>
                </Link>
            </template>

            <div class="sidebar-spacer" />

            <div class="sidebar-user">
                <div class="avatar av-admin">{{ me.initials }}</div>
                <div>
                    <div class="name">{{ me.name }}</div>
                    <div class="role">{{ me.role }}</div>
                </div>
                <span class="dots" role="button" aria-label="Account menu">
                    <IconDots :size="18" stroke-width="1.75" />
                </span>
            </div>
        </aside>

        <main class="main">
            <div class="topbar">
                <div>
                    <div class="breadcrumb">
                        <template v-for="(crumb, i) in visibleCrumbs" :key="i">
                            <component
                                :is="crumb.href && i !== lastCrumbIndex ? 'a' : 'span'"
                                :class="{ current: i === lastCrumbIndex }"
                                v-bind="crumb.href && i !== lastCrumbIndex ? { href: crumb.href } : {}"
                            >{{ crumb.label }}</component>
                            <span v-if="i !== lastCrumbIndex" class="sep">/</span>
                        </template>
                    </div>
                    <div v-if="title" class="topbar-title">{{ title }}</div>
                </div>

                <div class="topbar-search">
                    <span class="search-icon"><IconSearch :size="18" stroke-width="1.75" /></span>
                    <input placeholder="Search customers, invoices, products…">
                    <span class="kbd">⌘K</span>
                </div>

                <button class="bell-btn" aria-label="Notifications">
                    <IconBell :size="20" stroke-width="1.75" />
                </button>

                <div class="divider" />

                <div class="avatar-wrap">
                    <div class="avatar av-admin">{{ me.initials }}</div>
                    <div>
                        <div class="name">{{ me.name }}</div>
                        <div class="role">{{ me.role }}</div>
                    </div>
                    <span class="chev"><IconChevronDown :size="16" stroke-width="1.75" /></span>
                </div>
            </div>

            <div class="content">
                <slot />
            </div>
        </main>
    </div>
</template>
