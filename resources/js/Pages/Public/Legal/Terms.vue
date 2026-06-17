<script setup>
import { Head } from '@inertiajs/vue3';
import { IconFileText } from '@tabler/icons-vue';
import PublicLayout from '@/Layouts/PublicLayout.vue';
import LegalContent from './LegalContent.vue';
</script>

<template>
    <Head title="Terms of Service · Whitedash" />

    <PublicLayout>
        <section class="legal-wrap">
            <section class="card">
                <header class="card-header">
                    <div class="h-icon"><IconFileText :size="16" /></div>
                    <div><h3>Terms of Service</h3><div class="sub">Last updated: 17 June 2026</div></div>
                </header>
                <div class="card-body">
                    <LegalContent>
                        <h2>1. About these terms</h2>
                        <p>These terms govern your use of the Hub (hub.whitedash.com), operated by Whitedash Holdings Ltd, company number 16812820, registered office Suite 310e Sterling House, Langston Road, Loughton, United Kingdom, IG10 3TS ("we", "us"). By using the Hub or signing in, you agree to these terms. If you do not agree, do not use the Hub.</p>

                        <h2>2. The service</h2>
                        <p>The Hub provides account and single sign-on identity, billing and invoicing, support, and referral functions for Whitedash's products. We may change, suspend or withdraw features at any time.</p>

                        <h2>3. Your account</h2>
                        <p>You are responsible for keeping your login credentials confidential and for activity under your account. You must provide accurate information and keep it up to date, and tell us promptly if you suspect unauthorised use.</p>

                        <h2>4. Acceptable use</h2>
                        <p>You agree not to misuse the Hub, including by attempting to gain unauthorised access, interfering with its operation or security, or using it unlawfully or to harm others.</p>

                        <h2>5. Billing</h2>
                        <p>Where the Hub is used to bill you, you agree to pay the amounts shown on your invoices by their due dates. Payments are processed by our payment provider, Stripe. If a payment fails, we may retry it and may suspend access to paid features until the amount is settled. Except where the law requires otherwise, fees are non-refundable.</p>

                        <h2>6. Intellectual property</h2>
                        <p>The Hub and its content are owned by us or our licensors and are protected by law. These terms do not transfer any of those rights to you.</p>

                        <h2>7. Disclaimers and liability</h2>
                        <p>The Hub is provided on an "as is" and "as available" basis, and to the extent permitted by law we exclude all warranties not expressly stated in these terms. Nothing in these terms limits or excludes our liability for death or personal injury caused by our negligence, for fraud, or for anything else that cannot lawfully be limited or excluded. Subject to that, we are not liable for indirect or consequential loss, or for loss of profit, revenue, data or goodwill, and our total liability in connection with the Hub is limited to the total amount you paid us for it in the 12 months before the event giving rise to the claim.</p>

                        <h2>8. Termination</h2>
                        <p>We may suspend or end your access if you breach these terms, or where we reasonably need to. You may stop using the Hub at any time.</p>

                        <h2>9. Governing law</h2>
                        <p>These terms are governed by the laws of England and Wales, and the courts of England and Wales have exclusive jurisdiction.</p>

                        <h2>10. Changes</h2>
                        <p>We may update these terms; the "last updated" date shows when. Continued use after a change means you accept the updated terms.</p>

                        <h2>11. Contact</h2>
                        <p>Whitedash Holdings Ltd, Suite 310e Sterling House, Langston Road, Loughton, United Kingdom, IG10 3TS. Enquiries: support@whitedash.com.</p>
                    </LegalContent>
                </div>
            </section>
        </section>
    </PublicLayout>
</template>

<style scoped>
.legal-wrap { max-width: 760px; margin: 0 auto; padding: 48px 32px; }
</style>
