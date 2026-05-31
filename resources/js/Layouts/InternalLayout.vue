<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
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
    IconChevronRight,
    IconSettings2,
    IconLogout,
    IconUserCircle,
    IconCircleCheck,
    IconAlertTriangle,
    IconClock,
    IconUser,
    IconLoader2,
    IconWorld,
    IconLayoutKanban,
    IconCheckbox,
    IconReceipt2,
    IconFileDescription,
    IconUserPlus,
    IconForms,
    IconBolt,
    IconFlag,
    IconCheck,
    IconBuildingFactory2,
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
 * Bell-menu notifications — real persistent notifications shared from
 * HandleInertiaRequests (latest 15 + unread count). Clicking one marks
 * it read and follows its url; "Mark all read" clears the badge.
 */
const notifications = computed(() => page.props.notifications ?? []);
const unreadCount = computed(() => page.props.unread_count ?? 0);

function markAllRead() {
    router.post('/notifications/read-all', {}, { preserveState: true, preserveScroll: true, replace: true });
}
function markRead(id) {
    router.post(`/notifications/${id}/read`, {}, { preserveState: true, preserveScroll: true, replace: true });
}
function openNotification(n) {
    // Mark-read and navigate can't both be live Inertia visits at once —
    // the second cancels the first. So when there's a destination we mark
    // read, then follow through on finish; otherwise just mark read.
    if (n.url) {
        if (n.read) {
            router.visit(n.url);
        } else {
            router.post(`/notifications/${n.id}/read`, {}, {
                preserveState: true,
                preserveScroll: true,
                onFinish: () => router.visit(n.url),
            });
        }
    } else if (! n.read) {
        markRead(n.id);
    }
}

/*
 * The server stores a tabler icon NAME on each notification (e.g.
 * 'ti-checkbox'); resolve it to the imported component here — same
 * server-emits-a-name, client-maps-to-a-component pattern as
 * PRODUCT_ICONS / SEARCH_ICON_MAP. Unknown names fall back to the bell.
 */
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

/*
 * Collapsible nav groups (Clients / Billing / Operations). Expand
 * state is persisted in localStorage so it survives Inertia
 * navigations and full reloads. A group is *always* shown expanded
 * when the current page (activeNav) is one of its children, so the
 * operator never lands on a page whose nav entry is hidden — the
 * persisted flag only governs groups you're not currently inside.
 */
const expandedGroups = ref(JSON.parse(localStorage.getItem('nav_groups') ?? '{}'));

const toggleGroup = (key) => {
    expandedGroups.value[key] = ! expandedGroups.value[key];
    localStorage.setItem('nav_groups', JSON.stringify(expandedGroups.value));
};

const isGroupExpanded = (key, children) => {
    if (children.includes(props.activeNav)) {
        return true;
    }

    return expandedGroups.value[key] ?? false;
};

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
                { key: 'my-work',       label: 'My Work',       href: '/my-work',    icon: IconCheckbox },
                { key: 'projects',      label: 'Projects',      href: '/projects',   icon: IconLayoutKanban },
                { key: 'leads',         label: 'Leads',         href: '/leads',      icon: IconUserPlus },
                {
                    type: 'group',
                    key: 'clients',
                    label: 'Clients',
                    icon: IconUsers,
                    children: [
                        { key: 'customers', label: 'Customers', href: '/customers', icon: IconUsers },
                        { key: 'referrers', label: 'Referrers', href: '/referrers', icon: IconUsersGroup },
                    ],
                },
                {
                    type: 'group',
                    key: 'billing',
                    label: 'Billing',
                    icon: IconReceipt,
                    children: [
                        { key: 'invoices',      label: 'Invoices',      href: '/invoices',      icon: IconReceipt,          badge: invoicesBadge.value },
                        { key: 'proposals',     label: 'Proposals',     href: '/proposals',     icon: IconFileDescription },
                        { key: 'subscriptions', label: 'Subscriptions', href: '/subscriptions', icon: IconCreditCard },
                        { key: 'expenses',      label: 'Expenses',      href: '/expenses',      icon: IconReceipt2 },
                    ],
                },
                {
                    type: 'group',
                    key: 'operations',
                    label: 'Operations',
                    icon: IconSettings2,
                    children: [
                        { key: 'suppliers',    label: 'Suppliers',    href: '/suppliers',    icon: IconBuildingFactory2 },
                        { key: 'domains',      label: 'Domains',      href: '/domains',      icon: IconWorld },
                        { key: 'provisioning', label: 'Provisioning', href: '/provisioning', icon: IconLayoutGrid },
                        { key: 'forms',        label: 'Forms',        href: '/forms',        icon: IconForms },
                        { key: 'workflows',    label: 'Workflows',    href: '/workflows',    icon: IconBolt },
                    ],
                },
                { key: 'analytics',     label: 'Analytics',     href: '/analytics',  icon: IconChartLine },
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

/* ─────────────────────────────────────────────────────────────────
 * GLOBAL SEARCH (⌘K)
 *
 * 300ms debounced fetch to /search. The endpoint returns a flat
 * list of { type, icon, colour, title, sub, url } rows that the
 * dropdown renders verbatim. We don't trust the icon string — it's
 * an enum that maps to a component on the client side so the
 * server can't push arbitrary components into the page.
 * ──────────────────────────────────────────────────────────────── */
const searchInput = ref(null);
const searchQuery = ref('');
const searchResults = ref([]);
const searchLoading = ref(false);
const showResults = ref(false);
let searchTimer = null;

// Map server-emitted icon string → Tabler component. Anything not
// in the map falls back to a neutral marker so a typo doesn't crash
// the dropdown render.
const SEARCH_ICON_MAP = {
    'building-store': IconBuildingStore,
    receipt: IconReceipt,
    user: IconUser,
    headset: IconHeadset,
    box: IconBox,
};
function searchIcon(name) {
    return SEARCH_ICON_MAP[name] ?? IconSearch;
}

const SEARCH_TYPE_LABEL = {
    customer: 'Customer',
    invoice: 'Invoice',
    contact: 'Contact',
    ticket: 'Ticket',
    product: 'Product',
};

async function runSearch() {
    const q = searchQuery.value.trim();
    if (q.length < 2) {
        searchResults.value = [];
        showResults.value = false;
        searchLoading.value = false;

        return;
    }
    searchLoading.value = true;
    try {
        const res = await fetch(`/search?q=${encodeURIComponent(q)}`, {
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
            credentials: 'same-origin',
        });
        if (! res.ok) {
            searchResults.value = [];
            showResults.value = true;
            searchLoading.value = false;

            return;
        }
        const data = await res.json();
        // Race-guard: discard if the user already typed past this
        // query while we were waiting on the network.
        if (data.query === searchQuery.value.trim()) {
            searchResults.value = data.results ?? [];
            showResults.value = true;
        }
    } catch (e) {
        searchResults.value = [];
        showResults.value = true;
    } finally {
        searchLoading.value = false;
    }
}

function onSearch() {
    clearTimeout(searchTimer);
    if (searchQuery.value.trim().length < 2) {
        searchResults.value = [];
        showResults.value = false;
        searchLoading.value = false;

        return;
    }
    // Show the dropdown immediately with the loading state so the
    // operator sees feedback before the request resolves.
    showResults.value = true;
    searchLoading.value = true;
    searchTimer = setTimeout(runSearch, 300);
}

function pickResult(result) {
    showResults.value = false;
    searchQuery.value = '';
    searchResults.value = [];
    router.visit(result.url);
}

function closeSearchResults() {
    showResults.value = false;
}

// ⌘K / Ctrl-K focuses the search input from anywhere on the page.
// Esc closes the dropdown when it's open.
function onKeydown(e) {
    if ((e.metaKey || e.ctrlKey) && e.key === 'k') {
        e.preventDefault();
        searchInput.value?.focus();
        searchInput.value?.select();

        return;
    }
    if (e.key === 'Escape' && showResults.value) {
        showResults.value = false;
    }
}

// Click-outside-to-close. The wrapper carries the click so clicks
// on results don't immediately dismiss before pickResult fires.
function onDocClick(e) {
    if (! showResults.value) return;
    const wrapper = searchInput.value?.closest('.search-wrapper');
    if (wrapper && ! wrapper.contains(e.target)) {
        showResults.value = false;
    }
}

onMounted(() => {
    document.addEventListener('keydown', onKeydown);
    document.addEventListener('click', onDocClick);
});
onBeforeUnmount(() => {
    document.removeEventListener('keydown', onKeydown);
    document.removeEventListener('click', onDocClick);
    clearTimeout(searchTimer);
});
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
                <template v-for="item in section.items" :key="item.key">
                    <!-- Collapsible group (Clients / Billing / Operations) -->
                    <template v-if="item.type === 'group'">
                        <button
                            type="button"
                            class="nav-group-header"
                            @click="toggleGroup(item.key)"
                        >
                            <span class="nav-group-left">
                                <component :is="item.icon" :size="18" stroke-width="1.75" />
                                <span class="nav-label">{{ item.label }}</span>
                            </span>
                            <component
                                :is="isGroupExpanded(item.key, item.children.map(c => c.key)) ? IconChevronDown : IconChevronRight"
                                class="nav-chevron"
                                :size="14"
                                stroke-width="2"
                            />
                        </button>
                        <div
                            v-show="isGroupExpanded(item.key, item.children.map(c => c.key))"
                            class="nav-group-children"
                        >
                            <Link
                                v-for="child in item.children"
                                :key="child.key"
                                :href="child.href"
                                class="nav-item"
                                :class="{ active: activeNav === child.key }"
                            >
                                <component :is="child.icon" :size="18" stroke-width="1.75" />
                                <span>{{ child.label }}</span>
                                <span
                                    v-if="child.badge"
                                    class="count"
                                    :class="child.badge.cls"
                                >{{ child.badge.count }}</span>
                            </Link>
                        </div>
                    </template>
                    <!-- Plain nav item -->
                    <Link
                        v-else
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

                    <div class="search-wrapper">
                        <div class="topbar-search">
                            <span class="search-icon"><IconSearch :size="18" stroke-width="1.75" /></span>
                            <input
                                id="global-search"
                                ref="searchInput"
                                v-model="searchQuery"
                                placeholder="Search customers, invoices, products…"
                                autocomplete="off"
                                @input="onSearch"
                                @focus="searchQuery.length >= 2 && (showResults = true)"
                            >
                            <span class="kbd">⌘K</span>
                        </div>
                        <div v-if="showResults" class="search-results">
                            <div v-if="searchLoading" class="search-loading">
                                <IconLoader2 :size="14" stroke-width="2" class="search-spin" />
                                Searching…
                            </div>
                            <template v-else>
                                <button
                                    v-for="(r, i) in searchResults"
                                    :key="`${r.type}-${i}-${r.url}`"
                                    type="button"
                                    class="search-result-item"
                                    @click="pickResult(r)"
                                >
                                    <span
                                        class="search-result-icon"
                                        :style="{ background: r.colour }"
                                    >
                                        <component :is="searchIcon(r.icon)" :size="14" stroke-width="1.75" />
                                    </span>
                                    <div class="search-result-body">
                                        <div class="search-result-title">{{ r.title }}</div>
                                        <div class="search-result-sub">{{ r.sub }}</div>
                                    </div>
                                    <span class="search-result-type">{{ SEARCH_TYPE_LABEL[r.type] ?? r.type }}</span>
                                </button>
                                <div v-if="searchResults.length === 0" class="search-empty">
                                    No results for "{{ searchQuery }}"
                                </div>
                            </template>
                        </div>
                    </div>

                    <Menu as="div" class="bell-menu">
                        <MenuButton class="bell-btn" aria-label="Notifications">
                            <IconBell :size="20" stroke-width="1.75" />
                            <span v-if="unreadCount > 0" class="bell-badge">
                                {{ unreadCount > 9 ? '9+' : unreadCount }}
                            </span>
                        </MenuButton>
                        <MenuItems class="bell-popover">
                            <div class="bell-popover-head">
                                <span>Notifications</span>
                                <button
                                    v-if="unreadCount > 0"
                                    type="button"
                                    class="bell-mark-all"
                                    @click="markAllRead"
                                >Mark all read</button>
                            </div>
                            <div v-if="notifications.length" class="bell-popover-list">
                                <div
                                    v-for="n in notifications"
                                    :key="n.id"
                                    class="notif-item"
                                    :class="{ unread: !n.read }"
                                    @click="openNotification(n)"
                                >
                                    <span class="notif-icon" :style="{ background: n.colour + '22', color: n.colour }">
                                        <component :is="notifIcon(n.icon)" :size="15" stroke-width="2" />
                                    </span>
                                    <div class="notif-body">
                                        <div class="notif-title" :class="{ strong: !n.read }">{{ n.title }}</div>
                                        <div class="notif-message">{{ n.message }}</div>
                                        <div class="notif-time">{{ n.time_ago }}</div>
                                    </div>
                                    <span v-if="!n.read" class="notif-dot" />
                                </div>
                            </div>
                            <div v-else class="bell-popover-empty">
                                <IconCircleCheck :size="22" stroke-width="2" />
                                <span>All caught up ✓</span>
                            </div>
                            <Link href="/notifications" class="bell-popover-foot">
                                View all
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
