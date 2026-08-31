<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { ArrowLeft, Check, UserRound } from 'lucide-vue-next';
import { computed } from 'vue';
import { formatTime } from '@/lib/format';

type Person = { id: number; name: string; username: string | null };
type Todo = {
    id: number;
    title: string;
    bucket: string;
    status: string;
    due_date: string | null;
    due_time: string | null;
    user_id: number;
    assigned_by_id: number | null;
    user?: Person | null;
    assigned_by?: Person | null;
};

const props = defineProps<{ todos: Todo[]; days: string[] }>();

const toggle = (t: Todo) =>
    router.patch(`/todos/${t.id}/toggle`, {}, { preserveScroll: true });

/** Only the days that actually hold something — an empty week reads better. */
const populatedDays = computed(() =>
    props.days
        .map((date) => ({
            date,
            label: new Date(date).toLocaleDateString('en-GB', {
                weekday: 'long',
                day: 'numeric',
                month: 'short',
            }),
            isToday: date === new Date().toLocaleDateString('sv-SE'),
            todos: props.todos.filter((t) => t.due_date?.slice(0, 10) === date),
        }))
        .filter((d) => d.todos.length),
);

/** Whose task: null when it is mine, otherwise the teammate it went to. */
function assignedTo(t: Todo): Person | null {
    return t.assigned_by_id && t.user && t.assigned_by_id !== t.user_id ? t.user : null;
}
</script>

<template>
    <Head title="This week" />

    <Link href="/todos" class="mb-2 flex items-center gap-1 text-xs text-muted-foreground">
        <ArrowLeft class="h-3.5 w-3.5" /> Calendar
    </Link>

    <h1 class="text-2xl font-bold text-gradient-brand">This week</h1>
    <p class="mt-1 text-sm text-muted-foreground">
        Your next seven days, plus the work you assigned to your team.
    </p>

    <div
        v-if="!populatedDays.length"
        class="mt-6 rounded-xl border border-dashed border-border p-8 text-center text-sm text-muted-foreground"
    >
        Nothing scheduled this week 🎉
    </div>

    <section v-for="day in populatedDays" :key="day.date" class="mt-6">
        <h2 class="flex items-center gap-2 text-sm font-medium">
            <span :class="day.isToday ? 'text-primary' : 'text-muted-foreground'">
                {{ day.label }}
            </span>
            <span
                v-if="day.isToday"
                class="rounded-full bg-gradient-brand px-2 py-0.5 text-[10px] font-semibold text-white"
            >
                Today
            </span>
        </h2>

        <ul class="mt-2 space-y-2">
            <li
                v-for="t in day.todos"
                :key="t.id"
                class="flex items-center gap-3 rounded-xl border border-border bg-card p-3"
            >
                <button
                    class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg border border-border text-muted-foreground"
                    @click="toggle(t)"
                >
                    <Check class="h-4 w-4" />
                </button>
                <Link :href="`/todos/${t.id}`" class="min-w-0 flex-1">
                    <p class="truncate text-sm font-medium">{{ t.title }}</p>
                    <p class="mt-0.5 flex flex-wrap items-center gap-1 text-xs text-muted-foreground">
                        <span v-if="t.due_time">{{ formatTime(t.due_time) }}</span>
                        <span
                            v-if="assignedTo(t)"
                            class="inline-flex items-center gap-1 rounded-full bg-blue-500/15 px-1.5 py-0.5 font-medium text-blue-600 dark:text-blue-400"
                        >
                            <UserRound class="h-3 w-3" />{{ assignedTo(t)?.name }}
                        </span>
                        <span
                            v-else-if="t.assigned_by"
                            class="rounded-full bg-primary/15 px-1.5 py-0.5 font-medium text-primary"
                        >
                            from {{ t.assigned_by.name }}
                        </span>
                    </p>
                </Link>
            </li>
        </ul>
    </section>
</template>
