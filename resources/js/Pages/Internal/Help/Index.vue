<script setup>
import { computed, ref, watch } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import {
    Dialog,
    DialogPanel,
    Menu,
    MenuButton,
    MenuItem,
    MenuItems,
    TransitionChild,
    TransitionRoot,
} from '@headlessui/vue';
import {
    IconPlus,
    IconSearch,
    IconBookmark,
    IconLock,
    IconWorld,
    IconEye,
    IconDots,
    IconX,
    IconAlertCircle,
    IconCircleCheck,
    IconDeviceFloppy,
} from '@tabler/icons-vue';
import InternalLayout from '@/Layouts/InternalLayout.vue';
import ConfirmModal from '@/Components/UI/ConfirmModal.vue';

const props = defineProps({
    articles: { type: Array, default: () => [] },
    categories: { type: Array, default: () => [] },
    filters: { type: Object, default: () => ({}) },
});

/*
 * Sidebar filter: "all" or a category name. The category list is
 * derived server-side from is_published rows so an internal-only
 * category still shows up here (the rail is a staff view).
 */
const activeFilter = ref(props.filters?.category ?? 'all');
const searchTerm = ref(props.filters?.search ?? '');

const totalCount = computed(() => props.articles.length);

const countsByCategory = computed(() => {
    const map = {};
    for (const a of props.articles) {
        map[a.category] = (map[a.category] ?? 0) + 1;
    }
    return map;
});

const visibleArticles = computed(() => {
    if (activeFilter.value === 'all') return props.articles;
    return props.articles.filter((a) => a.category === activeFilter.value);
});

/*
 * Group within the selected filter so a single category header
 * still appears above its cards (helps when "All" is selected
 * and the page lists multiple categories back-to-back).
 */
const groupedArticles = computed(() => {
    const groups = new Map();
    for (const a of visibleArticles.value) {
        if (! groups.has(a.category)) groups.set(a.category, []);
        groups.get(a.category).push(a);
    }
    return [...groups.entries()].map(([category, items]) => ({ category, items }));
});

// Debounce search so each keystroke doesn't trigger a roundtrip.
let searchTimer = null;
watch(searchTerm, (val) => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => {
        router.get('/help', { search: val || undefined }, {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        });
    }, 300);
});

function setFilter(value) {
    activeFilter.value = value;
}

/* ── EDITOR SLIDE-OVER ─────────────────────────────────────────── */
const showEditor = ref(false);
const editingId = ref(null); // null = create

const form = useForm({
    title: '',
    category: '',
    content: '',
    is_public: true,
    is_published: true,
    sort_order: 0,
});

function openCreate() {
    editingId.value = null;
    form.reset();
    form.is_public = true;
    form.is_published = true;
    showEditor.value = true;
}

function openEdit(article) {
    // The index payload only carries the excerpt — pull full content
    // from the show endpoint so the editor opens with the live body.
    editingId.value = article.id;
    form.title = article.title;
    form.category = article.category;
    form.is_public = article.is_public;
    form.is_published = article.is_published;
    form.sort_order = 0;
    form.content = '';
    form.clearErrors();
    showEditor.value = true;

    fetch(`/help/${article.slug}`, {
        headers: {
            'X-Inertia': 'true',
            'X-Requested-With': 'XMLHttpRequest',
            Accept: 'application/json',
        },
    })
        .then((r) => r.json())
        .then((res) => {
            const a = res?.props?.article;
            if (a && editingId.value === article.id) {
                form.content = a.content_raw ?? '';
            }
        })
        .catch(() => {});
}

function closeEditor() {
    showEditor.value = false;
    editingId.value = null;
}

function submit() {
    const onSuccess = () => {
        showEditor.value = false;
        editingId.value = null;
        form.reset();
    };

    if (editingId.value) {
        form.put(`/help/${editingId.value}`, { preserveScroll: true, onSuccess });
    } else {
        form.post('/help', { preserveScroll: true, onSuccess });
    }
}

/* ── DELETE / UNPUBLISH ────────────────────────────────────────── */
const showDeleteModal = ref(false);
const deleteTarget = ref(null);
const deleteProcessing = ref(false);

function askDelete(article) {
    deleteTarget.value = article;
    showDeleteModal.value = true;
}

function performDelete() {
    if (! deleteTarget.value) return;
    deleteProcessing.value = true;
    router.delete(`/help/${deleteTarget.value.id}`, {
        preserveScroll: true,
        onFinish: () => {
            deleteProcessing.value = false;
            showDeleteModal.value = false;
            deleteTarget.value = null;
        },
    });
}

const deleteMessage = computed(() =>
    deleteTarget.value
        ? `'${deleteTarget.value.title}' will be unpublished and hidden from every listing.`
        : '',
);

function quickUnpublish(article) {
    router.delete(`/help/${article.id}`, { preserveScroll: true });
}

const breadcrumbs = [
    { label: 'Help & docs' },
];
</script>

<template>
    <Head title="Help & docs" />
    <InternalLayout title="Help & docs" :breadcrumbs="breadcrumbs" active-nav="help">
        <template #topbar-actions>
            <div class="topbar-search" style="width: 280px;">
                <span class="search-icon"><IconSearch :size="16" stroke-width="1.75" /></span>
                <input v-model="searchTerm" placeholder="Search articles…">
            </div>
            <button type="button" class="btn btn-primary" @click="openCreate">
                <IconPlus :size="14" stroke-width="1.75" />
                New article
            </button>
        </template>

        <div class="help">
            <div
                v-if="$page.props.flash?.success"
                style="margin-bottom: 14px; padding: 10px 14px; background: var(--success-bg); color: var(--success); border: 1px solid #BBF7D0; border-radius: var(--radius-md); font: 500 13px/1.4 'Inter', sans-serif; display: flex; align-items: center; gap: 8px;"
            >
                <IconCircleCheck :size="16" stroke-width="2" />{{ $page.props.flash.success }}
            </div>
            <div
                v-if="$page.props.flash?.error"
                style="margin-bottom: 14px; padding: 10px 14px; background: var(--danger-bg); color: var(--danger); border: 1px solid #FECACA; border-radius: var(--radius-md); font: 500 13px/1.4 'Inter', sans-serif; display: flex; align-items: center; gap: 8px;"
            >
                <IconAlertCircle :size="16" stroke-width="2" />{{ $page.props.flash.error }}
            </div>

            <div class="help-layout">
                <!-- LEFT — category filter rail -->
                <aside class="help-sidebar">
                    <div class="help-sidebar-header">Categories</div>
                    <button
                        type="button"
                        class="help-filter-item"
                        :class="{ active: activeFilter === 'all' }"
                        @click="setFilter('all')"
                    >
                        <span>All articles</span>
                        <span class="help-filter-count">{{ totalCount }}</span>
                    </button>
                    <button
                        v-for="cat in categories"
                        :key="`fc-${cat}`"
                        type="button"
                        class="help-filter-item"
                        :class="{ active: activeFilter === cat }"
                        @click="setFilter(cat)"
                    >
                        <span>{{ cat }}</span>
                        <span class="help-filter-count">{{ countsByCategory[cat] ?? 0 }}</span>
                    </button>
                </aside>

                <!-- RIGHT — article cards -->
                <section class="help-main">
                    <div v-if="totalCount === 0" class="help-empty">
                        <IconBookmark :size="48" stroke-width="1.5" />
                        <div class="help-empty-title">No articles yet</div>
                        <div class="help-empty-sub">
                            Start documenting your platform — internal runbooks, customer FAQs,
                            anything reusable.
                        </div>
                        <button type="button" class="btn btn-primary" @click="openCreate">
                            <IconPlus :size="14" stroke-width="1.75" />
                            Add first article
                        </button>
                    </div>

                    <template v-else>
                        <div v-for="group in groupedArticles" :key="group.category" class="help-group">
                            <div class="help-group-header">
                                <div class="help-group-bar" />
                                <h3>{{ group.category }}</h3>
                                <span class="help-group-count">{{ group.items.length }}</span>
                            </div>

                            <div class="help-cards">
                                <article v-for="a in group.items" :key="a.id" class="help-card">
                                    <div class="help-card-top">
                                        <span class="badge badge-info badge-sm">{{ a.category }}</span>
                                        <Menu as="div" class="dd-menu">
                                            <MenuButton class="icon-btn" aria-label="Article actions">
                                                <IconDots :size="16" stroke-width="1.75" />
                                            </MenuButton>
                                            <MenuItems class="dd-popover right-align">
                                                <MenuItem v-slot="{ active }">
                                                    <button type="button" :class="['dd-option', { active }]" @click="openEdit(a)">Edit</button>
                                                </MenuItem>
                                                <MenuItem v-slot="{ active }">
                                                    <button type="button" :class="['dd-option', { active }]" @click="quickUnpublish(a)">Unpublish</button>
                                                </MenuItem>
                                                <div style="height: 1px; background: var(--border-soft); margin: 4px 0;" />
                                                <MenuItem v-slot="{ active }">
                                                    <button type="button" :class="['dd-option', { active }]" style="color: var(--danger);" @click="askDelete(a)">Delete</button>
                                                </MenuItem>
                                            </MenuItems>
                                        </Menu>
                                    </div>

                                    <Link :href="`/help/${a.slug}`" class="help-card-title">{{ a.title }}</Link>
                                    <p class="help-card-excerpt">{{ a.excerpt }}</p>

                                    <footer class="help-card-footer">
                                        <span class="help-meta-item">{{ a.author ?? 'Unknown' }}</span>
                                        <span class="help-meta-sep">·</span>
                                        <span class="help-meta-item">{{ a.updated_at }}</span>
                                        <span class="help-meta-sep">·</span>
                                        <span class="help-meta-item">
                                            <IconEye :size="12" stroke-width="2" />
                                            {{ a.views }}
                                        </span>
                                        <span class="help-meta-spacer" />
                                        <span class="badge badge-sm" :class="a.is_public ? 'badge-active' : 'badge-inactive'">
                                            <component :is="a.is_public ? IconWorld : IconLock" :size="11" stroke-width="2" />
                                            {{ a.is_public ? 'Public' : 'Internal' }}
                                        </span>
                                    </footer>
                                </article>
                            </div>
                        </div>
                    </template>
                </section>
            </div>
        </div>

        <!-- ARTICLE EDITOR SLIDE-OVER (640px) -->
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
                                <h2>{{ editingId ? 'Edit article' : 'New article' }}</h2>
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
                                                placeholder="How to onboard a new customer"
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
                                                placeholder="e.g. Getting started"
                                                list="kb-category-options"
                                                required
                                            >
                                            <datalist id="kb-category-options">
                                                <option v-for="cat in categories" :key="cat" :value="cat" />
                                            </datalist>
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
                                                placeholder="# Heading&#10;&#10;Write the article body here…"
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

        <ConfirmModal
            v-model:show="showDeleteModal"
            :title="deleteTarget ? `Delete '${deleteTarget.title}'?` : 'Delete article?'"
            :message="deleteMessage"
            confirm-label="Delete"
            variant="danger"
            :loading="deleteProcessing"
            @confirm="performDelete"
        />
    </InternalLayout>
</template>
