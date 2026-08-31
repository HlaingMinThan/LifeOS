<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { ArrowLeft, Check, Trash2 } from 'lucide-vue-next';
import { formatDate, formatTime } from '@/lib/format';

type Todo = {
    id: number;
    title: string;
    status: string;
    bucket: string;
    due_date: string | null;
    due_time: string | null;
};

defineProps<{
    member: { id: number; name: string; username: string | null; email: string };
    todos: Todo[];
    openCount: number;
    doneCount: number;
}>();

const toggle = (t: Todo) =>
    router.patch(`/todos/${t.id}/toggle`, {}, { preserveScroll: true });
const remove = (t: Todo) => {
    if (confirm('Withdraw this task?')) {
        router.delete(`/todos/${t.id}`, { preserveScroll: true });
    }
};
</script>

<template>
    <Head :title="member.name" />

    <Link href="/settings/team" class="mb-2 flex items-center gap-1 text-xs text-muted-foreground">
        <ArrowLeft class="h-3.5 w-3.5" /> Team
    </Link>

    <div class="flex items-center gap-3">
        <div
            class="flex h-12 w-12 shrink-0 items-center justify-center rounded-full bg-gradient-brand text-sm font-bold text-white"
        >
            {{ member.name.slice(0, 2).toUpperCase() }}
        </div>
        <div class="min-w-0">
            <h1 class="truncate text-xl font-bold text-gradient-brand">{{ member.name }}</h1>
            <p class="truncate text-xs text-muted-foreground">
                <span v-if="member.username">@{{ member.username }} · </span>{{ member.email }}
            </p>
        </div>
    </div>

    <div class="mt-4 grid grid-cols-2 gap-2">
        <div class="rounded-xl border border-border bg-card p-3 text-center">
            <p class="text-xs text-muted-foreground">Open</p>
            <p class="mt-1 text-base font-bold tabular-nums text-primary">{{ openCount }}</p>
        </div>
        <div class="rounded-xl border border-border bg-card p-3 text-center">
            <p class="text-xs text-muted-foreground">Done</p>
            <p class="mt-1 text-base font-bold tabular-nums text-green-500">{{ doneCount }}</p>
        </div>
    </div>

    <p class="mt-4 text-xs text-muted-foreground">
        Only the tasks you assigned. The rest of their Life OS stays private.
    </p>

    <div
        v-if="!todos.length"
        class="mt-4 rounded-xl border border-dashed border-border p-6 text-center text-sm text-muted-foreground"
    >
        Nothing assigned yet — try
        <span class="text-foreground">@{{ member.username }} send the report on Monday 12pm</span>
        in the magic box.
    </div>

    <ul v-else class="mt-4 space-y-2">
        <li
            v-for="t in todos"
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
            <Link :href="`/todos/${t.id}`" class="min-w-0 flex-1">
                <p class="truncate text-sm font-medium" :class="{ 'line-through': t.status === 'done' }">
                    {{ t.title }}
                </p>
                <p v-if="t.due_date || t.due_time" class="text-xs text-muted-foreground">
                    <template v-if="t.due_date">{{ formatDate(t.due_date) }}</template>
                    <template v-if="t.due_time"> · {{ formatTime(t.due_time) }}</template>
                </p>
            </Link>
            <button class="shrink-0 p-2 text-muted-foreground/60" @click="remove(t)">
                <Trash2 class="h-4 w-4" />
            </button>
        </li>
    </ul>
</template>
