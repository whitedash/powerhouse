<script setup>
import { computed } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import {
    Menu,
    MenuButton,
    MenuItem,
    MenuItems,
} from '@headlessui/vue';
import {
    IconLayoutDashboard,
    IconUsers,
    IconReceipt,
    IconCreditCard,
    IconChartLine,
    IconUsersGroup,
    IconLayoutGrid,
    IconToolsKitchen2,
    IconFileInvoice,
    IconClipboardList,
    IconBuildingStore,
    IconMessage2,
    IconBox,
    IconHeadset,
    IconSettings,
    IconHelpCircle,
    IconDots,
    IconSearch,
    IconBell,
    IconChevronDown,
    IconLogout,
    IconUserCircle,
    IconCircleCheck,
    IconAlertTriangle,
    IconClock,
    IconTicket,
} from '@tabler/icons-vue';
import ToastContainer from '@/Components/UI/ToastContainer.vue';

const props = defineProps({
    title: { type: String, default: '' },
    breadcrumbs: { type: Array, default: () => [] },
    activeNav: { type: String, default: '' },
});

const page = usePage();

const me = computed(() => {
    const user = page.props.auth?.user;
    if (! user) {
        return { initials: '?', name: 'Guest', role: '', avatarClass: 'av-icon' };
    }
    const parts = (user.name || '').trim().split(/\s+/);
    const initials = ((parts[0]?.[0] ?? '') + (parts[1]?.[0] ?? parts[0]?.[1] ?? '')).toUpperCase() || '?';
    const role = user.role === 'super_admin' ? 'Super Admin'
        : user.role === 'staff' ? 'Whitedash Staff'
        : user.role === 'referrer' ? 'Referrer'
        : '';
    const avatarClass = user.role === 'super_admin' ? 'av-admin' : 'av-2';
    return { initials, name: user.name, role, avatarClass };
});

/*
 * Sidebar badges are NOT a stats panel — they're a notification system.
 * Every badge here means "needs your attention right now." Totals belong
 * on the dashboard KPI cards. Counts come from server-side cached
 * queries shared via HandleInertiaRequests::share().nav.
 */
const nav = computed(() => page.props.nav);

const invoicesBadge = computed(() => {
    const overdue = nav.value?.invoices_overdue ?? 0;
    const outstanding = nav.value?.invoices_outstanding ?? 0;
    if (overdue > 0) return { count: overdue, cls: 'red' };
    if (outstanding > 0) return { count: outstanding, cls: 'amber' };
    return null;
});

const supportBadge = computed(() => {
    const breached = nav.value?.support_sla_breached ?? 0;
    const open = nav.value?.support_open ?? 0;
    if (breached > 0) return { count: breached, cls: 'red' };
    if (open > 0) return { count: open, cls: 'amber' };
    return null;
});

/*
 * Bell-menu notifications. The same nav.* counts that drive the
 * sidebar badges drive this dropdown, so the operator can't see
 * 3 overdue invoices in the sidebar and a different number here.
 * Each entry carries a tabler icon, a tone (drives the chip colour),
 * a label, and an href to the filtered page that resolves it.
 */
const notifications = computed(() => {
    const n = nav.value;
    if (! n) return [];
    const out = [];
    if ((n.invoices_overdue ?? 0) > 0) {
        out.push({
            icon: IconReceipt,
            tone: 'red',
            label: `${n.invoices_overdue} overdue invoice${n.invoices_overdue === 1 ? '' : 's'}`,
            href: '/invoices?status=overdue',
        });
    }
    if ((n.invoices_outstanding ?? 0) > 0) {
        out.push({
            icon: IconClock,
            tone: 'amber',
            label: `${n.invoices_outstanding} outstanding invoice${n.invoices_outstanding === 1 ? '' : 's'}`,
            href: '/invoices?status=sent',
        });
    }
    if ((n.support_sla_breached ?? 0) > 0) {
        out.push({
            icon: IconAlertTriangle,
            tone: 'red',
            label: `${n.support_sla_breached} SLA breach${n.support_sla_breached === 1 ? '' : 'es'}`,
            href: '/support?status=open',
        });
    }
    if ((n.support_open ?? 0) > 0) {
        out.push({
            icon: IconTicket,
            tone: 'blue',
            label: `${n.support_open} open ticket${n.support_open === 1 ? '' : 's'}`,
            href: '/support',
        });
    }

    return out;
});
const notifTotal = computed(() => notifications.value.length);

/*
 * Sidebar Products are server-driven (HandleInertiaRequests.share()
 * → nav_products). The slug → tabler-icon mapping lives in PHP; the
 * client only needs to resolve the icon name back to the imported
 * component so it can keep rendering via <component :is>.
 */
const PRODUCT_ICONS = {
    'tools-kitchen-2': IconToolsKitchen2,
    'clipboard-list': IconClipboardList,
    'building-store': IconBuildingStore,
    'message-2': IconMessage2,
    'box': IconBox,
};

const productItems = computed(() => {
    const raw = page.props.nav_products ?? [];

    return raw.map((p) => ({
        key: p.slug,
        label: p.name,
        href: p.route,
        icon: PRODUCT_ICONS[p.icon] ?? IconBox,
    }));
});

const hasMaavelus = computed(() => (page.props.nav_products ?? []).some((p) => p.slug === 'maavelus'));

const sections = computed(() => {
    const products = [...productItems.value];
    // Statements is a sub-item under Maavelus — only surface it if
    // Maavelus is actually in the active product set. Insertion
    // immediately after Maavelus preserves the visual nesting.
    if (hasMaavelus.value) {
        const idx = products.findIndex((p) => p.key === 'maavelus');
        products.splice(idx + 1, 0, {
            key: 'maavelus-statements',
            label: 'Statements',
            href: '/maavelus/statements',
            icon: IconFileInvoice,
            sub: true,
        });
    }

    return [
        {
            label: 'Workspace',
            items: [
                { key: 'overview',      label: 'Overview',      href: '/',           icon: IconLayoutDashboard },
                { key: 'customers',     label: 'Customers',     href: '/customers',  icon: IconUsers },
                { key: 'invoices',      label: 'Invoices',      href: '/invoices',   icon: IconReceipt,    badge: invoicesBadge.value },
                { key: 'subscriptions', label: 'Subscriptions', href: '/subscriptions', icon: IconCreditCard },
                { key: 'analytics',     label: 'Analytics',     href: '/analytics',  icon: IconChartLine },
                { key: 'referrers',     label: 'Referrers',     href: '/referrers',  icon: IconUsersGroup },
                { key: 'provisioning',  label: 'Provisioning',  href: '/provisioning', icon: IconLayoutGrid },
            ],
        },
        ...(products.length > 0 ? [{ label: 'Products', items: products }] : []),
        {
            label: 'Account',
            items: [
                { key: 'support',  label: 'Support',     href: '/support',  icon: IconHeadset,   badge: supportBadge.value },
                { key: 'settings', label: 'Settings',    href: '/settings', icon: IconSettings },
                { key: 'help',     label: 'Help & docs', href: '/help',     icon: IconHelpCircle },
            ],
        },
    ];
});

const visibleCrumbs = computed(() => props.breadcrumbs ?? []);
const lastCrumbIndex = computed(() => visibleCrumbs.value.length - 1);

function logout() {
    router.post('/logout');
}
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
                    :class="{ active: activeNav === item.key, 'nav-sub': item.sub }"
                >
                    <component :is="item.icon" :size="item.sub ? 16 : 18" stroke-width="1.75" />
                    <span>{{ item.label }}</span>
                    <span
                        v-if="item.badge"
                        class="count"
                        :class="item.badge.cls"
                    >{{ item.badge.count }}</span>
                </Link>
            </template>

            <div class="sidebar-spacer" />

            <Menu as="div" class="sidebar-user-menu">
                <div class="sidebar-user">
                    <div class="avatar" :class="me.avatarClass">{{ me.initials }}</div>
                    <div>
                        <div class="name">{{ me.name }}</div>
                        <div class="role">{{ me.role }}</div>
                    </div>
                    <MenuButton class="dots" aria-label="Account menu">
                        <IconDots :size="18" stroke-width="1.75" />
                    </MenuButton>
                </div>
                <MenuItems class="user-menu-popover">
                    <MenuItem v-slot="{ active }">
                        <button
                            type="button"
                            class="user-menu-item"
                            :class="{ active }"
                            @click="logout"
                        >
                            <IconLogout :size="16" stroke-width="1.75" />
                            <span>Sign out</span>
                        </button>
                    </MenuItem>
                </MenuItems>
            </Menu>
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

                <div class="topbar-actions">
                    <slot name="topbar-actions" />
                    <div v-if="$slots['topbar-actions']" class="divider-v" />

                    <div class="topbar-search">
                        <span class="search-icon"><IconSearch :size="18" stroke-width="1.75" /></span>
                        <input placeholder="Search customers, invoices, products…">
                        <span class="kbd">⌘K</span>
                    </div>

                    <Menu as="div" class="bell-menu">
                        <MenuButton class="bell-btn" aria-label="Notifications">
                            <IconBell :size="20" stroke-width="1.75" />
                            <span v-if="notifTotal > 0" class="bell-dot" />
                        </MenuButton>
                        <MenuItems class="bell-popover">
                            <div class="bell-popover-head">
                                <span>Notifications</span>
                                <span v-if="notifTotal > 0" class="bell-popover-count">{{ notifTotal }}</span>
                            </div>
                            <div v-if="notifications.length" class="bell-popover-list">
                                <Link
                                    v-for="(n, i) in notifications"
                                    :key="i"
                                    :href="n.href"
                                    class="bell-row"
                                >
                                    <span class="bell-row-icon" :class="n.tone">
                                        <component :is="n.icon" :size="14" stroke-width="2" />
                                    </span>
                                    <span class="bell-row-text">{{ n.label }}</span>
                                </Link>
                            </div>
                            <div v-else class="bell-popover-empty">
                                <IconCircleCheck :size="22" stroke-width="2" />
                                <span>All caught up</span>
                            </div>
                            <Link href="/analytics" class="bell-popover-foot">
                                View activity
                            </Link>
                        </MenuItems>
                    </Menu>

                    <div class="divider-v" />

                <Menu as="div" class="topbar-user-menu">
                    <MenuButton class="avatar-wrap">
                        <div class="avatar" :class="me.avatarClass">{{ me.initials }}</div>
                        <div>
                            <div class="name">{{ me.name }}</div>
                            <div class="role">{{ me.role }}</div>
                        </div>
                        <span class="chev"><IconChevronDown :size="16" stroke-width="1.75" /></span>
                    </MenuButton>
                    <MenuItems class="user-menu-popover topbar-user-popover">
                        <MenuItem v-slot="{ active }">
                            <Link
                                href="/account"
                                class="user-menu-item"
                                :class="{ active }"
                            >
                                <IconUserCircle :size="16" stroke-width="1.75" />
                                <span>My account</span>
                            </Link>
                        </MenuItem>
                        <div class="user-menu-divider" />
                        <MenuItem v-slot="{ active }">
                            <button
                                type="button"
                                class="user-menu-item danger"
                                :class="{ active }"
                                @click="logout"
                            >
                                <IconLogout :size="16" stroke-width="1.75" />
                                <span>Sign out</span>
                            </button>
                        </MenuItem>
                    </MenuItems>
                </Menu>
                </div>
            </div>

            <div class="content">
                <slot />
            </div>
        </main>

        <ToastContainer />
    </div>
</template>

<style scoped>
.sidebar-user-menu {
    position: relative;
}

.topbar-user-menu {
    position: relative;
}

.user-menu-popover {
    position: absolute;
    z-index: 30;
    min-width: 180px;
    background: var(--card-bg);
    border: 1px solid var(--border);
    border-radius: var(--radius-md);
    box-shadow: var(--shadow-md);
    padding: 4px;
    outline: 0;
}

.sidebar-user-menu .user-menu-popover {
    bottom: calc(100% + 6px);
    left: 10px;
    right: 10px;
}

.topbar-user-popover {
    top: calc(100% + 6px);
    right: 0;
}

.user-menu-item {
    width: 100%;
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 8px 10px;
    border-radius: 6px;
    background: transparent;
    border: 0;
    cursor: pointer;
    color: var(--text-primary);
    font: 500 13px/1.2 'Inter', sans-serif;
    text-align: left;
}

.user-menu-item.active,
.user-menu-item:hover {
    background: var(--neutral-bg);
    color: var(--accent);
}
</style>
