<script setup>
import { ref } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import { IconChevronRight, IconLifebuoy } from '@tabler/icons-vue';
import PublicLayout from '@/Layouts/PublicLayout.vue';

const props = defineProps({
    article: { type: Object, required: true },
    related: { type: Array, default: () => [] },
});

// Non-persisted "Was this helpful?" — local UI only in v1.
const feedback = ref(null);
function vote(v) { feedback.value = v; }
</script>

<template>
    <Head :title="`${article.title} · Knowledge base`" />

    <PublicLayout>
        <article class="kb-article">
            <nav class="kb-crumb">
                <Link href="/kb">Knowledge base</Link>
                <IconChevronRight :size="13" stroke-width="2" />
                <span v-if="article.category">{{ article.category }}</span>
            </nav>

            <h1 class="kb-title">{{ article.title }}</h1>
            <div class="kb-meta">
                <span v-if="article.updated_at">Updated {{ article.updated_at }}</span>
            </div>

            <!-- content_html is sanitised server-side by KbContentService::renderSafe
                 (symfony/html-sanitizer allow-list) before it reaches here. -->
            <div class="kb-content prose" v-html="article.content_html" />

            <!-- Was this helpful? (non-persisted v1) -->
            <div class="kb-helpful">
                <template v-if="feedback === null">
                    <span>Was this helpful?</span>
                    <button type="button" class="btn btn-secondary btn-sm" @click="vote('yes')">👍 Yes</button>
                    <button type="button" class="btn btn-secondary btn-sm" @click="vote('no')">👎 No</button>
                </template>
                <span v-else class="kb-helpful-thanks">Thanks for your feedback!</span>
            </div>

            <!-- Still need help? → /support -->
            <div class="kb-cta">
                <div class="kb-cta-ic"><IconLifebuoy :size="22" stroke-width="1.75" /></div>
                <div class="kb-cta-text">
                    <strong>Still need help?</strong>
                    <span>Get in touch with our support team.</span>
                </div>
                <Link href="/support" class="btn btn-secondary btn-sm">Submit a ticket</Link>
            </div>

            <div v-if="related.length" class="kb-related">
                <h2>Related articles</h2>
                <ul>
                    <li v-for="r in related" :key="r.slug">
                        <Link :href="`/kb/${r.slug}`">{{ r.title }} <IconChevronRight :size="13" stroke-width="2" /></Link>
                    </li>
                </ul>
            </div>
        </article>
    </PublicLayout>
</template>

<style scoped>
.kb-article { max-width: 760px; margin: 0 auto; padding: 40px 32px; }
.kb-crumb { display: flex; align-items: center; gap: 6px; font-size: 13px; color: var(--text-secondary); margin-bottom: 16px; }
.kb-crumb a { color: var(--accent); text-decoration: none; }
.kb-crumb a:hover { text-decoration: underline; }
.kb-title { font-size: 32px; font-weight: 700; letter-spacing: -.02em; margin: 0 0 8px; }
.kb-meta { font-size: 13px; color: var(--text-tertiary); margin-bottom: 28px; }

/* Rendered article body. Tokens only; spacing for the sanitised HTML. */
.kb-content { font-size: 15.5px; line-height: 1.7; color: var(--text-primary); }
.kb-content :deep(h2) { font-size: 22px; font-weight: 700; margin: 32px 0 12px; }
.kb-content :deep(h3) { font-size: 18px; font-weight: 700; margin: 24px 0 10px; }
.kb-content :deep(p) { margin: 0 0 16px; }
.kb-content :deep(ul), .kb-content :deep(ol) { margin: 0 0 16px; padding-left: 24px; }
.kb-content :deep(li) { margin: 4px 0; }
.kb-content :deep(a) { color: var(--accent); text-decoration: underline; }
.kb-content :deep(code) { background: var(--neutral-bg); padding: 2px 6px; border-radius: var(--radius-sm); font-size: 13.5px; }
.kb-content :deep(pre) { background: var(--bg-navy); color: #E2E8F0; padding: 16px; border-radius: var(--radius-md); overflow-x: auto; margin: 0 0 16px; }
.kb-content :deep(pre code) { background: none; padding: 0; color: inherit; }
.kb-content :deep(blockquote) { border-left: 3px solid var(--border); padding-left: 16px; color: var(--text-secondary); margin: 0 0 16px; }
.kb-content :deep(table) { width: 100%; border-collapse: collapse; margin: 0 0 16px; }
.kb-content :deep(th), .kb-content :deep(td) { border: 1px solid var(--border); padding: 8px 10px; text-align: left; }

.kb-helpful { display: flex; align-items: center; gap: 10px; margin: 36px 0 0; padding-top: 24px; border-top: 1px solid var(--border); font-size: 14px; color: var(--text-secondary); }
.kb-helpful-thanks { color: var(--success); font-weight: 600; }

.kb-cta {
    display: flex; align-items: center; gap: 14px; margin-top: 24px; padding: 18px 20px;
    background: var(--card-bg); border: 1px solid var(--border); border-radius: var(--radius-xl);
}
.kb-cta-ic { width: 44px; height: 44px; flex-shrink: 0; border-radius: var(--radius-lg); display: flex; align-items: center; justify-content: center; background: var(--info-bg); color: var(--info); }
.kb-cta-text { display: flex; flex-direction: column; flex: 1; }
.kb-cta-text strong { font-size: 14.5px; }
.kb-cta-text span { font-size: 13px; color: var(--text-secondary); }
.kb-cta-soon { display: inline-flex; align-items: center; gap: 8px; font-size: 13px; color: var(--text-tertiary); }
.soon { font-size: 9.5px; font-weight: 600; text-transform: uppercase; letter-spacing: .04em; color: var(--text-secondary); background: var(--neutral-bg); border: 1px solid var(--border); border-radius: 999px; padding: 1px 6px; }

.kb-related { margin-top: 40px; }
.kb-related h2 { font-size: 16px; font-weight: 700; margin: 0 0 12px; }
.kb-related ul { list-style: none; margin: 0; padding: 0; }
.kb-related li { border-top: 1px solid var(--border-soft); }
.kb-related li a { display: flex; align-items: center; gap: 6px; padding: 12px 0; color: var(--text-primary); text-decoration: none; font-weight: 500; }
.kb-related li a:hover { color: var(--accent); }
</style>
