<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Check, Loader2, RotateCcw, Send, UserRound, X } from 'lucide-vue-next';
import { ref } from 'vue';
import { apiPost } from '@/lib/api';
import { formatDate, formatMmk, formatTime } from '@/lib/format';

type LedgerEntry = {
    id: number;
    title: string;
    amount_mmk: number;
    due_date: string | null;
    contact?: { name: string } | null;
};
type Todo = { id: number; title: string; bucket: string; due_date: string | null };
type CareTask = { id: number; title: string };
type Parsed = {
    action: string;
    target: string | null;
    amount_mmk: number | null;
    due: string | null;
    due_time: string | null;
    bucket: string | null;
    confidence: number;
};

defineProps<{
    payables: LedgerEntry[];
    receivables: LedgerEntry[];
    today: Todo[];
    careToday: CareTask[];
    overdue: Todo[];
}>();

const ACTION_LABELS: Record<string, string> = {
    mark_paid: 'Mark paid',
    add_payable: 'Expense',
    add_receivable: 'Income',
    income_received: 'Income received',
    add_todo: 'New todo',
    complete_todo: 'Complete todo',
    add_care_task: 'New care task',
    add_idea: 'Park idea',
    show_day: 'Day lookup (ask in Telegram)',
    unknown: 'Not sure…',
};

// Actions the user can pick when correcting a parse (everything but unknown).
const ACTION_OPTIONS = Object.entries(ACTION_LABELS).filter(([k]) => k !== 'unknown');
const BUCKET_ACTIONS = ['add_todo', 'complete_todo'];
const AMOUNT_ACTIONS = ['mark_paid', 'add_payable', 'add_receivable', 'income_received'];

const text = ref('');
const state = ref<'idle' | 'parsing' | 'confirm' | 'applying' | 'applied'>('idle');
const parsed = ref<Parsed | null>(null);
const originalParsed = ref('');
const rawText = ref('');
const lastEventId = ref<number | null>(null);
const error = ref('');

async function parse() {
    if (!text.value.trim()) return;
    error.value = '';
    state.value = 'parsing';
    try {
        const res = await apiPost<{ raw_text: string; parsed: Parsed }>('/inbox/parse', {
            text: text.value,
        });
        parsed.value = res.parsed;
        originalParsed.value = JSON.stringify(res.parsed);
        rawText.value = res.raw_text;
        state.value = 'confirm';
    } catch (e) {
        error.value = (e as Error).message;
        state.value = 'idle';
    }
}

async function apply() {
    state.value = 'applying';
    error.value = '';
    try {
        const res = await apiPost<{ event_id: number }>('/inbox/apply', {
            raw_text: rawText.value,
            parsed: parsed.value,
            // A changed parse is a correction — the parser learns from it.
            corrected: JSON.stringify(parsed.value) !== originalParsed.value,
        });
        lastEventId.value = res.event_id;
        state.value = 'applied';
        text.value = '';
        router.reload();
    } catch (e) {
        error.value = (e as Error).message;
        state.value = 'confirm';
    }
}

async function undo() {
    if (!lastEventId.value) return;
    try {
        await apiPost(`/inbox/undo/${lastEventId.value}`, {});
        dismiss();
        router.reload();
    } catch (e) {
        error.value = (e as Error).message;
    }
}

function dismiss() {
    state.value = 'idle';
    parsed.value = null;
    lastEventId.value = null;
}
</script>

<template>
    <Head title="Home" />

    <div class="flex items-start justify-between">
        <div>
            <h1 class="text-2xl font-bold text-gradient-brand">Catch up</h1>
            <p class="mt-1 text-sm text-muted-foreground">
                Everything that needs you, on one screen.
            </p>
        </div>
        <div class="mt-1 flex shrink-0 items-center gap-2">
            <Link
                href="/onboard"
                class="rounded-full border border-border px-3 py-1 text-xs text-muted-foreground"
            >
                Import
            </Link>
            <Link
                href="/profile"
                class="flex h-8 w-8 items-center justify-center rounded-full bg-gradient-brand text-white shadow-md shadow-fuchsia-500/20"
            >
                <UserRound class="h-4 w-4" />
            </Link>
        </div>
    </div>

    <form class="mt-4 flex gap-2" @submit.prevent="parse">
        <input
            v-model="text"
            type="text"
            placeholder="paid gon khaung 500k…"
            class="h-12 flex-1 rounded-xl border border-input bg-card px-4 text-base shadow-sm outline-none placeholder:text-muted-foreground focus:ring-2 focus:ring-ring"
            :disabled="state === 'parsing' || state === 'applying'"
        />
        <button
            type="submit"
            class="flex h-12 w-12 items-center justify-center rounded-xl bg-gradient-brand text-white shadow-md shadow-fuchsia-500/20 transition-transform active:scale-95 disabled:opacity-50"
            :disabled="!text.trim() || state === 'parsing'"
        >
            <Loader2 v-if="state === 'parsing'" class="h-5 w-5 animate-spin" />
            <Send v-else class="h-5 w-5" />
        </button>
    </form>

    <p v-if="error" class="mt-2 text-sm text-red-500">{{ error }}</p>

    <!-- Confirm chip: editable — nothing is written until this is accepted -->
    <Transition name="form">
    <div
        v-if="(state === 'confirm' || state === 'applying') && parsed"
        class="mt-3 rounded-xl border border-primary/40 bg-primary/5 p-4"
    >
        <div class="flex items-start justify-between gap-3">
            <div class="min-w-0 flex-1 space-y-2">
                <select
                    v-model="parsed.action"
                    class="w-full rounded-lg border border-input bg-card px-2 py-1.5 text-sm font-medium text-primary outline-none focus:ring-2 focus:ring-ring"
                >
                    <option v-if="parsed.action === 'unknown'" value="unknown" disabled>
                        Not sure — pick an action…
                    </option>
                    <option v-for="[value, label] in ACTION_OPTIONS" :key="value" :value="value">
                        {{ label }}
                    </option>
                </select>
                <input
                    v-model="parsed.target"
                    type="text"
                    placeholder="Title / who"
                    class="w-full rounded-lg border border-input bg-card px-2 py-1.5 text-sm outline-none focus:ring-2 focus:ring-ring"
                />
                <div class="flex gap-2">
                    <input
                        v-if="AMOUNT_ACTIONS.includes(parsed.action)"
                        v-model.number="parsed.amount_mmk"
                        type="number"
                        placeholder="Amount (Ks)"
                        class="w-full rounded-lg border border-input bg-card px-2 py-1.5 text-sm outline-none focus:ring-2 focus:ring-ring"
                    />
                    <select
                        v-if="BUCKET_ACTIONS.includes(parsed.action)"
                        v-model="parsed.bucket"
                        class="w-full rounded-lg border border-input bg-card px-2 py-1.5 text-sm outline-none focus:ring-2 focus:ring-ring"
                    >
                        <option :value="null">Bucket…</option>
                        <option value="work">Work</option>
                        <option value="personal">Personal</option>
                        <option value="money_task">Money</option>
                    </select>
                </div>
                <p v-if="parsed.amount_mmk && AMOUNT_ACTIONS.includes(parsed.action)" class="text-xs text-muted-foreground">
                    = {{ formatMmk(parsed.amount_mmk) }}
                </p>
                <p v-if="parsed.due || parsed.due_time" class="text-xs text-muted-foreground">
                    Due
                    <template v-if="parsed.due">{{ formatDate(parsed.due) }}</template>
                    <template v-if="parsed.due_time"> ⏰ {{ formatTime(parsed.due_time) }}</template>
                </p>
            </div>
            <div class="flex shrink-0 flex-col gap-2">
                <button
                    class="flex h-10 w-10 items-center justify-center rounded-lg bg-primary text-primary-foreground disabled:opacity-50"
                    :disabled="state === 'applying' || parsed.action === 'unknown' || parsed.action === 'show_day' || !parsed.target"
                    @click="apply"
                >
                    <Loader2 v-if="state === 'applying'" class="h-4 w-4 animate-spin" />
                    <Check v-else class="h-4 w-4" />
                </button>
                <button
                    class="flex h-10 w-10 items-center justify-center rounded-lg border border-border text-muted-foreground"
                    @click="dismiss"
                >
                    <X class="h-4 w-4" />
                </button>
            </div>
        </div>
        <p v-if="parsed.action === 'unknown'" class="mt-2 text-xs text-amber-500">
            Claude isn't sure — pick the action and fix the fields, then confirm.
            Your correction teaches the parser.
        </p>
        <p v-else-if="parsed.confidence < 0.7" class="mt-2 text-xs text-amber-500">
            Low confidence — double-check before confirming.
        </p>
    </div>
    </Transition>

    <!-- Applied: one-tap undo -->
    <Transition name="form">
    <div
        v-if="state === 'applied'"
        class="mt-3 flex items-center justify-between rounded-xl border border-green-500/30 bg-green-500/5 p-3"
    >
        <span class="text-sm text-green-600 dark:text-green-400">Applied ✓</span>
        <div class="flex gap-3">
            <button class="flex items-center gap-1 text-sm text-muted-foreground" @click="undo">
                <RotateCcw class="h-3.5 w-3.5" /> Undo
            </button>
            <button class="text-sm text-muted-foreground" @click="dismiss">
                <X class="h-4 w-4" />
            </button>
        </div>
    </div>
    </Transition>

    <div class="mt-6 space-y-4">
        <section class="rounded-xl border border-border bg-card p-4">
            <h2 class="text-sm font-medium text-muted-foreground">Expense</h2>
            <p v-if="!payables.length" class="mt-2 text-sm text-muted-foreground/70">
                No open expenses
            </p>
            <ul v-else class="mt-2 divide-y divide-border">
                <li v-for="e in payables" :key="e.id" class="flex justify-between py-2 text-sm">
                    <span class="truncate">{{ e.contact?.name ?? e.title }}</span>
                    <span class="ml-2 shrink-0 font-medium">{{ formatMmk(e.amount_mmk) }}</span>
                </li>
            </ul>
        </section>

        <section class="rounded-xl border border-border bg-card p-4">
            <h2 class="text-sm font-medium text-muted-foreground">Income</h2>
            <p v-if="!receivables.length" class="mt-2 text-sm text-muted-foreground/70">
                No expected income
            </p>
            <ul v-else class="mt-2 divide-y divide-border">
                <li v-for="e in receivables" :key="e.id" class="flex justify-between py-2 text-sm">
                    <span class="truncate">{{ e.contact?.name ?? e.title }}</span>
                    <span class="ml-2 shrink-0 font-medium">{{ formatMmk(e.amount_mmk) }}</span>
                </li>
            </ul>
        </section>

        <section class="rounded-xl border border-border bg-card p-4">
            <h2 class="text-sm font-medium text-muted-foreground">Today</h2>
            <p
                v-if="!today.length && !careToday.length"
                class="mt-2 text-sm text-muted-foreground/70"
            >
                Nothing due today
            </p>
            <ul v-else class="mt-2 divide-y divide-border">
                <li v-for="t in careToday" :key="`c${t.id}`" class="py-2 text-sm">
                    💗 {{ t.title }}
                </li>
                <li v-for="t in today" :key="t.id" class="py-2 text-sm">{{ t.title }}</li>
            </ul>
        </section>

        <section class="rounded-xl border border-border bg-card p-4">
            <h2 class="text-sm font-medium text-muted-foreground">Overdue</h2>
            <p v-if="!overdue.length" class="mt-2 text-sm text-muted-foreground/70">
                Nothing overdue 🎉
            </p>
            <ul v-else class="mt-2 divide-y divide-border">
                <li v-for="t in overdue" :key="t.id" class="flex justify-between py-2 text-sm">
                    <span class="truncate">{{ t.title }}</span>
                    <span class="ml-2 shrink-0 text-red-500">{{ formatDate(t.due_date) }}</span>
                </li>
            </ul>
        </section>
    </div>
</template>
