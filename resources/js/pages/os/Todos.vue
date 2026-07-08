<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { ChevronLeft, ChevronRight, FileQuestion } from 'lucide-vue-next';
import { computed } from 'vue';

type DayCount = { open: number; done: number };

const props = defineProps<{
    month: string; // "2026-07"
    counts: Record<string, DayCount>;
    undatedCount: number;
}>();

const WEEKDAYS = ['Su', 'Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa'];

const year = computed(() => Number(props.month.slice(0, 4)));
const monthIndex = computed(() => Number(props.month.slice(5, 7)) - 1);

const monthLabel = computed(() =>
    new Date(year.value, monthIndex.value, 1).toLocaleDateString('en-GB', {
        month: 'long',
        year: 'numeric',
    }),
);

const daysInMonth = computed(() => new Date(year.value, monthIndex.value + 1, 0).getDate());
const leadingBlanks = computed(() => new Date(year.value, monthIndex.value, 1).getDay());
const todayIso = new Date().toLocaleDateString('sv-SE'); // YYYY-MM-DD, local tz

function iso(day: number): string {
    return `${props.month}-${String(day).padStart(2, '0')}`;
}

function shiftMonth(delta: number) {
    const d = new Date(year.value, monthIndex.value + delta, 1);
    const target = `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`;
    router.get('/todos', { month: target }, { preserveState: false });
}

const openDay = (day: number) => router.visit(`/todos/day/${iso(day)}`);
</script>

<template>
    <Head title="Todos" />

    <h1 class="text-2xl font-bold text-gradient-brand">Todos</h1>
    <p class="mt-1 text-sm text-muted-foreground">Tap a day to see or add its todos.</p>

    <!-- Month navigation -->
    <div class="mt-4 flex items-center justify-between">
        <button
            class="flex h-8 w-8 items-center justify-center rounded-lg border border-border text-muted-foreground"
            @click="shiftMonth(-1)"
        >
            <ChevronLeft class="h-4 w-4" />
        </button>
        <span class="text-sm font-semibold">{{ monthLabel }}</span>
        <button
            class="flex h-8 w-8 items-center justify-center rounded-lg border border-border text-muted-foreground"
            @click="shiftMonth(1)"
        >
            <ChevronRight class="h-4 w-4" />
        </button>
    </div>

    <!-- Calendar grid -->
    <div class="mt-3 rounded-xl border border-border bg-card p-3">
        <div class="grid grid-cols-7 text-center text-xs font-medium text-muted-foreground">
            <span v-for="d in WEEKDAYS" :key="d" class="py-1">{{ d }}</span>
        </div>
        <div class="mt-1 grid grid-cols-7 gap-1">
            <span v-for="i in leadingBlanks" :key="`blank-${i}`"></span>
            <button
                v-for="day in daysInMonth"
                :key="day"
                class="relative flex aspect-square flex-col items-center justify-center rounded-lg text-sm transition-colors"
                :class="[
                    iso(day) === todayIso
                        ? 'bg-gradient-brand font-bold text-white shadow-md shadow-fuchsia-500/20'
                        : counts[iso(day)]
                          ? 'bg-primary/10 font-medium text-foreground'
                          : 'text-muted-foreground hover:bg-muted',
                ]"
                @click="openDay(day)"
            >
                {{ day }}
                <span
                    v-if="counts[iso(day)]"
                    class="absolute bottom-0.5 flex items-center gap-0.5 text-[9px] leading-none"
                >
                    <span
                        v-if="counts[iso(day)].open"
                        class="rounded-full px-1 font-semibold"
                        :class="iso(day) === todayIso ? 'bg-white/25 text-white' : 'bg-gradient-brand text-white'"
                    >
                        {{ counts[iso(day)].open }}
                    </span>
                    <span
                        v-else
                        :class="iso(day) === todayIso ? 'text-white' : 'text-green-500'"
                    >✓</span>
                </span>
            </button>
        </div>
    </div>

    <!-- Undated bucket -->
    <Link
        href="/todos/day/undated"
        class="mt-4 flex items-center gap-3 rounded-xl border border-border bg-card p-3"
    >
        <FileQuestion class="h-4 w-4 shrink-0 text-muted-foreground" />
        <span class="flex-1 text-sm font-medium">No date</span>
        <span
            v-if="undatedCount"
            class="rounded-full bg-gradient-brand px-2 py-0.5 text-xs font-semibold text-white"
        >
            {{ undatedCount }}
        </span>
        <span v-else class="text-xs text-muted-foreground">empty</span>
    </Link>
</template>
