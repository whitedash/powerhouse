<script setup>
import { computed } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import {
    Menu,
    MenuButton,
    MenuItem,
    MenuItems,
} from '@headlessui/vue';
import { IconLogout, IconUserCircle, IconEye } from '@tabler/icons-vue';
import ToastContainer from '@/Components/UI/ToastContainer.vue';

/*
 * Portal chrome — implements the Whitedash handoff design system
 * (resources/css/portal.css, scoped under .wd). The layout owns the
 * shared chrome: the desktop .topnav + .foot-bar, and the mobile
 * .tabbar. Each page fills two slots:
 *   #default  → desktop content (a div.content wrapper)
 *   #mobile   → mobile content (its own .m-appbar + .m-body, since the
 *               app bar varies per page — title, back affordance, etc.)
 * The layout appends the mobile .tabbar after the #mobile slot unless
 * mobileTabbar is false (ticket thread + confirmation have none).
 */
const props = defineProps({
    // overview | subscriptions | invoices | support | account
    activeNav: { type: String, default: '' },
    // { invoices?: number, support?: number } — drives the nav badges.
    counts: { type: Object, default: () => ({}) },
    // Mobile bottom tab bar. Off for the ticket thread (sticky reply
    // bar instead) and the payment confirmation screen.
    mobileTabbar: { type: Boolean, default: true },
});

const page = usePage();

const me = computed(() => {
    const pu = page.props.auth?.portal_user;
    if (! pu) {
        return { initials: '?', name: 'Guest', customerName: '', city: '' };
    }
    const cname = pu.customer?.name ?? pu.name ?? '';
    const parts = cname.trim().split(/\s+/);
    const initials = ((parts[0]?.[0] ?? '') + (parts[1]?.[0] ?? '')).toUpperCase()
        || cname.slice(0, 2).toUpperCase();
    return {
        initials: initials || '?',
        name: pu.name,
        customerName: cname,
        city: pu.customer?.city ?? '',
    };
});

/*
 * Desktop tabs. `tone` matches the handoff count-pill colours
 * (invoices = amber, support = blue). A count of 0/undefined paints
 * no badge — same rule the rest of the portal uses.
 */
const tabs = computed(() => [
    { key: 'overview', label: 'Overview', href: '/portal/dashboard' },
    { key: 'assets', label: 'My Assets', href: '/portal/assets' },
    { key: 'subscriptions', label: 'Subscriptions', href: '/portal/subscriptions' },
    { key: 'invoices', label: 'Invoices', href: '/portal/invoices', count: props.counts?.invoices, tone: 'amber' },
    { key: 'support', label: 'Support', href: '/portal/support', count: props.counts?.support, tone: 'blue' },
    { key: 'account', label: 'Account', href: '/portal/account' },
]);

const mobileTabs = computed(() => [
    { key: 'overview', icon: 'layout-grid', label: 'Overview', href: '/portal/dashboard' },
    { key: 'assets', icon: 'box', label: 'Assets', href: '/portal/assets' },
    { key: 'subscriptions', icon: 'stack-2', label: 'Subs', href: '/portal/subscriptions' },
    { key: 'invoices', icon: 'receipt', label: 'Invoices', href: '/portal/invoices', count: props.counts?.invoices },
    { key: 'support', icon: 'lifebuoy', label: 'Support', href: '/portal/support', count: props.counts?.support },
    { key: 'account', icon: 'user', label: 'Account', href: '/portal/account' },
]);

function logout() {
    router.post('/portal/logout');
}

/*
 * Exit preview: super_admin opens the impersonation tab via window.open,
 * so window.close() usually works (best UX — drops them back on their
 * original Powerhouse tab). If it's blocked (back-button, direct URL),
 * fall back to the exit route, which clears the portal-guard session
 * and returns the operator to the internal dashboard — the staff 'web'
 * session is untouched, so they land back as admin, not on portal login.
 */
function exitPreview() {
    window.close();
    setTimeout(() => { window.location.href = '/portal/preview/exit'; }, 150);
}
</script>

<template>
    <div class="wd portal-shell">
        <!-- Preview banner (operator impersonation) — a safety affordance
             outside the handoff design, kept above the chrome. -->
        <div v-if="page.props.portal_preview_mode" class="portal-preview-banner">
            <IconEye :size="16" stroke-width="2" />
            <span>Previewing as <strong>{{ me.name || me.customerName }}</strong></span>
            <button type="button" class="portal-preview-exit" @click="exitPreview">
                <IconLogout :size="14" stroke-width="2" />
                Exit preview
            </button>
        </div>

        <!-- ═══════════ DESKTOP SHELL (≥768px) ═══════════ -->
        <div class="wd-desktop">
            <nav class="topnav">
                <Link href="/portal/dashboard" class="brand" style="text-decoration:none;color:inherit">
                    <div class="brand-mark">W</div>
                    <div class="brand-name">Whitedash</div>
                    <div class="brand-divider" />
                    <div class="brand-sub">account</div>
                </Link>

                <div class="tabs">
                    <Link
                        v-for="tab in tabs"
                        :key="tab.key"
                        :href="tab.href"
                        class="tab"
                        :class="{ active: activeNav === tab.key }"
                    >
                        {{ tab.label }}
                        <span v-if="tab.count" class="count" :class="tab.tone">{{ tab.count }}</span>
                    </Link>
                </div>

                <div class="nav-right">
                    <button class="bell-btn" aria-label="Notifications"><i class="ti ti-bell" /></button>
                    <div class="brand-divider" />
                    <Menu as="div" style="position:relative">
                        <MenuButton class="user-pill">
                            <div class="avatar av-amber">{{ me.initials }}</div>
                            <div class="uname">{{ me.customerName }}</div>
                            <i class="ti ti-chevron-down chev" />
                        </MenuButton>
                        <MenuItems class="portal-user-popover">
                            <Link href="/portal/account" class="portal-user-item">
                                <IconUserCircle :size="16" stroke-width="1.75" />
                                <span>Account settings</span>
                            </Link>
                            <div class="portal-user-sep" />
                            <MenuItem v-slot="{ active }">
                                <button type="button" class="portal-user-item" :class="{ active }" @click="logout">
                                    <IconLogout :size="16" stroke-width="1.75" />
                                    <span>Sign out</span>
                                </button>
                            </MenuItem>
                        </MenuItems>
                    </Menu>
                </div>
            </nav>

            <slot name="desktop" />

            <footer class="foot-bar">
                <div class="l"><div class="brand-mark">W</div>Whitedash · account.whitedash.co.uk</div>
                <div class="links">
                    <a href="#">Privacy</a><a href="#">Terms</a><a href="#">Status</a><a href="#">Help centre</a>
                </div>
                <div class="l" style="color:var(--text-tertiary)">© 2026 Whitedash Holdings Ltd</div>
            </footer>
        </div>

        <!-- ═══════════ MOBILE SHELL (below 768px) ═══════════ -->
        <div class="wd-mobile">
            <slot name="mobile" />

            <div v-if="mobileTabbar" class="tabbar">
                <Link
                    v-for="tab in mobileTabs"
                    :key="tab.key"
                    :href="tab.href"
                    class="tb"
                    :class="{ active: activeNav === tab.key }"
                    style="text-decoration:none"
                >
                    <span v-if="tab.count" class="tb-badge">{{ tab.count }}</span>
                    <i class="ti" :class="'ti-' + tab.icon" /><span class="lbl">{{ tab.label }}</span>
                </Link>
            </div>
        </div>

        <ToastContainer />
    </div>
</template>

<style scoped>
/* Preview banner — minimal, not part of the handoff sheet. */
.portal-preview-banner {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 8px 16px;
    background: #1e293b;
    color: #fff;
    font: 500 13px/1 'Inter', sans-serif;
}
.portal-preview-banner strong { font-weight: 700; }
.portal-preview-exit {
    margin-left: auto;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    background: rgba(255, 255, 255, .12);
    color: #fff;
    border: 0;
    border-radius: 7px;
    padding: 6px 10px;
    font: 500 12px/1 'Inter', sans-serif;
    cursor: pointer;
}
.portal-preview-exit:hover { background: rgba(255, 255, 255, .2); }

/* User dropdown popover (the handoff shows a static pill; this is the
   menu it opens). Uses the .wd design tokens. */
.portal-user-popover {
    position: absolute;
    right: 0;
    top: calc(100% + 8px);
    min-width: 200px;
    background: #fff;
    border: 1px solid var(--border, #e2e8f0);
    border-radius: 10px;
    box-shadow: var(--shadow-md, 0 4px 12px -2px rgba(15, 23, 42, .08));
    padding: 6px;
    z-index: 60;
}
.portal-user-item {
    display: flex;
    align-items: center;
    gap: 10px;
    width: 100%;
    padding: 9px 10px;
    border: 0;
    background: transparent;
    border-radius: 7px;
    font: 500 13px/1 'Inter', sans-serif;
    color: var(--text-primary, #0f172a);
    text-decoration: none;
    cursor: pointer;
}
.portal-user-item:hover,
.portal-user-item.active { background: var(--neutral-bg, #f1f5f9); }
.portal-user-sep { height: 1px; background: var(--border-soft, #eef2f7); margin: 4px 0; }
</style>
