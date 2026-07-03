<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { Heart } from 'lucide-vue-next';

type CareTask = {
    id: number;
    title: string;
    schedule_type: string;
    next_run_at: string | null;
    active: boolean;
};

defineProps<{ tasks: CareTask[] }>();

const SCHEDULE_LABELS: Record<string, string> = {
    daily: 'Daily',
    weekly: 'Weekly',
    random: 'Surprise 🎲',
};

function nextRun(task: CareTask): string {
    if (!task.next_run_at) return '';
    return new Date(task.next_run_at).toLocaleDateString('en-GB', {
        weekday: 'short',
        day: 'numeric',
        month: 'short',
    });
}
</script>

<template>
    <Head title="Care" />

    <h1 class="text-2xl font-semibold">Care</h1>
    <p class="mt-1 text-sm text-muted-foreground">
        Recurring care tasks and surprises.
    </p>

    <div
        v-if="!tasks.length"
        class="mt-6 rounded-xl border border-dashed border-border p-8 text-center text-sm text-muted-foreground"
    >
        Nothing yet — "သောကြာနေ့ ပန်းစည်း ပို့ရန်" in the magic box, or wait for
        Day 3's Telegram engine.
    </div>

    <ul v-else class="mt-6 space-y-2">
        <li
            v-for="t in tasks"
            :key="t.id"
            class="flex items-center gap-3 rounded-xl border border-border bg-card p-3"
            :class="{ 'opacity-50': !t.active }"
        >
            <Heart class="h-4 w-4 shrink-0 text-rose-400" />
            <div class="min-w-0 flex-1">
                <p class="truncate text-sm font-medium">{{ t.title }}</p>
                <p class="text-xs text-muted-foreground">
                    {{ SCHEDULE_LABELS[t.schedule_type] ?? t.schedule_type }}
                    <span v-if="t.next_run_at"> · next {{ nextRun(t) }}</span>
                </p>
            </div>
        </li>
    </ul>
</template>
