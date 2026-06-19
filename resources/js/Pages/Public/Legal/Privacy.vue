<script setup>
import { Head } from '@inertiajs/vue3';
import { IconShieldLock } from '@tabler/icons-vue';
import PublicLayout from '@/Layouts/PublicLayout.vue';
import LegalContent from './LegalContent.vue';
</script>

<template>
    <Head title="Privacy Policy · Whitedash" />

    <PublicLayout>
        <section class="legal-wrap">
            <section class="card">
                <header class="card-header">
                    <div class="h-icon"><IconShieldLock :size="16" /></div>
                    <div><h3>Privacy Policy</h3><div class="sub">Last updated: 17 June 2026</div></div>
                </header>
                <div class="card-body">
                    <LegalContent>
                        <h2>Who we are</h2>
                        <p>This service ("the Hub", hub.whitedash.com) is operated by Whitedash Holdings Ltd, a company registered in England and Wales under company number 16812820, with its registered office at Suite 310e Sterling House, Langston Road, Loughton, United Kingdom, IG10 3TS ("we", "us", "our"). We are the data controller for the personal data described here. For any privacy question, or to exercise your rights, contact us at support@whitedash.com.</p>

                        <h2>What this policy covers</h2>
                        <p>The Hub is the account, billing, support and referral layer for Whitedash's products. This policy explains what personal data we collect when you use the Hub, why, and your rights over it. Individual Whitedash products may also have their own privacy notices.</p>

                        <h2>The data we collect</h2>
                        <ul>
                            <li>Account and identity data: your name, email address, and a securely hashed password. The Hub is the single sign-on identity provider across Whitedash products, so signing in to a product authenticates you through the Hub.</li>
                            <li>Support data: if you contact support (including via the no-account contact form), the name, email, optional phone number, subject and message you submit, plus your IP address and the time of submission.</li>
                            <li>Billing data: where you are billed, invoice and payment records. Card payments are handled by our payment provider (Stripe); we do not see or store full card numbers.</li>
                            <li>Referral data: where relevant, referral codes and the link between a referral and an account.</li>
                            <li>Technical and usage data: IP address, browser type, and information collected through cookies (see our Cookie Policy).</li>
                        </ul>

                        <h2>Why we use it, and our legal basis</h2>
                        <ul>
                            <li>To provide and operate the Hub and your account — performance of a contract.</li>
                            <li>To respond to and manage support requests — legitimate interests (or performance of a contract).</li>
                            <li>To process billing and keep financial records — performance of a contract and a legal obligation.</li>
                            <li>To secure the service and prevent abuse, including bot protection — legitimate interests in keeping the service safe.</li>
                            <li>For any non-essential cookies or optional communications — your consent, where required.</li>
                        </ul>

                        <h2>Who we share it with</h2>
                        <p>We use trusted third parties to run the Hub, who process data on our behalf under contract:</p>
                        <ul>
                            <li>Stripe — payment processing.</li>
                            <li>Postmark — sending transactional email (e.g. support confirmations, billing notices).</li>
                            <li>Cloudflare — security, content delivery and bot protection.</li>
                            <li>040hosting — application hosting.</li>
                            <li>The Whitedash products you use — to authenticate you via single sign-on.</li>
                        </ul>
                        <p>We do not sell your personal data. We may disclose data where required by law or to protect our rights.</p>

                        <h2>International transfers</h2>
                        <p>Some providers (such as Stripe, Postmark and Cloudflare) process data outside the UK. Where data is transferred outside the UK, we rely on appropriate safeguards — such as UK adequacy regulations, the UK extension to the EU–US Data Privacy Framework, and standard contractual clauses with the UK International Data Transfer Addendum.</p>

                        <h2>How long we keep it</h2>
                        <p>We keep account and support data for 12 months after your account is closed or your support enquiry is resolved. We keep billing and financial records for 6 years, as UK company and tax law requires. Once these periods end, we delete or anonymise the data.</p>

                        <h2>Your rights</h2>
                        <p>Under UK data protection law you have the right to access your data; to have it corrected or erased; to restrict or object to its processing; to data portability; and, where processing relies on consent, to withdraw that consent. To exercise any of these, contact support@whitedash.com. You also have the right to complain to the Information Commissioner's Office (ICO) at ico.org.uk.</p>

                        <h2>Cookies</h2>
                        <p>We use cookies as described in our Cookie Policy.</p>

                        <h2>Changes to this policy</h2>
                        <p>We may update this policy from time to time; the "last updated" date shows when. Material changes will be notified where appropriate.</p>

                        <h2>Contact</h2>
                        <p>Whitedash Holdings Ltd, Suite 310e Sterling House, Langston Road, Loughton, United Kingdom, IG10 3TS. Privacy enquiries: support@whitedash.com.</p>
                    </LegalContent>
                </div>
            </section>
        </section>
    </PublicLayout>
</template>

<style scoped>
.legal-wrap { max-width: 760px; margin: 0 auto; padding: 48px 32px; }
</style>
