<script setup>
import { Link } from '@inertiajs/vue3';

const nav = [
    { name: 'Overview', href: '/account' },
    { name: 'Products', href: '/account/products' },
    { name: 'Invoices', href: '/account/invoices' },
    { name: 'Support', href: '/account/support' },
    { name: 'Settings', href: '/account/settings' },
];
</script>

<template>
    <div class="min-h-screen" style="background: var(--content-bg)">
        <header
            class="border-b"
            style="background: var(--card-bg); border-color: var(--border)"
        >
            <div class="mx-auto flex h-14 max-w-[960px] items-center justify-between px-4">
                <div class="text-sm font-semibold" style="color: var(--text-primary)">
                    Whitedash Account
                </div>
                <nav class="flex gap-4 text-sm">
                    <Link
                        v-for="item in nav"
                        :key="item.name"
                        :href="item.href"
                        class="hover:opacity-80"
                        style="color: var(--text-secondary)"
                    >
                        {{ item.name }}
                    </Link>
                </nav>
            </div>
        </header>
        <main class="mx-auto max-w-[960px] px-4 py-8">
            <slot />
        </main>
    </div>
</template>
