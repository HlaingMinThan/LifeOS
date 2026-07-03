<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { Check, Trash2 } from 'lucide-vue-next';
import { formatDate } from '@/lib/format';

type Todo = {
    id: number;
    title: string;
    bucket: string;
    status: string;
    due_date: string | null;
};

const props = defineProps<{ todos: Todo[] }>();

const BUCKETS: Record<string, string> = {
    work: 'Work',
    personal: 'Personal',
    money_task: 'Money',
};

const toggle = (t: Todo) =>
    router.patch(`/todos/${t.id}/toggle`, {}, { preserveScroll: true });
const remove = (t: Todo) =>
    router.delete(`/todos/${t.id}`, { preserveScroll: true });

const inBucket = (bucket: string) => props.todos.filter((t) => t.bucket === bucket);
</script>

<template>
    <Head title="Todos" />

    <h1 class="text-2xl font-semibold">Todos</h1>
    <p class="mt-1 text-sm text-muted-foreground">Work · Personal · Money tasks.</p>

    <div
        v-if="!todos.length"
        class="mt-6 rounded-xl border border-dashed border-border p-8 text-center text-sm text-muted-foreground"
    >
        Nothing yet — add one from the Home magic box.
    </div>

    <template v-for="(label, bucket) in BUCKETS" :key="bucket">
        <section v-if="inBucket(bucket).length" class="mt-6">
            <h2 class="text-sm font-medium text-muted-foreground">{{ label }}</h2>
            <ul class="mt-2 space-y-2">
                <li
                    v-for="t in inBucket(bucket)"
                    :key="t.id"
                    class="flex items-center gap-3 rounded-xl border border-border bg-card p-3"
                    :class="{ 'opacity-50': t.status === 'done' }"
                >
                    <button
                        class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg border"
                        :class="
                            t.status === 'done'
                                ? 'border-green-500/40 bg-green-500/10 text-green-500'
                                : 'border-border text-muted-foreground'
                        "
                        @click="toggle(t)"
                    >
                        <Check class="h-4 w-4" />
                    </button>
                    <div class="min-w-0 flex-1">
                        <p class="truncate text-sm font-medium" :class="{ 'line-through': t.status === 'done' }">
                            {{ t.title }}
                        </p>
                        <p v-if="t.due_date" class="text-xs text-muted-foreground">
                            Due {{ formatDate(t.due_date) }}
                        </p>
                    </div>
                    <button class="shrink-0 p-2 text-muted-foreground/60" @click="remove(t)">
                        <Trash2 class="h-4 w-4" />
                    </button>
                </li>
            </ul>
        </section>
    </template>
</template>
