<script setup>
import { computed, ref } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import {
    Dialog,
    DialogPanel,
    TransitionChild,
    TransitionRoot,
} from '@headlessui/vue';
import {
    IconArrowLeft,
    IconPencil,
    IconEye,
    IconWorld,
    IconLock,
    IconX,
    IconDeviceFloppy,
    IconBookmark,
} from '@tabler/icons-vue';
import InternalLayout from '@/Layouts/InternalLayout.vue';

const props = defineProps({
    article: { type: Object, required: true },
    related: { type: Array, default: () => [] },
});

const breadcrumbs = computed(() => [
    { label: 'Help & docs', href: '/help' },
    { label: props.article.title },
]);

/* ── INLINE EDITOR — same shape as the Index slide-over so an
 * operator who lands on the article can fix a typo without having
 * to back up to the list. ─────────────────────────────────────── */
const showEditor = ref(false);

const form = useForm({
    title: props.article.title,
    category: props.article.category,
    content: props.article.content_raw,
    is_public: props.article.is_public,
    is_published: props.article.is_published,
    sort_order: 0,
});

function openEdit() {
    form.title = props.article.title;
    form.category = props.article.category;
    form.content = props.article.content_raw;
    form.is_public = props.article.is_public;
    form.is_published = props.article.is_published;
    form.clearErrors();
    showEditor.value = true;
}

function closeEditor() {
    showEditor.value = false;
}

function submit() {
    form.put(`/help/${props.article.id}`, {
        preserveScroll: true,
        onSuccess: () => {
            showEditor.value = false;
            // Re-fetch this page so the rendered HTML reflects the
            // latest content without a full reload.
            router.reload({ only: ['article', 'related'] });
        },
    });
}
</script>

<template>
    <Head :title="article.title" />
    <InternalLayout :title="article.title" :breadcrumbs="breadcrumbs" active-nav="help">
        <template #topbar-actions>
            <button type="button" class="btn btn-primary" @click="openEdit">
                <IconPencil :size="14" stroke-width="1.75" />
                Edit
            </button>
        </template>

        <div class="help-show">
            <div class="help-show-grid">
                <!-- LEFT — article body -->
                <article class="help-article">
                    <header class="help-article-header">
                        <div class="help-article-badges">
                            <span class="badge badge-info">{{ article.category }}</span>
                            <span class="badge" :class="article.is_public ? 'badge-active' : 'badge-inactive'">
                                <component :is="article.is_public ? IconWorld : IconLock" :size="11" stroke-width="2" />
                                {{ article.is_public ? 'Public' : 'Internal' }}
                            </span>
                            <span v-if="! article.is_published" class="badge badge-pending">Unpublished</span>
                        </div>
                        <h1 class="help-article-title">{{ article.title }}</h1>
                        <div class="help-article-meta">
                            <span>{{ article.author ?? 'Unknown' }}</span>
                            <span class="dot">·</span>
                            <span>Updated {{ article.updated_at }}</span>
                            <span class="dot">·</span>
                            <span class="help-article-meta-views">
                                <IconEye :size="13" stroke-width="2" />
                                {{ article.views }}
                            </span>
                        </div>
                    </header>

                    <div class="help-article-divider" />

                    <!--
                      content_html is either HTML authored by staff via the
                      Tiptap rich-text editor (allow-listed nodes only) or
                      legacy Markdown rendered through league/commonmark
                      with html_input=escape. Either way the body has only
                      ever been touched by staff, so v-html is safe here.
                    -->
                    <div class="help-article-content prose" v-html="article.content_html" />
                </article>

                <!-- RIGHT — sidebar -->
                <aside class="help-show-sidebar">
                    <div class="help-info-card">
                        <div class="help-info-card-title">Article info</div>
                        <div class="help-info-row">
                            <span class="help-info-label">Category</span>
                            <span class="help-info-value">{{ article.category }}</span>
                        </div>
                        <div class="help-info-row">
                            <span class="help-info-label">Author</span>
                            <span class="help-info-value">{{ article.author ?? 'Unknown' }}</span>
                        </div>
                        <div class="help-info-row">
                            <span class="help-info-label">Updated</span>
                            <span class="help-info-value">{{ article.updated_at }}</span>
                        </div>
                        <div class="help-info-row">
                            <span class="help-info-label">Views</span>
                            <span class="help-info-value">{{ article.views }}</span>
                        </div>
                        <div class="help-info-row">
                            <span class="help-info-label">Visibility</span>
                            <span class="help-info-value">{{ article.is_public ? 'Public' : 'Internal' }}</span>
                        </div>
                    </div>

                    <div class="help-info-card">
                        <div class="help-info-card-title">Related articles</div>
                        <div v-if="related.length === 0" class="help-related-empty">
                            <IconBookmark :size="20" stroke-width="1.5" />
                            <span>No other articles in this category yet.</span>
                        </div>
                        <Link
                            v-for="r in related"
                            :key="r.id"
                            :href="`/help/${r.slug}`"
                            class="help-related-row"
                        >
                            {{ r.title }}
                        </Link>
                    </div>

                    <Link href="/help" class="help-back-link">
                        <IconArrowLeft :size="14" stroke-width="1.75" />
                        Back to Help
                    </Link>
                </aside>
            </div>
        </div>

        <!-- INLINE EDIT SLIDE-OVER -->
        <TransitionRoot as="template" :show="showEditor">
            <Dialog as="div" class="slide-over-dialog" @close="closeEditor">
                <TransitionChild
                    as="template"
                    enter="transition-opacity ease-out duration-200" enter-from="opacity-0" enter-to="opacity-100"
                    leave="transition-opacity ease-in duration-150" leave-from="opacity-100" leave-to="opacity-0"
                >
                    <div class="slide-over-backdrop" />
                </TransitionChild>
                <TransitionChild
                    as="template"
                    enter="transform transition ease-out duration-200" enter-from="translate-x-full" enter-to="translate-x-0"
                    leave="transform transition ease-in duration-150" leave-from="translate-x-0" leave-to="translate-x-full"
                >
                    <DialogPanel class="slide-over-panel" style="width: 640px;">
                        <form class="slide-over-form" @submit.prevent="submit">
                            <header class="slide-over-header">
                                <h2>Edit article</h2>
                                <button type="button" class="icon-btn" aria-label="Close" @click="closeEditor">
                                    <IconX :size="18" stroke-width="1.75" />
                                </button>
                            </header>

                            <div class="slide-over-body">
                                <div class="form-section">
                                    <div class="form-row single">
                                        <div class="form-field">
                                            <label>Title<span class="req">*</span></label>
                                            <input
                                                v-model="form.title"
                                                type="text"
                                                class="help-title-input"
                                                :class="{ 'has-err': form.errors.title }"
                                                required
                                            >
                                            <div v-if="form.errors.title" class="err">{{ form.errors.title }}</div>
                                        </div>
                                    </div>
                                    <div class="form-row single">
                                        <div class="form-field">
                                            <label>Category<span class="req">*</span></label>
                                            <input
                                                v-model="form.category"
                                                type="text"
                                                :class="{ 'has-err': form.errors.category }"
                                                required
                                            >
                                            <div v-if="form.errors.category" class="err">{{ form.errors.category }}</div>
                                        </div>
                                    </div>
                                    <div class="form-row single">
                                        <div class="form-field">
                                            <label>
                                                Content<span class="req">*</span>
                                                <span style="float: right; color: var(--text-tertiary); font-weight: 400; font-size: 11px;">Markdown supported</span>
                                            </label>
                                            <textarea
                                                v-model="form.content"
                                                rows="15"
                                                class="help-content-input"
                                                :class="{ 'has-err': form.errors.content }"
                                                required
                                            />
                                            <div v-if="form.errors.content" class="err">{{ form.errors.content }}</div>
                                        </div>
                                    </div>
                                </div>

                                <div class="form-section">
                                    <h3>Visibility</h3>
                                    <div class="status-rows">
                                        <div class="set-row">
                                            <div>
                                                <div class="nm">Public</div>
                                                <div class="sb">Visible to customers in the portal.</div>
                                            </div>
                                            <button type="button" class="toggle" :class="{ on: form.is_public }" aria-label="Toggle public" @click="form.is_public = ! form.is_public" />
                                        </div>
                                        <div class="set-row">
                                            <div>
                                                <div class="nm">Published</div>
                                                <div class="sb">Unpublished drafts are hidden from every listing.</div>
                                            </div>
                                            <button type="button" class="toggle" :class="{ on: form.is_published }" aria-label="Toggle published" @click="form.is_published = ! form.is_published" />
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <footer class="slide-over-footer">
                                <button type="button" class="btn btn-secondary" @click="closeEditor">Cancel</button>
                                <button type="submit" class="btn btn-primary" :disabled="form.processing">
                                    <IconDeviceFloppy :size="15" stroke-width="1.75" />
                                    {{ form.processing ? 'Saving…' : 'Save article' }}
                                </button>
                            </footer>
                        </form>
                    </DialogPanel>
                </TransitionChild>
            </Dialog>
        </TransitionRoot>
    </InternalLayout>
</template>
