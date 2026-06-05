<script setup>
import { Link } from '@inertiajs/vue3';
import { ref, watch, onMounted, onBeforeUnmount } from 'vue';
import { IconMenu2, IconX } from '@tabler/icons-vue';

/**
 * The hub's public/marketing shell — the first layout that renders outside
 * any authenticated guard. Standalone (not the Internal/Portal chrome) and
 * built on the root design tokens.
 *
 * Desktop (≥ 721px): the four nav items render inline as today. Mobile
 * (≤ 720px): they collapse behind a burger that toggles a dropdown panel
 * carrying the SAME links. The panel closes on link tap, outside-click and
 * Escape, and locks body scroll while open.
 */
const year = new Date().getFullYear();

const open = ref(false);
const toggle = () => { open.value = ! open.value; };
const close = () => { open.value = false; };

// Lock body scroll while the menu is open so the page behind doesn't move.
watch(open, (isOpen) => {
    document.body.style.overflow = isOpen ? 'hidden' : '';
});

const onKeydown = (e) => { if (e.key === 'Escape') close(); };
// If the viewport grows back to desktop while open, drop the menu so the
// inline nav (and body scroll) are never left in a stale state.
const onResize = () => { if (window.innerWidth > 720) close(); };

onMounted(() => {
    window.addEventListener('keydown', onKeydown);
    window.addEventListener('resize', onResize);
});
onBeforeUnmount(() => {
    window.removeEventListener('keydown', onKeydown);
    window.removeEventListener('resize', onResize);
    document.body.style.overflow = '';
});
</script>

<template>
    <div class="pub">
        <header class="pub-header">
            <Link href="/" class="pub-brand">
                <span class="brand-mark">W</span>
                <span class="pub-brand-text">
                    <span class="pub-brand-title">Powerhouse</span>
                    <span class="pub-brand-sub">Whitedash</span>
                </span>
            </Link>

            <nav class="pub-nav">
                <Link href="/kb" class="pub-nav-link">Knowledge base</Link>
                <Link href="/support" class="pub-nav-link">Support</Link>
            </nav>

            <div class="pub-actions">
                <a href="/portal/login" class="btn btn-secondary">Customer login</a>
                <a href="/login" class="btn btn-primary">Partner login</a>
            </div>

            <!-- Mobile only (≤720px): toggles the dropdown panel below. -->
            <button
                type="button"
                class="pub-burger"
                :aria-label="open ? 'Close menu' : 'Open menu'"
                :aria-expanded="open"
                aria-controls="pub-mobile-menu"
                @click="toggle"
            >
                <IconX v-if="open" :size="22" stroke-width="1.75" />
                <IconMenu2 v-else :size="22" stroke-width="1.75" />
            </button>

            <!-- Transparent catch-all: outside-click closes the menu while
                 the brand/logo stays visible in the bar. -->
            <div v-if="open" class="pub-mobile-backdrop" aria-hidden="true" @click="close" />

            <nav v-if="open" id="pub-mobile-menu" class="pub-mobile-panel">
                <Link href="/kb" class="pub-mobile-link" @click="close">Knowledge base</Link>
                <Link href="/support" class="pub-mobile-link" @click="close">Support</Link>
                <a href="/portal/login" class="pub-mobile-link" @click="close">Customer login</a>
                <a href="/login" class="pub-mobile-link pub-mobile-link--primary" @click="close">Partner login</a>
            </nav>
        </header>

        <main class="pub-main">
            <slot />
        </main>

        <footer class="pub-footer">
            <div class="pub-footer-inner">
                <div class="pub-foot-legal">
                    <span class="pub-foot-link is-soon">Privacy</span>
                    <span class="pub-foot-link is-soon">Terms</span>
                    <span class="pub-foot-link is-soon">Cookies</span>
                </div>
                <div class="pub-foot-copy">© {{ year }} Whitedash Holdings. All rights reserved.</div>
            </div>
        </footer>
    </div>
</template>

<style scoped>
.pub {
    min-height: 100vh;
    display: flex;
    flex-direction: column;
    background: var(--content-bg);
    color: var(--text-primary);
    font-family: 'Inter', sans-serif;
}

/* Header */
.pub-header {
    position: relative; /* anchors the mobile dropdown panel */
    display: flex;
    align-items: center;
    gap: 24px;
    padding: 16px 32px;
    background: var(--card-bg);
    border-bottom: 1px solid var(--border);
}
.pub-brand { display: flex; align-items: center; gap: 10px; text-decoration: none; }
.pub-brand-text { display: flex; flex-direction: column; line-height: 1.1; }
.pub-brand-title { font-weight: 700; font-size: 15px; color: var(--text-primary); }
.pub-brand-sub { font-size: 11px; color: var(--text-secondary); }

.pub-nav { display: flex; align-items: center; gap: 20px; margin-left: 16px; }
.pub-nav-link {
    font-size: 13px; font-weight: 500; color: var(--text-secondary);
    text-decoration: none; display: inline-flex; align-items: center; gap: 6px;
}
.pub-nav-link:not(.is-soon):hover { color: var(--text-primary); }
.pub-nav-link.is-soon { cursor: default; opacity: .65; }
.soon {
    font-size: 9.5px; font-weight: 600; text-transform: uppercase; letter-spacing: .04em;
    color: var(--text-secondary); background: var(--neutral-bg);
    border: 1px solid var(--border); border-radius: 999px; padding: 1px 6px;
}

.pub-actions { margin-left: auto; display: flex; align-items: center; gap: 10px; }

/* Mobile burger + dropdown — hidden on desktop, revealed ≤720px. */
.pub-burger {
    display: none; /* desktop: hidden */
    position: relative;
    z-index: 60; /* stays above the backdrop so it can toggle closed */
    margin-left: auto;
    align-items: center;
    justify-content: center;
    width: 40px;
    height: 40px;
    padding: 0;
    color: var(--text-primary);
    background: transparent;
    border: 1px solid var(--border);
    border-radius: 8px;
    cursor: pointer;
}
.pub-burger:hover { background: var(--neutral-bg); }

.pub-mobile-backdrop {
    position: fixed;
    inset: 0;
    z-index: 40;
    background: transparent;
}
.pub-mobile-panel {
    position: absolute;
    top: 100%;
    left: 0;
    right: 0;
    z-index: 50;
    display: flex;
    flex-direction: column;
    padding: 8px;
    background: var(--card-bg);
    border-bottom: 1px solid var(--border);
    box-shadow: 0 10px 24px rgba(0, 0, 0, 0.08);
}
.pub-mobile-link {
    font-size: 14px;
    font-weight: 500;
    color: var(--text-secondary);
    text-decoration: none;
    padding: 12px 12px;
    border-radius: 8px;
}
.pub-mobile-link:hover { background: var(--neutral-bg); color: var(--text-primary); }
.pub-mobile-link--primary { color: var(--accent); font-weight: 600; }

/* Main */
.pub-main { flex: 1; }

/* Footer */
.pub-footer { background: var(--card-bg); border-top: 1px solid var(--border); margin-top: 48px; }
.pub-footer-inner {
    max-width: 1080px; margin: 0 auto; padding: 24px 32px;
    display: flex; align-items: center; justify-content: space-between; gap: 16px; flex-wrap: wrap;
}
.pub-foot-legal { display: flex; gap: 18px; }
.pub-foot-link { font-size: 12.5px; color: var(--text-secondary); }
.pub-foot-link.is-soon { opacity: .6; cursor: default; }
.pub-foot-copy { font-size: 12.5px; color: var(--text-tertiary); }

@media (max-width: 720px) {
    .pub-header { gap: 14px; padding: 14px 18px; }
    /* Collapse the inline links behind the burger. */
    .pub-nav { display: none; }
    .pub-actions { display: none; }
    .pub-burger { display: inline-flex; }
}
</style>
