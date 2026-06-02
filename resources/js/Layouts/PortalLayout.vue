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
    IconBell,
    IconChevronDown,
    IconLogout,
    IconUserCircle,
    IconEye,
    IconHome,
    IconReceipt,
    IconHeadset,
    IconUser,
} from '@tabler/icons-vue';
import ToastContainer from '@/Components/UI/ToastContainer.vue';

const props = defineProps({
    title: { type: String, default: '' },
    activeNav: { type: String, default: '' },
    counts: { type: Object, default: () => ({}) },
});

const page = usePage();

const me = computed(() => {
    const pu = page.props.auth?.portal_user;
    if (! pu) {
        return { initials: '?', name: 'Guest', customerName: '', city: '' };
    }
    const cname = pu.customer?.name ?? pu.name ?? '';
    const parts = cname.trim().split(/\s+/);
    const initials = ((parts[0]?.[0] ?? '') + (parts[1]?.[0] ?? '')).toUpperCase() || cname.slice(0, 2).toUpperCase();
    return {
        initials: initials || '?',
        name: pu.name,
        customerName: cname,
        city: pu.customer?.city ?? '',
    };
});

/*
 * Tabs surfaced in the topnav. Keys match the activeNav prop so the
 * active styling stays in sync with the route. Counts come in via
 * the page-level prop so each page can paint badges based on its
 * own data (e.g. dashboard knows open ticket count, others may not).
 */
const tabs = computed(() => [
    { key: 'dashboard',     label: 'Overview',      href: '/portal/dashboard' },
    { key: 'subscriptions', label: 'Subscriptions', href: '/portal/subscriptions', count: props.counts?.subscriptions },
    { key: 'invoices',      label: 'Invoices',      href: '/portal/invoices',      count: props.counts?.invoices },
    { key: 'support',       label: 'Support',       href: '/portal/support',       count: props.counts?.support },
    { key: 'account',       label: 'Account',       href: '/portal/account' },
]);

function logout() {
    router.post('/portal/logout');
}

/*
 * Exit preview: super_admin opens the impersonation tab via
 * window.open from the internal dashboard, so window.close()
 * normally works. If it doesn't (user navigated and back-buttoned,
 * or opened the URL directly), fall back to POSTing the portal
 * logout endpoint. Plain location.href would 405 because the
 * portal logout route is POST-only.
 */
function exitPreview() {
    window.close();
    setTimeout(() => {
        router.post('/portal/logout');
    }, 150);
}
</script>

<template>
    <div class="portal">
        <!--
          Preview banner: super_admin used /impersonate/portal/{id} to
          view this account. Shown above the topnav so it's unmissable;
          closing the tab clears the session naturally.
        -->
        <div v-if="page.props.portal_preview_mode" class="preview-banner">
            <IconEye :size="16" stroke-width="2" />
            <span>Previewing as <strong>{{ me.name || me.customerName }}</strong></span>
            <button type="button" class="preview-exit-btn" @click="exitPreview">
                <IconLogout :size="14" stroke-width="2" />
                Exit preview
            </button>
        </div>

        <!-- Topnav: sticky white bar with brand, tabs, bell, user pill -->
        <nav class="portal-topnav">
            <Link href="/portal/dashboard" class="portal-brand">
                <div class="brand-mark">W</div>
                <div class="portal-brand-name">Whitedash</div>
                <div class="portal-brand-divider" />
                <div class="portal-brand-sub">account</div>
            </Link>

            <div class="portal-tabs">
                <Link
                    v-for="tab in tabs"
                    :key="tab.key"
                    :href="tab.href"
                    class="portal-tab"
                    :class="{ active: activeNav === tab.key }"
                >
                    <span>{{ tab.label }}</span>
                    <span v-if="tab.count" class="portal-tab-count">{{ tab.count }}</span>
                </Link>
            </div>

            <div class="portal-nav-right">
                <button class="portal-bell-btn" aria-label="Notifications">
                    <IconBell :size="20" stroke-width="1.75" />
                </button>
                <div class="portal-brand-divider" />

                <Menu as="div" class="portal-user-menu">
                    <MenuButton class="portal-user-pill">
                        <div class="portal-avatar av-teal">{{ me.initials }}</div>
                        <div class="portal-user-name">{{ me.customerName }}</div>
                        <IconChevronDown :size="16" stroke-width="1.75" class="portal-user-chev" />
                    </MenuButton>
                    <MenuItems class="portal-user-popover">
                        <Link href="/portal/account" class="portal-user-item">
                            <IconUserCircle :size="16" stroke-width="1.75" />
                            <span>Account settings</span>
                        </Link>
                        <div style="height: 1px; background: var(--border-soft); margin: 4px 0;" />
                        <MenuItem v-slot="{ active }">
                            <button
                                type="button"
                                class="portal-user-item"
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
        </nav>

        <!-- Page content sits in a max-960 column. Each page styles its own
             internals — the layout only owns the chrome around it. -->
        <main class="portal-content">
            <slot />
        </main>

        <footer class="portal-footer">
            <div class="portal-footer-left">
                <div class="brand-mark">W</div>
                Whitedash · account.whitedash.co.uk
            </div>
            <div class="portal-footer-mid">
                <a href="#">Privacy policy</a>
                <a href="#">Terms</a>
                <a href="#">Status</a>
                <a href="#">Help</a>
            </div>
            <div class="portal-footer-right">© 2026 Whitedash Holdings Ltd</div>
        </footer>

        <!-- Mobile bottom tab bar — hidden on desktop via CSS. Active state
             reuses the page-level activeNav prop so it stays in sync with
             the topnav; badges read the same counts. -->
        <nav class="po-bottom-nav">
            <Link href="/portal/dashboard" class="po-tab" :class="{ active: activeNav === 'dashboard' }">
                <IconHome :size="20" stroke-width="1.75" />
                <span>Home</span>
            </Link>
            <Link href="/portal/invoices" class="po-tab" :class="{ active: activeNav === 'invoices' }">
                <IconReceipt :size="20" stroke-width="1.75" />
                <span>Invoices</span>
                <span v-if="counts?.invoices" class="po-tab-badge">{{ counts.invoices }}</span>
            </Link>
            <Link href="/portal/support" class="po-tab" :class="{ active: activeNav === 'support' }">
                <IconHeadset :size="20" stroke-width="1.75" />
                <span>Support</span>
                <span v-if="counts?.support" class="po-tab-badge">{{ counts.support }}</span>
            </Link>
            <Link href="/portal/account" class="po-tab" :class="{ active: activeNav === 'account' }">
                <IconUser :size="20" stroke-width="1.75" />
                <span>Account</span>
            </Link>
        </nav>

        <ToastContainer />
    </div>
</template>

<style scoped>
.po-bottom-nav { display: none; }

@media (max-width: 767px) {
    .po-bottom-nav {
        display: flex;
        position: fixed;
        bottom: 0;
        left: 0;
        right: 0;
        background: #fff;
        border-top: 1px solid #f3f4f6;
        z-index: 50;
        padding-bottom: env(safe-area-inset-bottom, 0);
        box-shadow: 0 -2px 12px rgba(0, 0, 0, .06);
    }
    .po-tab {
        flex: 1;
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        gap: 4px;
        padding: 8px 4px;
        min-height: 56px;
        text-decoration: none;
        color: #9ca3af;
        font: 400 10px/1 'Inter', sans-serif;
        position: relative;
        transition: color .15s;
    }
    .po-tab.active { color: var(--accent, #f5a623); }
    .po-tab-badge {
        position: absolute;
        top: 6px;
        right: calc(50% - 20px);
        background: #dc2626;
        color: #fff;
        font: 600 9px/1 'Inter', sans-serif;
        padding: 2px 5px;
        border-radius: 999px;
        min-width: 16px;
        text-align: center;
    }
    /* Keep page content clear of the fixed bar. */
    .portal-content { padding-bottom: 80px; }
}
</style>
