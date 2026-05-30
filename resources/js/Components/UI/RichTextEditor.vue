<script setup>
import { onBeforeUnmount, watch } from 'vue';
import { useEditor, EditorContent } from '@tiptap/vue-3';
import StarterKit from '@tiptap/starter-kit';
import Image from '@tiptap/extension-image';
import Link from '@tiptap/extension-link';
import Placeholder from '@tiptap/extension-placeholder';
import {
    IconBold,
    IconItalic,
    IconHeading,
    IconList,
    IconListNumbers,
    IconQuote,
    IconCode,
    IconTerminal2,
    IconPhoto,
    IconLink,
    IconArrowBackUp,
    IconArrowForwardUp,
} from '@tabler/icons-vue';

const props = defineProps({
    modelValue: { type: String, default: '' },
    placeholder: { type: String, default: 'Write something…' },
});

const emit = defineEmits(['update:modelValue']);

const editor = useEditor({
    content: props.modelValue || '',
    extensions: [
        StarterKit,
        // Block-level (not inline) image so it doesn't get tangled
        // up in a paragraph's text flow on copy/paste.
        Image.configure({ inline: false, allowBase64: false }),
        Link.configure({
            openOnClick: false,
            HTMLAttributes: { rel: 'noopener noreferrer', target: '_blank' },
        }),
        Placeholder.configure({ placeholder: props.placeholder }),
    ],
    onUpdate: ({ editor }) => {
        emit('update:modelValue', editor.getHTML());
    },
});

// Keep the editor in sync if the parent overwrites modelValue
// (e.g. switching from one article to another in the same slide-over).
watch(() => props.modelValue, (val) => {
    if (! editor.value) return;
    if (val !== editor.value.getHTML()) {
        editor.value.commands.setContent(val || '', false);
    }
});

onBeforeUnmount(() => editor.value?.destroy());

function insertImage() {
    const url = window.prompt('Image URL:');
    if (url) editor.value?.chain().focus().setImage({ src: url }).run();
}

function insertLink() {
    const current = editor.value?.getAttributes('link')?.href ?? '';
    const url = window.prompt('URL:', current);
    if (url === null) return; // cancelled
    if (url === '') {
        editor.value?.chain().focus().extendMarkRange('link').unsetLink().run();
        return;
    }
    editor.value?.chain().focus().extendMarkRange('link').setLink({ href: url }).run();
}

function isActive(name, attrs) {
    return editor.value?.isActive(name, attrs) ?? false;
}
</script>

<template>
    <div class="rte-wrapper">
        <!-- Toolbar -->
        <div class="rte-toolbar" v-if="editor">
            <button type="button" class="rte-btn" :class="{ active: isActive('bold') }" title="Bold" @click="editor.chain().focus().toggleBold().run()">
                <IconBold :size="15" stroke-width="2" />
            </button>
            <button type="button" class="rte-btn" :class="{ active: isActive('italic') }" title="Italic" @click="editor.chain().focus().toggleItalic().run()">
                <IconItalic :size="15" stroke-width="2" />
            </button>
            <span class="rte-divider" />
            <button type="button" class="rte-btn" :class="{ active: isActive('heading', { level: 2 }) }" title="Heading 2" @click="editor.chain().focus().toggleHeading({ level: 2 }).run()">
                <IconHeading :size="15" stroke-width="2" />
            </button>
            <button type="button" class="rte-btn" :class="{ active: isActive('bulletList') }" title="Bullet list" @click="editor.chain().focus().toggleBulletList().run()">
                <IconList :size="15" stroke-width="2" />
            </button>
            <button type="button" class="rte-btn" :class="{ active: isActive('orderedList') }" title="Numbered list" @click="editor.chain().focus().toggleOrderedList().run()">
                <IconListNumbers :size="15" stroke-width="2" />
            </button>
            <span class="rte-divider" />
            <button type="button" class="rte-btn" :class="{ active: isActive('blockquote') }" title="Quote" @click="editor.chain().focus().toggleBlockquote().run()">
                <IconQuote :size="15" stroke-width="2" />
            </button>
            <button type="button" class="rte-btn" :class="{ active: isActive('code') }" title="Inline code" @click="editor.chain().focus().toggleCode().run()">
                <IconCode :size="15" stroke-width="2" />
            </button>
            <button type="button" class="rte-btn" :class="{ active: isActive('codeBlock') }" title="Code block" @click="editor.chain().focus().toggleCodeBlock().run()">
                <IconTerminal2 :size="15" stroke-width="2" />
            </button>
            <span class="rte-divider" />
            <button type="button" class="rte-btn" title="Insert image" @click="insertImage">
                <IconPhoto :size="15" stroke-width="2" />
            </button>
            <button type="button" class="rte-btn" :class="{ active: isActive('link') }" title="Insert / edit link" @click="insertLink">
                <IconLink :size="15" stroke-width="2" />
            </button>
            <span class="rte-divider" />
            <button type="button" class="rte-btn" title="Undo" :disabled="! editor.can().undo()" @click="editor.chain().focus().undo().run()">
                <IconArrowBackUp :size="15" stroke-width="2" />
            </button>
            <button type="button" class="rte-btn" title="Redo" :disabled="! editor.can().redo()" @click="editor.chain().focus().redo().run()">
                <IconArrowForwardUp :size="15" stroke-width="2" />
            </button>
        </div>

        <EditorContent :editor="editor" class="rte-content" />
    </div>
</template>
