<script setup lang="ts">
import StarterKit from '@tiptap/starter-kit';
import { Editor, EditorContent } from '@tiptap/vue-3';
import {
    Bold,
    Heading2,
    Italic,
    List,
    ListOrdered,
    Strikethrough,
} from 'lucide-vue-next';
import { onBeforeUnmount, watch } from 'vue';

const model = defineModel<string | null>();

const editor = new Editor({
    content: model.value ?? '',
    extensions: [StarterKit],
    editorProps: {
        attributes: {
            class: 'prose-editor min-h-[8rem] w-full rounded-b-lg bg-background px-3 py-2 text-sm outline-none',
        },
    },
    onUpdate: ({ editor }) => {
        const html = editor.getHTML();
        model.value = html === '<p></p>' ? null : html;
    },
});

// Keep editor in sync if the parent resets the value (e.g. after save).
watch(model, (value) => {
    if ((value ?? '') !== editor.getHTML()) {
        editor.commands.setContent(value ?? '', { emitUpdate: false });
    }
});

onBeforeUnmount(() => editor.destroy());

const tools = [
    { icon: Bold, action: () => editor.chain().focus().toggleBold().run(), name: 'bold' },
    { icon: Italic, action: () => editor.chain().focus().toggleItalic().run(), name: 'italic' },
    { icon: Strikethrough, action: () => editor.chain().focus().toggleStrike().run(), name: 'strike' },
    { icon: Heading2, action: () => editor.chain().focus().toggleHeading({ level: 2 }).run(), name: 'heading', attrs: { level: 2 } },
    { icon: List, action: () => editor.chain().focus().toggleBulletList().run(), name: 'bulletList' },
    { icon: ListOrdered, action: () => editor.chain().focus().toggleOrderedList().run(), name: 'orderedList' },
];
</script>

<template>
    <div class="rounded-lg border border-input">
        <div class="flex flex-wrap gap-1 border-b border-input p-1">
            <button
                v-for="tool in tools"
                :key="tool.name"
                type="button"
                class="flex h-8 w-8 items-center justify-center rounded-md transition-colors"
                :class="
                    editor.isActive(tool.name, tool.attrs)
                        ? 'bg-primary/15 text-primary'
                        : 'text-muted-foreground hover:bg-muted'
                "
                @click="tool.action"
            >
                <component :is="tool.icon" class="h-4 w-4" />
            </button>
        </div>
        <EditorContent :editor="editor" />
    </div>
</template>

<style>
.prose-editor p {
    margin: 0.25rem 0;
}
.prose-editor h2 {
    margin: 0.5rem 0 0.25rem;
    font-size: 1.05rem;
    font-weight: 700;
}
.prose-editor ul {
    margin: 0.25rem 0;
    padding-left: 1.25rem;
    list-style: disc;
}
.prose-editor ol {
    margin: 0.25rem 0;
    padding-left: 1.25rem;
    list-style: decimal;
}
.prose-editor:focus {
    outline: none;
}
</style>
