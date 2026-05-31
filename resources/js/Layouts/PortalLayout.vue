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
    { key: 'security',      label: 'Security',      href: '/portal/security' },
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

        <ToastContainer />
    </div>
</template>
