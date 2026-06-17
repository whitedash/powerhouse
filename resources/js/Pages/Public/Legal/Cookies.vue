<script setup>
import { Head } from '@inertiajs/vue3';
import { IconCookie } from '@tabler/icons-vue';
import PublicLayout from '@/Layouts/PublicLayout.vue';
import LegalContent from './LegalContent.vue';
</script>

<template>
    <Head title="Cookie Policy · Whitedash" />

    <PublicLayout>
        <section class="legal-wrap">
            <section class="card">
                <header class="card-header">
                    <div class="h-icon"><IconCookie :size="16" /></div>
                    <div><h3>Cookie Policy</h3><div class="sub">Last updated: 17 June 2026</div></div>
                </header>
                <div class="card-body">
                    <LegalContent>
                        <h2>What cookies are</h2>
                        <p>Cookies are small text files stored on your device when you visit a website. They help the site work, keep you signed in, and keep it secure.</p>

                        <h2>The cookies we use</h2>
                        <p>The Hub uses only strictly necessary cookies, which are required for it to function and cannot be switched off through the site:</p>
                        <ul>
                            <li>a session cookie that keeps you signed in;</li>
                            <li>an XSRF-TOKEN cookie that protects forms against cross-site request forgery;</li>
                            <li>security cookies set by Cloudflare (for example __cf_bm) that distinguish legitimate visitors from automated traffic.</li>
                        </ul>
                        <p>We do not use analytics, advertising or other non-essential cookies.</p>

                        <h2>Managing cookies</h2>
                        <p>Most browsers let you view, block or delete cookies through their settings. Blocking strictly necessary cookies may stop parts of the Hub working.</p>

                        <h2>Changes</h2>
                        <p>We may update this policy; the "last updated" date shows when.</p>

                        <h2>Contact</h2>
                        <p>Questions about cookies: support@whitedash.com.</p>
                    </LegalContent>
                </div>
            </section>
        </section>
    </PublicLayout>
</template>

<style scoped>
.legal-wrap { max-width: 760px; margin: 0 auto; padding: 48px 32px; }
</style>
