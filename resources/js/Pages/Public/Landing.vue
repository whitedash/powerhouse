<script setup>
import { Head, Link } from '@inertiajs/vue3';
import {
    IconUserCircle,
    IconUsersGroup,
    IconBook2,
    IconLifebuoy,
    IconArrowRight,
} from '@tabler/icons-vue';
import PublicLayout from '@/Layouts/PublicLayout.vue';

/**
 * Hub front-door landing (guests only — authenticated users are redirected
 * by LandingController). Hero + three-door cards + featured-help strip +
 * support CTA.
 *
 * Live now: Customer login (/portal/login), Partner login (/login),
 * Knowledge base (/kb) + featured articles. Still "Soon": Submit a ticket
 * (/support, Phase 3) and Become a partner (/apply, referral Phase 2).
 */
defineProps({
    featured: { type: Array, default: () => [] },
});
</script>

<template>
    <Head title="Powerhouse · Whitedash" />

    <PublicLayout>
        <!-- Hero -->
        <section class="hero">
            <div class="hero-inner">
                <h1 class="hero-title">One home for your Whitedash products</h1>
                <p class="hero-sub">
                    Manage your subscriptions and invoices, partner with us, or find the help you need —
                    all from a single Whitedash account.
                </p>
                <div class="hero-cta">
                    <a href="/portal/login" class="btn btn-primary btn-lg">
                        Customer login <IconArrowRight :size="16" stroke-width="1.75" />
                    </a>
                    <a href="/login" class="btn btn-secondary btn-lg">Partner login</a>
                </div>
            </div>
        </section>

        <!-- Three doors -->
        <section class="doors">
            <div class="doors-inner">
                <!-- Customers -->
                <article class="door">
                    <div class="door-ic door-ic-teal"><IconUserCircle :size="22" stroke-width="1.75" /></div>
                    <h2 class="door-title">Customers</h2>
                    <p class="door-copy">Access your portal to manage subscriptions, view invoices, and raise support tickets.</p>
                    <a href="/portal/login" class="door-link">Go to customer portal <IconArrowRight :size="14" stroke-width="1.75" /></a>
                </article>

                <!-- Partners -->
                <article class="door">
                    <div class="door-ic door-ic-gold"><IconUsersGroup :size="22" stroke-width="1.75" /></div>
                    <h2 class="door-title">Partners</h2>
                    <p class="door-copy">Already a referral partner? Sign in to track your referrals and commissions.</p>
                    <a href="/login" class="door-link">Partner login <IconArrowRight :size="14" stroke-width="1.75" /></a>
                    <span class="door-soon">Become a partner <span class="soon">Soon</span></span>
                </article>

                <!-- Guests / help -->
                <article class="door">
                    <div class="door-ic door-ic-blue"><IconBook2 :size="22" stroke-width="1.75" /></div>
                    <h2 class="door-title">Help &amp; support</h2>
                    <p class="door-copy">Browse the knowledge base or get in touch — no account needed.</p>
                    <Link href="/kb" class="door-link">Knowledge base <IconArrowRight :size="14" stroke-width="1.75" /></Link>
                    <Link href="/support" class="door-link">Submit a ticket <IconArrowRight :size="14" stroke-width="1.75" /></Link>
                </article>
            </div>
        </section>

        <!-- Featured help (top articles by views). Self-hides when empty. -->
        <section v-if="featured.length" class="featured">
            <div class="featured-inner">
                <h2 class="featured-head">Popular help articles</h2>
                <div class="featured-grid">
                    <Link v-for="a in featured" :key="a.slug" :href="`/kb/${a.slug}`" class="featured-card">
                        <span v-if="a.category" class="featured-cat">{{ a.category }}</span>
                        <span class="featured-title">{{ a.title }}</span>
                    </Link>
                </div>
            </div>
        </section>

        <!-- Support CTA -->
        <section class="cta">
            <div class="cta-inner">
                <div class="cta-ic"><IconLifebuoy :size="24" stroke-width="1.75" /></div>
                <div class="cta-text">
                    <h3 class="cta-title">Need a hand?</h3>
                    <p class="cta-copy">Our support team is here to help. Self-serve help and ticketing are coming soon.</p>
                </div>
            </div>
        </section>
    </PublicLayout>
</template>

<style scoped>
/* Hero */
.hero { background: var(--bg-navy); color: #fff; }
.hero-inner { max-width: 920px; margin: 0 auto; padding: 80px 32px 72px; text-align: center; }
.hero-title { font-size: 42px; line-height: 1.1; font-weight: 700; margin: 0 0 16px; letter-spacing: -.02em; }
.hero-sub { font-size: 17px; line-height: 1.6; color: #CBD5E1; max-width: 620px; margin: 0 auto 28px; }
.hero-cta { display: flex; gap: 12px; justify-content: center; flex-wrap: wrap; }
.btn-lg { height: 46px; padding: 0 22px; font-size: 14px; }

/* Doors */
.doors { max-width: 1080px; margin: 0 auto; padding: 56px 32px 8px; }
.doors-inner { display: grid; grid-template-columns: repeat(3, 1fr); gap: 20px; }
.door {
    background: var(--card-bg); border: 1px solid var(--border);
    border-radius: var(--radius-xl); box-shadow: var(--shadow-md);
    padding: 28px 24px; display: flex; flex-direction: column; gap: 8px;
}
.door-ic {
    width: 46px; height: 46px; border-radius: var(--radius-lg);
    display: flex; align-items: center; justify-content: center; margin-bottom: 6px;
}
.door-ic-teal { background: rgba(13, 148, 136, .12); color: var(--teal); }
.door-ic-gold { background: rgba(245, 158, 11, .14); color: var(--accent); }
.door-ic-blue { background: var(--info-bg); color: var(--info); }
.door-title { font-size: 18px; font-weight: 700; margin: 0; }
.door-copy { font-size: 13.5px; line-height: 1.55; color: var(--text-secondary); margin: 0; flex: 1; }
.door-link {
    display: inline-flex; align-items: center; gap: 6px; margin-top: 8px;
    font-size: 13px; font-weight: 600; color: var(--accent); text-decoration: none;
}
.door-link:hover { text-decoration: underline; }
.door-soon {
    display: inline-flex; align-items: center; gap: 8px; margin-top: 8px;
    font-size: 13px; font-weight: 500; color: var(--text-tertiary);
}
.soon {
    font-size: 9.5px; font-weight: 600; text-transform: uppercase; letter-spacing: .04em;
    color: var(--text-secondary); background: var(--neutral-bg);
    border: 1px solid var(--border); border-radius: 999px; padding: 1px 6px;
}

/* Featured help */
.featured { max-width: 1080px; margin: 0 auto; padding: 48px 32px 8px; }
.featured-head { font-size: 18px; font-weight: 700; margin: 0 0 16px; }
.featured-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 14px; }
.featured-card {
    display: flex; flex-direction: column; gap: 6px; padding: 16px 18px;
    background: var(--card-bg); border: 1px solid var(--border); border-radius: var(--radius-lg);
    box-shadow: var(--shadow-sm); text-decoration: none; transition: box-shadow .15s, transform .15s;
}
.featured-card:hover { box-shadow: var(--shadow-md); transform: translateY(-2px); }
.featured-cat { font-size: 10.5px; font-weight: 600; text-transform: uppercase; letter-spacing: .04em; color: var(--accent); }
.featured-title { font-size: 14px; font-weight: 600; color: var(--text-primary); line-height: 1.4; }

/* Support CTA */
.cta { max-width: 1080px; margin: 0 auto; padding: 40px 32px 8px; }
.cta-inner {
    display: flex; align-items: center; gap: 18px;
    background: var(--card-bg); border: 1px solid var(--border);
    border-radius: var(--radius-xl); box-shadow: var(--shadow-sm); padding: 24px 28px;
}
.cta-ic {
    width: 52px; height: 52px; flex-shrink: 0; border-radius: var(--radius-lg);
    display: flex; align-items: center; justify-content: center;
    background: var(--info-bg); color: var(--info);
}
.cta-title { margin: 0 0 4px; font-size: 17px; font-weight: 700; }
.cta-copy { margin: 0; font-size: 13.5px; color: var(--text-secondary); }

@media (max-width: 860px) {
    .doors-inner { grid-template-columns: 1fr; }
    .hero-title { font-size: 32px; }
}
</style>
