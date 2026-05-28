<script setup>
import { Link } from '@inertiajs/vue3';
import {
    IconLayoutDashboard,
    IconUsers,
    IconReceipt,
    IconShare,
    IconWorld,
    IconHelpCircle,
    IconSettings,
    IconRocket,
} from '@tabler/icons-vue';

const nav = [
    { name: 'Dashboard', href: '/', icon: IconLayoutDashboard },
    { name: 'Customers', href: '/customers', icon: IconUsers },
    { name: 'Invoices', href: '/invoices', icon: IconReceipt },
    { name: 'Referrers', href: '/referrers', icon: IconShare },
    { name: 'Domains', href: '/domains', icon: IconWorld },
    { name: 'Support', href: '/support', icon: IconHelpCircle },
    { name: 'Provisioning', href: '/provisioning', icon: IconRocket },
    { name: 'Settings', href: '/settings', icon: IconSettings },
];
</script>

<template>
    <div class="flex min-h-screen" style="background: var(--content-bg)">
        <aside
            class="flex w-64 flex-col"
            style="background: var(--bg-navy); color: white"
        >
            <div class="px-6 py-5 text-lg font-semibold" style="color: var(--accent)">
                Powerhouse
            </div>
            <nav class="flex flex-1 flex-col gap-1 px-3">
                <Link
                    v-for="item in nav"
                    :key="item.name"
                    :href="item.href"
                    class="flex items-center gap-3 rounded px-3 py-2 text-sm transition hover:bg-white/10"
                >
                    <component :is="item.icon" :size="18" />
                    <span>{{ item.name }}</span>
                </Link>
            </nav>
        </aside>

        <div class="flex flex-1 flex-col">
            <header
                class="flex h-14 items-center justify-between border-b px-6"
                style="border-color: var(--border); background: var(--card-bg)"
            >
                <h1 class="text-sm font-medium" style="color: var(--text-secondary)">
                    <slot name="title" />
                </h1>
                <div class="text-sm" style="color: var(--text-secondary)">
                    <slot name="topbar" />
                </div>
            </header>
            <main class="flex-1 p-6">
                <slot />
            </main>
        </div>
    </div>
</template>
