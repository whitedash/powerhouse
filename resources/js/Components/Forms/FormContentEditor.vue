<script setup>
/**
 * Restricted rich-text editor for the form-builder "Text block" (placeholder)
 * field — built per /design/Form Builder · Rich Text Toolbar.html.
 *
 * It reuses the Tiptap foundation of Components/UI/RichTextEditor.vue but is
 * trimmed to exactly the tags FormContentSanitizer allows (p, br, strong, em, u,
 * ul, ol, li, a), so its output round-trips unchanged through
 * save → FormFieldRenderer → embed. StarterKit v3 already bundles the link and
 * underline extensions, so they are configured through it (no extra dependency);
 * heading/blockquote/code/codeBlock/strike/horizontalRule are disabled so the
 * editor cannot even produce a tag the sanitiser would strip.
 *
 * The link UI is the inline popover from the design — NOT window.prompt (native
 * dialogs are banned; the shared RichTextEditor.vue still uses prompt and is
 * untouched here so the Help/KB editor keeps its broad toolbar).
 */
import { ref, nextTick, onMounted, onBeforeUnmount, watch } from 'vue';
import { useEditor, EditorContent } from '@tiptap/vue-3';
import StarterKit from '@tiptap/starter-kit';
import Placeholder from '@tiptap/extension-placeholder';
import {
    IconBold, IconItalic, IconUnderline, IconList, IconListNumbers, IconLink, IconUnlink,
} from '@tabler/icons-vue';

const props = defineProps({
    modelValue: { type: String, default: '' },
    placeholder: { type: String, default: 'Enter your text block content…' },
});
const emit = defineEmits(['update:modelValue']);

const editor = useEditor({
    content: props.modelValue || '',
    extensions: [
        StarterKit.configure({
            // Disable everything outside the sanitiser allowlist so the editor
            // cannot emit a tag that would be stripped on save.
            heading: false,
            blockquote: false,
            code: false,
            codeBlock: false,
            strike: false,
            horizontalRule: false,
            // Bundled in StarterKit v3; configured to match the sanitiser's forced
            // anchor attributes (rel/target) and safe link schemes.
            link: {
                openOnClick: false,
                autolink: true,
                protocols: ['http', 'https', 'mailto'],
                HTMLAttributes: { rel: 'nofollow noopener noreferrer', target: '_blank' },
            },
            // underline stays enabled (bundled, default on)
        }),
        Placeholder.configure({ placeholder: props.placeholder }),
    ],
    onUpdate: ({ editor }) => emit('update:modelValue', editor.getHTML()),
});

// Keep the editor in sync if the parent overwrites modelValue (e.g. switching to
// a different field's edit panel reusing the same instance).
watch(() => props.modelValue, (val) => {
    if (! editor.value) return;
    if (val !== editor.value.getHTML()) {
        editor.value.commands.setContent(val || '', false);
    }
});

onBeforeUnmount(() => editor.value?.destroy());

function isActive(name, attrs) {
    return editor.value?.isActive(name, attrs) ?? false;
}

/* ─── Link popover (replaces window.prompt for insert + edit) ─── */
const linkOpen = ref(false);
const linkUrl = ref('');
const linkError = ref('');
const isEditingLink = ref(false);
const popRef = ref(null);
const linkBtnRef = ref(null);

function openLinkPopover() {
    if (! editor.value) return;
    const existing = editor.value.getAttributes('link')?.href ?? '';
    isEditingLink.value = existing !== '' || isActive('link');
    linkUrl.value = existing;
    linkError.value = '';
    linkOpen.value = true;
    nextTick(() => {
        const input = popRef.value?.querySelector('input');
        input?.focus();
        input?.select();
    });
}

function closeLinkPopover() {
    linkOpen.value = false;
    linkError.value = '';
    editor.value?.chain().focus().run(); // hand focus back to the editor
}

// Normalise to an allowlisted scheme. Bare domains get https://; an explicit
// disallowed scheme (javascript:, data:, …) is rejected in-UI. The server
// sanitiser is the backstop either way.
function normaliseUrl(raw) {
    const v = (raw ?? '').trim();
    if (v === '') return { remove: true };
    if (/^(https?:\/\/|mailto:)/i.test(v)) return { href: v };
    if (/^[a-z][a-z0-9+.-]*:/i.test(v)) return { error: 'Use an http://, https:// or mailto: link.' };
    return { href: 'https://' + v };
}

function applyLink() {
    const res = normaliseUrl(linkUrl.value);
    if (res.error) { linkError.value = res.error; return; }
    if (res.remove) { removeLink(); return; }
    editor.value?.chain().focus().extendMarkRange('link').setLink({ href: res.href }).run();
    linkOpen.value = false;
}

function removeLink() {
    editor.value?.chain().focus().extendMarkRange('link').unsetLink().run();
    linkOpen.value = false;
}

function onPopoverKeydown(e) {
    if (e.key === 'Enter') { e.preventDefault(); applyLink(); }
    else if (e.key === 'Escape') { e.preventDefault(); closeLinkPopover(); }
}

// ⌘K / Ctrl-K opens the link popover.
function onEditorKeydown(e) {
    if ((e.metaKey || e.ctrlKey) && (e.key === 'k' || e.key === 'K')) {
        e.preventDefault();
        openLinkPopover();
    }
}

// Click outside the popover (and not on the Link button) closes it.
function onDocMousedown(e) {
    if (! linkOpen.value) return;
    if (popRef.value?.contains(e.target)) return;
    const btnEl = linkBtnRef.value?.$el ?? linkBtnRef.value;
    if (btnEl?.contains?.(e.target)) return;
    linkOpen.value = false;
}
onMounted(() => document.addEventListener('mousedown', onDocMousedown));
onBeforeUnmount(() => document.removeEventListener('mousedown', onDocMousedown));
</script>

<template>
    <div class="fce" @keydown="onEditorKeydown">
        <div v-if="editor" class="fce-toolbar">
            <button type="button" class="fce-btn" :class="{ active: isActive('bold') }" title="Bold (⌘B)" @click="editor.chain().focus().toggleBold().run()">
                <IconBold :size="17" stroke-width="2" />
            </button>
            <button type="button" class="fce-btn" :class="{ active: isActive('italic') }" title="Italic (⌘I)" @click="editor.chain().focus().toggleItalic().run()">
                <IconItalic :size="17" stroke-width="2" />
            </button>
            <button type="button" class="fce-btn" :class="{ active: isActive('underline') }" title="Underline (⌘U)" @click="editor.chain().focus().toggleUnderline().run()">
                <IconUnderline :size="17" stroke-width="2" />
            </button>
            <span class="fce-divider" />
            <button type="button" class="fce-btn" :class="{ active: isActive('bulletList') }" title="Bullet list" @click="editor.chain().focus().toggleBulletList().run()">
                <IconList :size="17" stroke-width="2" />
            </button>
            <button type="button" class="fce-btn" :class="{ active: isActive('orderedList') }" title="Numbered list" @click="editor.chain().focus().toggleOrderedList().run()">
                <IconListNumbers :size="17" stroke-width="2" />
            </button>
            <span class="fce-divider" />
            <button ref="linkBtnRef" type="button" class="fce-btn" :class="{ active: isActive('link') || linkOpen }" title="Link (⌘K)" @click="linkOpen ? closeLinkPopover() : openLinkPopover()">
                <IconLink :size="17" stroke-width="2" />
            </button>
        </div>

        <!-- Inline link popover -->
        <div v-if="linkOpen" ref="popRef" class="fce-link-pop">
            <div class="fce-pop-label"><IconLink :size="13" stroke-width="2" /> Link URL</div>
            <div class="fce-pop-row">
                <input
                    v-model="linkUrl"
                    type="text"
                    class="fce-pop-input"
                    placeholder="https://…"
                    @keydown="onPopoverKeydown"
                />
                <button type="button" class="fce-apply" @click="applyLink">Apply</button>
            </div>
            <p v-if="linkError" class="fce-pop-error">{{ linkError }}</p>
            <div class="fce-pop-foot">
                <button v-if="isEditingLink" type="button" class="fce-remove" @click="removeLink">
                    <IconUnlink :size="14" stroke-width="2" /> Remove link
                </button>
                <span v-else class="fce-scheme">http · https · mailto</span>
                <span class="fce-hint">↵ Enter · Esc</span>
            </div>
        </div>

        <EditorContent :editor="editor" class="fce-content" />
    </div>
</template>

<style scoped>
.fce {
    position: relative;
    border: 1px solid var(--border);
    border-radius: var(--radius-md);
    background: #fff;
}
.fce:focus-within {
    border-color: var(--accent);
    box-shadow: 0 0 0 3px color-mix(in srgb, var(--accent) 18%, transparent);
}

.fce-toolbar {
    display: flex;
    align-items: center;
    gap: 2px;
    padding: 6px;
    border-bottom: 1px solid var(--border-soft);
    flex-wrap: wrap;
}
.fce-btn {
    appearance: none;
    border: 0;
    background: transparent;
    cursor: pointer;
    width: 30px;
    height: 30px;
    border-radius: var(--radius-sm);
    display: inline-grid;
    place-items: center;
    color: var(--text-secondary);
    transition: background .12s, color .12s;
}
.fce-btn:hover { background: var(--neutral-bg); color: var(--text-primary); }
.fce-btn.active {
    background: color-mix(in srgb, var(--accent) 14%, transparent);
    color: color-mix(in srgb, var(--accent), var(--text-primary) 40%);
}
.fce-divider { width: 1px; height: 18px; background: var(--border); margin: 0 5px; }

.fce-content { padding: 12px 14px; }
.fce-content :deep(.ProseMirror) {
    min-height: 96px;
    outline: none;
    font: 400 14px/1.5 'Inter', sans-serif;
    color: var(--text-primary);
}
.fce-content :deep(.ProseMirror p) { margin: 0 0 .55rem; }
.fce-content :deep(.ProseMirror p:last-child) { margin-bottom: 0; }
.fce-content :deep(.ProseMirror ul),
.fce-content :deep(.ProseMirror ol) { margin: .35rem 0; padding-left: 1.3rem; }
.fce-content :deep(.ProseMirror li) { margin: .1rem 0; }
.fce-content :deep(.ProseMirror a) { color: var(--accent); text-decoration: underline; }
/* Tiptap Placeholder extension */
.fce-content :deep(.ProseMirror p.is-editor-empty:first-child::before) {
    content: attr(data-placeholder);
    color: var(--text-tertiary);
    float: left;
    height: 0;
    pointer-events: none;
}

/* ─── Link popover ─── */
.fce-link-pop {
    position: absolute;
    top: 46px;
    left: 6px;
    z-index: 30;
    width: 320px;
    background: #fff;
    border: 1px solid var(--border);
    border-radius: var(--radius-md);
    box-shadow: var(--shadow-md);
    padding: 10px;
}
.fce-link-pop::before {
    content: "";
    position: absolute;
    left: 22px;
    top: -6px;
    width: 10px;
    height: 10px;
    background: #fff;
    border-left: 1px solid var(--border);
    border-top: 1px solid var(--border);
    transform: rotate(45deg);
}
.fce-pop-label {
    font: 500 11px/1.2 'Inter', sans-serif;
    text-transform: uppercase;
    letter-spacing: .1em;
    color: var(--text-tertiary);
    margin-bottom: 8px;
    display: flex;
    align-items: center;
    gap: 6px;
}
.fce-pop-row { display: flex; gap: 8px; align-items: center; }
.fce-pop-input {
    flex: 1;
    min-width: 0;
    height: 32px;
    font: 400 13px/1.3 'Inter', sans-serif;
    color: var(--text-primary);
    background: #fff;
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    padding: 0 10px;
    outline: 0;
}
.fce-pop-input:focus {
    border-color: var(--accent);
    box-shadow: 0 0 0 3px color-mix(in srgb, var(--accent) 18%, transparent);
}
.fce-apply {
    appearance: none;
    border: 0;
    cursor: pointer;
    height: 32px;
    padding: 0 12px;
    border-radius: var(--radius-sm);
    background: var(--accent);
    color: var(--bg-navy);
    font: 600 13px/1 'Inter', sans-serif;
}
.fce-pop-error { margin: 8px 0 0; font: 400 12px/1.4 'Inter', sans-serif; color: var(--danger); }
.fce-pop-foot { display: flex; align-items: center; justify-content: space-between; margin-top: 8px; }
.fce-remove {
    appearance: none;
    border: 0;
    background: transparent;
    cursor: pointer;
    color: var(--danger);
    font: 500 12px/1 'Inter', sans-serif;
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 0;
}
.fce-scheme { font: 500 11px/1.3 'JetBrains Mono', monospace; color: var(--text-tertiary); }
.fce-hint { font: 400 11px/1.3 'Inter', sans-serif; color: var(--text-tertiary); }
</style>
