<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import {
    ArrowLeft,
    ChevronLeft,
    ChevronRight,
    ImageIcon,
    TrendingDown,
    TrendingUp,
} from 'lucide-vue-next';
import { computed, ref } from 'vue';
import { formatDate } from '@/lib/format';

type Entry = {
    id: number;
    title: string;
    amount_mmk: number;
    date: string | null;
    note: string | null;
    contact: string | null;
    image: string | null;
};

const props = defineProps<{
    detail: {
        category: string;
        month: string;
        label: string;
        total: number;
        count: number;
        average: number;
        biggest: number;
        share: number;
        previous: { total: number; label: string };
        change: number | null;
        trend: { month: string; label: string; total: number }[];
        entries: Entry[];
    };
}>();

const sort = ref<'amount' | 'date'>('amount');

const entries = computed(() =>
    sort.value === 'amount'
        ? props.detail.entries
        : [...props.detail.entries].sort((a, b) => (b.date ?? '').localeCompare(a.date ?? '')),
);

// Bars are relative to the largest month in view, not to an absolute scale —
// the shape of the trend is what matters here.
const trendPeak = computed(() => Math.max(...props.detail.trend.map((t) => t.total), 1));

function shiftMonth(delta: number) {
    const [y, m] = props.detail.month.split('-').map(Number);
    const d = new Date(y, m - 1 + delta, 1);
    router.get('/money/category', {
        name: props.detail.category,
        month: `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`,
    }, { preserveState: false });
}

function openMonth(month: string) {
    if (month === props.detail.month) return;
    router.get('/money/category', { name: props.detail.category, month }, { preserveState: false });
}
</script>

<template>
    <Head :title="detail.category" />

    <div>
        <Link
            :href="`/money/review?month=${detail.month}`"
            class="mb-2 flex items-center gap-1 text-xs text-muted-foreground"
        >
            <ArrowLeft class="h-3.5 w-3.5" /> Review
        </Link>
        <h1 class="text-2xl font-bold text-gradient-brand">{{ detail.category }}</h1>
        <p class="mt-1 text-sm text-muted-foreground">
            {{ detail.share }}% of what you spent in {{ detail.label }}.
        </p>
    </div>

    <!-- Month navigation -->
    <div class="mt-4 flex items-center justify-between">
        <button
            class="flex h-8 w-8 items-center justify-center rounded-lg border border-border text-muted-foreground"
            @click="shiftMonth(-1)"
        >
            <ChevronLeft class="h-4 w-4" />
        </button>
        <span class="text-sm font-semibold">{{ detail.label }}</span>
        <button
            class="flex h-8 w-8 items-center justify-center rounded-lg border border-border text-muted-foreground"
            @click="shiftMonth(1)"
        >
            <ChevronRight class="h-4 w-4" />
        </button>
    </div>

    <!-- Headline -->
    <div class="mt-3 rounded-xl border border-border bg-card p-4 text-center">
        <p class="text-xs text-muted-foreground">Total</p>
        <p class="mt-1 text-3xl font-bold tabular-nums text-rose-400">
            {{ detail.total.toLocaleString() }}
        </p>
        <p
            v-if="detail.change !== null"
            class="mt-1 flex items-center justify-center gap-1 text-xs font-medium"
            :class="detail.change > 0 ? 'text-rose-400' : 'text-green-500'"
        >
            <component :is="detail.change > 0 ? TrendingUp : TrendingDown" class="h-3.5 w-3.5" />
            {{ Math.abs(detail.change) }}% {{ detail.change > 0 ? 'more' : 'less' }} than
            {{ detail.previous.label }}
        </p>
        <p v-else-if="detail.previous.total === 0 && detail.total > 0" class="mt-1 text-xs text-muted-foreground">
            Nothing here in {{ detail.previous.label }}.
        </p>
    </div>

    <div class="mt-2 grid grid-cols-3 gap-2">
        <div class="rounded-xl border border-border bg-card p-3 text-center">
            <p class="text-xs text-muted-foreground">Entries</p>
            <p class="mt-1 text-base font-bold tabular-nums">{{ detail.count }}</p>
        </div>
        <div class="rounded-xl border border-border bg-card p-3 text-center">
            <p class="text-xs text-muted-foreground">Average</p>
            <p class="mt-1 text-base font-bold tabular-nums">
                {{ detail.average.toLocaleString() }}
            </p>
        </div>
        <div class="rounded-xl border border-border bg-card p-3 text-center">
            <p class="text-xs text-muted-foreground">Biggest</p>
            <p class="mt-1 text-base font-bold tabular-nums">
                {{ detail.biggest.toLocaleString() }}
            </p>
        </div>
    </div>

    <!-- 6-month trend -->
    <section class="mt-6">
        <h2 class="text-sm font-medium text-muted-foreground">Last 6 months</h2>
        <div class="mt-2 rounded-xl border border-border bg-card p-3">
            <div class="flex h-28 items-end gap-1.5">
                <button
                    v-for="t in detail.trend"
                    :key="t.month"
                    class="flex flex-1 flex-col items-center gap-1"
                    @click="openMonth(t.month)"
                >
                    <span class="text-[9px] tabular-nums text-muted-foreground">
                        {{ t.total ? Math.round(t.total / 1000) + 'k' : '' }}
                    </span>
                    <div
                        class="w-full rounded-t transition-colors"
                        :class="
                            t.month === detail.month
                                ? 'bg-gradient-brand'
                                : 'bg-primary/25 hover:bg-primary/40'
                        "
                        :style="{ height: `${Math.max((t.total / trendPeak) * 72, 2)}px` }"
                    ></div>
                    <span
                        class="text-[10px]"
                        :class="t.month === detail.month ? 'font-bold text-primary' : 'text-muted-foreground'"
                    >
                        {{ t.label }}
                    </span>
                </button>
            </div>
        </div>
    </section>

    <!-- Transactions -->
    <section class="mt-6 mb-2">
        <div class="flex items-center justify-between">
            <h2 class="text-sm font-medium text-muted-foreground">
                {{ detail.count }} {{ detail.count === 1 ? 'transaction' : 'transactions' }}
            </h2>
            <div v-if="detail.entries.length > 1" class="flex rounded-lg border border-border p-0.5 text-xs">
                <button
                    class="rounded-md px-2 py-1"
                    :class="sort === 'amount' ? 'bg-primary/10 font-medium text-primary' : 'text-muted-foreground'"
                    @click="sort = 'amount'"
                >
                    Biggest
                </button>
                <button
                    class="rounded-md px-2 py-1"
                    :class="sort === 'date' ? 'bg-primary/10 font-medium text-primary' : 'text-muted-foreground'"
                    @click="sort = 'date'"
                >
                    Newest
                </button>
            </div>
        </div>

        <p
            v-if="!entries.length"
            class="mt-2 rounded-xl border border-dashed border-border p-8 text-center text-sm text-muted-foreground"
        >
            Nothing in this category for {{ detail.label }}.
        </p>

        <ul v-else class="mt-2 space-y-2">
            <li
                v-for="e in entries"
                :key="e.id"
                class="rounded-xl border border-border bg-card p-3"
            >
                <div class="flex items-baseline justify-between gap-2">
                    <p class="min-w-0 truncate text-sm font-medium">{{ e.title }}</p>
                    <p class="shrink-0 text-sm font-bold tabular-nums text-rose-400">
                        {{ e.amount_mmk.toLocaleString() }}
                    </p>
                </div>
                <p class="mt-0.5 flex items-center gap-1.5 text-xs text-muted-foreground">
                    <span v-if="e.date">{{ formatDate(e.date) }}</span>
                    <span v-if="e.contact && e.contact !== e.title">· {{ e.contact }}</span>
                    <ImageIcon v-if="e.image" class="h-3 w-3" />
                    <span v-if="detail.total">
                        · {{ Math.round((e.amount_mmk / detail.total) * 100) }}%
                    </span>
                </p>
                <p v-if="e.note" class="mt-0.5 truncate text-xs text-muted-foreground/70">
                    {{ e.note }}
                </p>
            </li>
        </ul>
    </section>
</template>
