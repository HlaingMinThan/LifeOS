<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { Check, Loader2, RotateCcw, Send, Target, UserRound, X } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import DateTimeField from '@/components/DateTimeField.vue';
import { apiPost } from '@/lib/api';
import { formatDate, formatMmk, formatTime } from '@/lib/format';

type Todo = {
    id: number;
    title: string;
    bucket: string;
    status: string;
    due_date: string | null;
    due_time: string | null;
};
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

const props = defineProps<{
    focus: Todo | null;
    nextUp: Todo | null;
    overdue: Todo[];
    todayTodos: Todo[];
    careToday: CareTask[];
    money: { incoming: number; toPay: number; dueThisWeek: number; overdue: number };
    tomorrow: { todos: number; care: number };
    parkedIdea: string | null;
}>();

const page = usePage();
const greeting = computed(() => {
    const hour = new Date().getHours();
    const name = ((page.props as any).auth?.user?.name ?? '').split(' ')[0];
    const part = hour < 12 ? 'morning' : hour < 18 ? 'afternoon' : 'evening';
    return `Good ${part}${name ? ', ' + name : ''}`;
});
const todayIso = new Date().toLocaleDateString('sv-SE');
const tomorrowIso = new Date(Date.now() + 86400000).toLocaleDateString('sv-SE');
const dateLabel = new Date().toLocaleDateString('en-GB', {
    weekday: 'short',
    day: 'numeric',
    month: 'short',
});
const net = computed(() => props.money.incoming - props.money.toPay);
const allClear = computed(
    () => !props.overdue.length && !props.todayTodos.length && !props.careToday.length,
);

const toggleTodo = (t: Todo) =>
    router.patch(`/todos/${t.id}/toggle`, {}, { preserveScroll: true });
const clearFocus = () => {
    if (props.focus) router.patch(`/todos/${props.focus.id}/focus`, {}, { preserveScroll: true });
};

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
const DATED_ACTIONS = ['add_todo', 'add_payable', 'add_receivable', 'add_care_task'];

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
            <h1 class="text-2xl font-bold text-gradient-brand">{{ greeting }}</h1>
            <p class="mt-1 text-sm text-muted-foreground">{{ dateLabel }}</p>
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
                <div v-if="DATED_ACTIONS.includes(parsed.action)" class="flex gap-2">
                    <DateTimeField v-model="parsed.due" mode="date" class="flex-1" placeholder="Due date" />
                    <DateTimeField
                        v-if="parsed.action === 'add_todo'"
                        v-model="parsed.due_time"
                        mode="time"
                        class="w-32"
                        placeholder="Time"
                    />
                </div>
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
        <!-- 🎯 Focus: the one thing you chose to keep front and center -->
        <section
            v-if="focus"
            class="rounded-2xl border-2 border-primary/50 bg-gradient-to-br from-primary/10 to-pink-500/5 p-4 shadow-md shadow-fuchsia-500/10"
        >
            <div class="flex items-center justify-between">
                <span class="flex items-center gap-1.5 text-xs font-semibold uppercase tracking-wide text-primary">
                    <Target class="h-3.5 w-3.5" /> Focus
                </span>
                <button class="text-xs text-muted-foreground" @click="clearFocus">Clear</button>
            </div>
            <Link :href="`/todos/${focus.id}`" class="mt-2 block">
                <p class="text-lg font-bold leading-snug">{{ focus.title }}</p>
                <p v-if="focus.due_time" class="mt-0.5 text-sm text-muted-foreground">
                    ⏰ {{ formatTime(focus.due_time) }}
                </p>
            </Link>
            <button
                class="mt-3 flex w-full items-center justify-center gap-2 rounded-xl bg-gradient-brand py-2.5 text-sm font-semibold text-white shadow-md shadow-fuchsia-500/20 active:scale-[0.99]"
                @click="toggleTodo(focus)"
            >
                <Check class="h-4 w-4" /> Mark done
            </button>
        </section>

        <!-- ⚡ Next up: the next timed thing today -->
        <section
            v-if="nextUp"
            class="flex items-center gap-3 rounded-xl border border-primary/40 bg-primary/5 p-4"
        >
            <span class="text-lg">⚡</span>
            <div class="min-w-0 flex-1">
                <p class="text-xs font-medium uppercase tracking-wide text-primary">Next up</p>
                <p class="mt-0.5 truncate text-sm font-medium">
                    ⏰ {{ formatTime(nextUp.due_time) }} — {{ nextUp.title }}
                </p>
            </div>
            <button
                class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg border border-border text-muted-foreground"
                @click="toggleTodo(nextUp)"
            >
                <Check class="h-4 w-4" />
            </button>
        </section>

        <!-- 🔴 Overdue: only when something slipped -->
        <section
            v-if="overdue.length"
            class="rounded-xl border border-red-500/40 bg-red-500/5 p-4"
        >
            <h2 class="text-sm font-semibold text-red-500">🔴 Overdue ({{ overdue.length }})</h2>
            <ul class="mt-2 divide-y divide-border">
                <li v-for="t in overdue" :key="t.id" class="flex items-center gap-2 py-2 text-sm">
                    <button
                        class="flex h-7 w-7 shrink-0 items-center justify-center rounded-lg border border-border text-muted-foreground"
                        @click="toggleTodo(t)"
                    >
                        <Check class="h-3.5 w-3.5" />
                    </button>
                    <span class="min-w-0 flex-1 truncate">{{ t.title }}</span>
                    <span class="shrink-0 text-xs text-red-500">{{ formatDate(t.due_date) }}</span>
                </li>
            </ul>
        </section>

        <!-- 📌 Today -->
        <Link :href="`/todos/day/${todayIso}`" class="block">
            <section class="rounded-xl border border-border bg-card p-4">
                <h2 class="flex items-center justify-between text-sm font-medium text-muted-foreground">
                    📌 Today
                    <span class="text-xs">day page →</span>
                </h2>
                <p
                    v-if="!todayTodos.length && !careToday.length"
                    class="mt-2 text-sm text-muted-foreground/70"
                >
                    Nothing due today 🎉
                </p>
                <ul v-else class="mt-2 divide-y divide-border">
                    <li
                        v-for="t in todayTodos"
                        :key="t.id"
                        class="flex items-center gap-2 py-2 text-sm"
                        :class="{ 'opacity-50': t.status === 'done' }"
                    >
                        <button
                            class="flex h-7 w-7 shrink-0 items-center justify-center rounded-lg border"
                            :class="
                                t.status === 'done'
                                    ? 'border-green-500/40 bg-green-500/10 text-green-500'
                                    : 'border-border text-muted-foreground'
                            "
                            @click.prevent.stop="toggleTodo(t)"
                        >
                            <Check class="h-3.5 w-3.5" />
                        </button>
                        <span
                            class="min-w-0 flex-1 truncate"
                            :class="{ 'line-through': t.status === 'done' }"
                        >
                            {{ t.title }}
                        </span>
                        <span v-if="t.due_time" class="shrink-0 text-xs text-muted-foreground">
                            {{ formatTime(t.due_time) }}
                        </span>
                    </li>
                    <li v-for="t in careToday" :key="`c${t.id}`" class="py-2 text-sm">
                        💗 {{ t.title }}
                    </li>
                </ul>
            </section>
        </Link>

        <!-- 💵 Money strip -->
        <Link href="/money" class="block">
            <section class="flex items-center justify-between rounded-xl border border-border bg-card p-4">
                <div class="min-w-0">
                    <h2 class="text-sm font-medium text-muted-foreground">💵 Money</h2>
                    <p class="mt-1 text-sm">
                        <span
                            class="font-bold tabular-nums"
                            :class="net >= 0 ? 'text-green-500' : 'text-rose-400'"
                        >
                            Net {{ (net >= 0 ? '+' : '') + net.toLocaleString() }}
                        </span>
                        <span v-if="money.dueThisWeek" class="text-muted-foreground">
                            · {{ money.dueThisWeek }} due this week
                        </span>
                    </p>
                </div>
                <span
                    v-if="money.overdue"
                    class="shrink-0 rounded-full bg-red-500/15 px-2 py-1 text-xs font-semibold text-red-500"
                >
                    {{ money.overdue }} overdue
                </span>
                <span v-else class="shrink-0 text-xs text-muted-foreground">→</span>
            </section>
        </Link>

        <!-- 🌙 Tomorrow peek -->
        <Link
            v-if="tomorrow.todos || tomorrow.care"
            :href="`/todos/day/${tomorrowIso}`"
            class="block"
        >
            <section class="flex items-center justify-between rounded-xl border border-border bg-card p-4">
                <p class="text-sm text-muted-foreground">
                    🌙 Tomorrow:
                    <span v-if="tomorrow.todos" class="text-foreground">{{ tomorrow.todos }} todo{{ tomorrow.todos > 1 ? 's' : '' }}</span>
                    <span v-if="tomorrow.todos && tomorrow.care"> · </span>
                    <span v-if="tomorrow.care" class="text-foreground">💗 {{ tomorrow.care }} care</span>
                </p>
                <span class="text-xs text-muted-foreground">→</span>
            </section>
        </Link>

        <!-- 💡 Parked idea of the week -->
        <Link v-if="parkedIdea" href="/ideas" class="block">
            <section class="rounded-xl border border-dashed border-border p-3 text-center text-xs text-muted-foreground">
                💡 Still parked: <span class="text-foreground">{{ parkedIdea }}</span>
            </section>
        </Link>

        <p v-if="allClear" class="pt-2 text-center text-sm text-muted-foreground">
            All clear — nothing needs you right now 🎉
        </p>
    </div>
</template>
