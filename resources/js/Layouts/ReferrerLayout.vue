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
} from '@tabler/icons-vue';

defineProps({
    title: { type: String, default: '' },
    activeNav: { type: String, default: '' },
});

const page = usePage();

const me = computed(() => {
    const user = page.props.auth?.user;
    if (! user) {
        return { initials: '?', name: 'Partner', email: '' };
    }
    const parts = (user.name || '').trim().split(/\s+/);
    const initials = ((parts[0]?.[0] ?? '') + (parts[1]?.[0] ?? '')).toUpperCase()
        || user.name?.slice(0, 2).toUpperCase()
        || '?';
    return { initials, name: user.name, email: user.email };
});

/*
 * The referrer portal reuses the customer portal's chrome — same
 * .portal-topnav, .portal-tabs, .portal-content, .portal-footer.
 * Only the branding sub-label differs ("partner" vs "account") so
 * a referrer reading the URL bar (referrers.whitedash.co.uk) and a
 * customer reading theirs (account.whitedash.co.uk) get visually
 * distinct landings that still feel like the same product.
 */
const tabs = [
    { key: 'dashboard',    label: 'Dashboard',    href: '/referrer/dashboard' },
    { key: 'commissions',  label: 'Commissions',  href: '/referrer/commissions' },
    { key: 'my-customers', label: 'My Customers', href: '/referrer/customers' },
    { key: 'account',      label: 'Account',      href: '/referrer/account' },
];

function logout() {
    router.post('/logout');
}
</script>

<template>
    <div class="portal referrer">
        <nav class="portal-topnav">
            <Link href="/referrer/dashboard" class="portal-brand">
                <div class="brand-mark">W</div>
                <div class="portal-brand-name">Whitedash</div>
                <div class="portal-brand-divider" />
                <div class="portal-brand-sub">partner</div>
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
                        <div class="portal-user-name">{{ me.name }}</div>
                        <IconChevronDown :size="16" stroke-width="1.75" class="portal-user-chev" />
                    </MenuButton>
                    <MenuItems class="portal-user-popover">
                        <Link href="/referrer/account" class="portal-user-item">
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

        <main class="portal-content">
            <slot />
        </main>

        <footer class="portal-footer">
            <div class="portal-footer-left">
                <div class="brand-mark">W</div>
                Whitedash Partners · referrers.whitedash.co.uk
            </div>
            <div class="portal-footer-mid">
                <a href="#">Privacy policy</a>
                <a href="#">Terms</a>
                <a href="#">Commission rules</a>
            </div>
            <div class="portal-footer-right">© 2026 Whitedash Holdings Ltd</div>
        </footer>
    </div>
</template>
