<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { Lightbulb, Trash2 } from 'lucide-vue-next';

type Idea = { id: number; title: string; note: string | null; status: string };

defineProps<{ ideas: Idea[] }>();

const remove = (i: Idea) =>
    router.delete(`/ideas/${i.id}`, { preserveScroll: true });
</script>

<template>
    <Head title="Ideas" />

    <h1 class="text-2xl font-semibold">Ideas</h1>
    <p class="mt-1 text-sm text-muted-foreground">The parking lot.</p>

    <div
        v-if="!ideas.length"
        class="mt-6 rounded-xl border border-dashed border-border p-8 text-center text-sm text-muted-foreground"
    >
        Park ideas from the magic box — "mushroom idea မှတ်ထား".
    </div>

    <ul v-else class="mt-6 space-y-2">
        <li
            v-for="i in ideas"
            :key="i.id"
            class="flex items-center gap-3 rounded-xl border border-border bg-card p-3"
        >
            <Lightbulb class="h-4 w-4 shrink-0 text-amber-400" />
            <div class="min-w-0 flex-1">
                <p class="truncate text-sm font-medium">{{ i.title }}</p>
                <p v-if="i.note" class="truncate text-xs text-muted-foreground">{{ i.note }}</p>
            </div>
            <span class="shrink-0 rounded-full border border-border px-2 py-0.5 text-xs text-muted-foreground">
                {{ i.status }}
            </span>
            <button class="shrink-0 p-2 text-muted-foreground/60" @click="remove(i)">
                <Trash2 class="h-4 w-4" />
            </button>
        </li>
    </ul>
</template>
