<script setup>
import { ref, watch } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { IconSearch, IconBook2, IconChevronLeft, IconChevronRight, IconArrowRight } from '@tabler/icons-vue';
import PublicLayout from '@/Layouts/PublicLayout.vue';

const props = defineProps({
    articles: { type: Object, default: () => ({ data: [] }) },
    categories: { type: Array, default: () => [] },
    filters: { type: Object, default: () => ({}) },
});

const searchInput = ref(props.filters?.search ?? '');
let searchTimer = null;
watch(searchInput, (v) => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => {
        if ((v ?? '') === (props.filters?.search ?? '')) return;
        navigate({ search: v });
    }, 350);
});

function navigate(patch) {
    const q = { ...(props.filters ?? {}), ...patch };
    Object.keys(q).forEach((k) => { if (! q[k]) delete q[k]; });
    router.get('/kb', q, { preserveState: true, preserveScroll: true, replace: true });
}
function setCategory(c) { navigate({ category: props.filters?.category === c ? null : c }); }
function go(url) { if (url) router.visit(url, { preserveScroll: true }); }
</script>

<template>
    <Head title="Knowledge base · Whitedash" />

    <PublicLayout>
        <section class="kb-hero">
            <div class="kb-hero-inner">
                <h1>Knowledge base</h1>
                <p>Guides and answers for Whitedash products — no account needed.</p>
                <div class="kb-search">
                    <IconSearch :size="18" stroke-width="1.75" />
                    <input v-model="searchInput" type="search" placeholder="Search the knowledge base…">
                </div>
            </div>
        </section>

        <section class="kb-body">
            <div v-if="categories.length" class="kb-cats">
                <button type="button" :class="['kb-cat', { active: ! filters.category }]" @click="navigate({ category: null })">All</button>
                <button
                    v-for="c in categories"
                    :key="c"
                    type="button"
                    :class="['kb-cat', { active: filters.category === c }]"
                    @click="setCategory(c)"
                >{{ c }}</button>
            </div>

            <div v-if="articles.data.length" class="kb-list">
                <Link v-for="a in articles.data" :key="a.slug" :href="`/kb/${a.slug}`" class="kb-card">
                    <span v-if="a.category" class="kb-card-cat">{{ a.category }}</span>
                    <span class="kb-card-title">{{ a.title }}</span>
                    <span class="kb-card-excerpt">{{ a.excerpt }}</span>
                    <span class="kb-card-more">Read <IconArrowRight :size="13" stroke-width="1.75" /></span>
                </Link>
            </div>

            <div v-else class="kb-empty">
                <IconBook2 :size="40" stroke-width="1.5" />
                <h3>No articles found</h3>
                <p>{{ filters.search || filters.category ? 'Try a different search or category.' : 'Articles will appear here soon.' }}</p>
            </div>

            <div v-if="articles.data.length" class="kb-foot">
                <div class="info">Showing <strong>{{ articles.from ?? 0 }}–{{ articles.to ?? 0 }}</strong> of <strong>{{ articles.total }}</strong></div>
                <div class="right">
                    <button type="button" class="pg-btn" :disabled="! articles.prev_page_url" @click="go(articles.prev_page_url)">
                        <IconChevronLeft :size="14" stroke-width="1.75" /> Previous
                    </button>
                    <button type="button" class="pg-btn" :disabled="! articles.next_page_url" @click="go(articles.next_page_url)">
                        Next <IconChevronRight :size="14" stroke-width="1.75" />
                    </button>
                </div>
            </div>
        </section>
    </PublicLayout>
</template>

<style scoped>
.kb-hero { background: var(--bg-navy); color: #fff; }
.kb-hero-inner { max-width: 760px; margin: 0 auto; padding: 56px 32px 48px; text-align: center; }
.kb-hero-inner h1 { font-size: 34px; font-weight: 700; margin: 0 0 10px; letter-spacing: -.02em; }
.kb-hero-inner p { color: #CBD5E1; font-size: 15.5px; margin: 0 0 24px; }
.kb-search {
    display: flex; align-items: center; gap: 10px; background: #fff;
    border-radius: var(--radius-lg); padding: 0 14px; max-width: 540px; margin: 0 auto;
    color: var(--text-tertiary);
}
.kb-search input { flex: 1; border: 0; outline: none; height: 46px; font-size: 15px; background: transparent; color: var(--text-primary); }

.kb-body { max-width: 1080px; margin: 0 auto; padding: 32px; }
.kb-cats { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 24px; }
.kb-cat {
    padding: 6px 14px; border-radius: 999px; border: 1px solid var(--border);
    background: var(--card-bg); font-size: 13px; font-weight: 500; color: var(--text-secondary); cursor: pointer;
}
.kb-cat:hover { border-color: #CBD5E1; }
.kb-cat.active { background: var(--bg-navy); color: #fff; border-color: var(--bg-navy); }

.kb-list { display: grid; grid-template-columns: repeat(2, 1fr); gap: 16px; }
.kb-card {
    display: flex; flex-direction: column; gap: 6px; padding: 20px 22px;
    background: var(--card-bg); border: 1px solid var(--border); border-radius: var(--radius-xl);
    box-shadow: var(--shadow-sm); text-decoration: none; transition: box-shadow .15s, transform .15s;
}
.kb-card:hover { box-shadow: var(--shadow-md); transform: translateY(-2px); }
.kb-card-cat { font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: .04em; color: var(--accent); }
.kb-card-title { font-size: 16px; font-weight: 700; color: var(--text-primary); }
.kb-card-excerpt { font-size: 13.5px; line-height: 1.55; color: var(--text-secondary); }
.kb-card-more { display: inline-flex; align-items: center; gap: 5px; margin-top: 4px; font-size: 12.5px; font-weight: 600; color: var(--accent); }

.kb-empty { text-align: center; padding: 64px 24px; color: var(--text-muted); }
.kb-empty h3 { margin: 8px 0 4px; color: var(--text-primary); }
.kb-empty p { margin: 0; }

.kb-foot { display: flex; align-items: center; justify-content: space-between; margin-top: 24px; gap: 16px; flex-wrap: wrap; }
.kb-foot .info { font-size: 13px; color: var(--text-secondary); }
.kb-foot .right { display: flex; gap: 8px; }

@media (max-width: 720px) { .kb-list { grid-template-columns: 1fr; } }
</style>
