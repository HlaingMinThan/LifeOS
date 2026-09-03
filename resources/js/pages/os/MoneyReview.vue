<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import {
    ArrowLeft,
    ChevronLeft,
    ChevronRight,
    Loader2,
    Pencil,
    Sparkles,
    TrendingDown,
    TrendingUp,
} from 'lucide-vue-next';
import { computed, reactive, ref, watch } from 'vue';
import { apiPost } from '@/lib/api';
import { formatMmk } from '@/lib/format';

type CategoryMember = { category: string; count: number; total: number };
type Category = {
    category: string;
    count: number;
    total: number;
    share?: number;
    // Populated only on the synthetic "Other" row.
    members?: CategoryMember[];
};
type Period = {
    income: number;
    expenses: number;
    net: number;
    savings_rate: number | null;
    categories: Category[];
    label?: string;
};
type Pattern = {
    key: string;
    label: string;
    count: number;
    total: number;
    current: string[];
    /** Labels this merchant already carries — the likely answer. */
    options: string[];
    /** The most common of those, pre-filled into the box. */
    suggested: string | null;
    conflicted: boolean;
    unlabelled: boolean;
};
type Bucket = { count: number; total: number };
type Outstanding = Record<
    'overdue' | 'this_week' | 'later' | 'no_date',
    { receivable: Bucket; payable: Bucket }
>;

const props = defineProps<{
    monthly: Period & {
        month: string;
        label: string;
        previous: Period & { label: string };
        change: { income: number | null; expenses: number | null };
    };
    thisWeek: Period & { label: string };
    lastWeek: Period & { label: string };
    outstanding: Outstanding;
    indicator: { level: string; emoji: string; message: string };
    patterns: Pattern[];
    knownCategories: string[];
}>();

// Colour tracks meaning, not sign: for expenses "up" is bad, for income good.
const INDICATOR_RING: Record<string, string> = {
    good: 'border-green-500/40 bg-green-500/5',
    watch: 'border-amber-500/40 bg-amber-500/5',
    bad: 'border-rose-500/40 bg-rose-500/5',
    none: 'border-border bg-card',
};

// A single palette so the same category keeps its colour down the page.
const BAR_COLORS = [
    'bg-fuchsia-500',
    'bg-violet-500',
    'bg-sky-500',
    'bg-emerald-500',
    'bg-amber-500',
    'bg-rose-500',
    'bg-teal-500',
];

function shiftMonth(delta: number) {
    const [y, m] = props.monthly.month.split('-').map(Number);
    const d = new Date(y, m - 1 + delta, 1);
    const target = `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`;
    router.get('/money/review', { month: target }, { preserveState: false });
}

const savingsLabel = computed(() =>
    props.monthly.savings_rate === null ? '—' : `${props.monthly.savings_rate}%`,
);

// Only the two buckets that need action; "later" and "no date" are not news.
const owedRows = computed(() =>
    (
        [
            { key: 'overdue', label: 'Overdue', urgent: true },
            { key: 'this_week', label: 'This week', urgent: false },
        ] as const
    ).flatMap((b) =>
        (
            [
                { dir: 'payable', word: 'to pay', tone: 'text-rose-400' },
                { dir: 'receivable', word: 'to collect', tone: 'text-green-500' },
            ] as const
        )
            .map((d) => ({
                ...b,
                ...d,
                ...props.outstanding[b.key][d.dir],
            }))
            .filter((r) => r.count > 0),
    ),
);

// --- Repeating merchants filed inconsistently ---
// Detection arrived free with the page; naming is the only step that costs,
// so it waits for a tap.
const drafts = reactive<Record<string, string>>({});
const naming = ref(false);
const nameError = ref('');

// Start each box on the label the merchant already carries, so the usual fix
// ("half of these say Loans, the rest say nothing") is a single tap on
// "Move all" rather than a blank field to puzzle over.
watch(
    () => props.patterns,
    (patterns) => {
        for (const p of patterns) {
            if (drafts[p.key] === undefined) drafts[p.key] = p.suggested ?? '';
        }
    },
    { immediate: true },
);

// Its own labels first — those are the likely answer — then everything else
// in use, so picking never invents a near-duplicate like "Food" vs "Foods".
const chipsFor = (p: Pattern) => [
    ...p.options,
    ...props.knownCategories.filter((c) => !p.options.includes(c)),
];

async function nameAll() {
    naming.value = true;
    nameError.value = '';
    try {
        const res = await apiPost<{ suggestions: Record<string, string> }>(
            '/money/patterns/name',
            {},
        );
        for (const [key, category] of Object.entries(res.suggestions)) {
            drafts[key] = category;
        }
    } catch (e) {
        nameError.value = (e as Error).message;
    } finally {
        naming.value = false;
    }
}

function applyPattern(p: Pattern) {
    const category = drafts[p.key]?.trim();
    if (!category) return;
    router.post(
        '/money/patterns/apply',
        { key: p.key, category, label: p.label },
        { preserveScroll: true },
    );
}

const dismissPattern = (p: Pattern) =>
    router.post(
        '/money/patterns/dismiss',
        { key: p.key, label: p.label },
        { preserveScroll: true },
    );

const otherOpen = ref(false);
const toggleOther = () => (otherOpen.value = !otherOpen.value);

const categoryUrl = (name: string) =>
    `/money/category?name=${encodeURIComponent(name)}&month=${props.monthly.month}`;

const weekTab = ref<'this' | 'last'>('this');
const week = computed(() => (weekTab.value === 'this' ? props.thisWeek : props.lastWeek));

// --- Category rename (applies to every entry carrying the old name) ---
const renaming = ref<string | null>(null);
const renameTo = ref('');

function startRename(name: string) {
    renaming.value = name;
    renameTo.value = name;
}

function saveRename() {
    const from = renaming.value;
    if (!from || !renameTo.value.trim() || renameTo.value === from) {
        renaming.value = null;
        return;
    }
    router.post(
        '/money/categories/rename',
        { from, to: renameTo.value.trim() },
        { preserveScroll: true, onFinish: () => (renaming.value = null) },
    );
}
</script>

<template>
    <Head title="Money review" />

    <div>
        <Link href="/money" class="mb-2 flex items-center gap-1 text-xs text-muted-foreground">
            <ArrowLeft class="h-3.5 w-3.5" /> Money
        </Link>
        <h1 class="text-2xl font-bold text-gradient-brand">Money review</h1>
        <p class="mt-1 text-sm text-muted-foreground">
            Based on money that actually moved — settled entries only.
        </p>
    </div>

    <!-- Verdict -->
    <div class="mt-4 rounded-xl border p-4" :class="INDICATOR_RING[indicator.level]">
        <div class="flex items-start gap-3">
            <span class="text-2xl leading-none">{{ indicator.emoji }}</span>
            <p class="text-sm font-medium">{{ indicator.message }}</p>
        </div>
    </div>

    <!-- Month navigation -->
    <div class="mt-4 flex items-center justify-between">
        <button
            class="flex h-8 w-8 items-center justify-center rounded-lg border border-border text-muted-foreground"
            @click="shiftMonth(-1)"
        >
            <ChevronLeft class="h-4 w-4" />
        </button>
        <span class="text-sm font-semibold">{{ monthly.label }}</span>
        <button
            class="flex h-8 w-8 items-center justify-center rounded-lg border border-border text-muted-foreground"
            @click="shiftMonth(1)"
        >
            <ChevronRight class="h-4 w-4" />
        </button>
    </div>

    <!-- Month totals -->
    <div class="mt-3 grid grid-cols-3 gap-2">
        <div class="rounded-xl border border-border bg-card p-3 text-center">
            <p class="text-xs text-muted-foreground">In</p>
            <p class="mt-1 text-base font-bold tabular-nums text-green-500">
                {{ monthly.income.toLocaleString() }}
            </p>
        </div>
        <div class="rounded-xl border border-border bg-card p-3 text-center">
            <p class="text-xs text-muted-foreground">Out</p>
            <p class="mt-1 text-base font-bold tabular-nums text-rose-400">
                {{ monthly.expenses.toLocaleString() }}
            </p>
        </div>
        <div class="rounded-xl border border-border bg-card p-3 text-center">
            <p class="text-xs text-muted-foreground">Kept</p>
            <p
                class="mt-1 text-base font-bold tabular-nums"
                :class="monthly.net >= 0 ? 'text-green-500' : 'text-rose-400'"
            >
                {{ savingsLabel }}
            </p>
        </div>
    </div>

    <!-- vs previous month -->
    <div
        v-if="monthly.change.expenses !== null || monthly.change.income !== null"
        class="mt-2 flex flex-wrap items-center gap-x-4 gap-y-1 rounded-xl border border-border bg-card px-3 py-2 text-xs"
    >
        <span class="text-muted-foreground">vs {{ monthly.previous.label }}</span>
        <span
            v-if="monthly.change.expenses !== null"
            class="flex items-center gap-1 font-medium"
            :class="monthly.change.expenses > 0 ? 'text-rose-400' : 'text-green-500'"
        >
            <component
                :is="monthly.change.expenses > 0 ? TrendingUp : TrendingDown"
                class="h-3.5 w-3.5"
            />
            spending {{ Math.abs(monthly.change.expenses) }}%
            {{ monthly.change.expenses > 0 ? 'up' : 'down' }}
        </span>
        <span
            v-if="monthly.change.income !== null"
            class="flex items-center gap-1 font-medium"
            :class="monthly.change.income >= 0 ? 'text-green-500' : 'text-rose-400'"
        >
            <component
                :is="monthly.change.income >= 0 ? TrendingUp : TrendingDown"
                class="h-3.5 w-3.5"
            />
            income {{ Math.abs(monthly.change.income) }}%
            {{ monthly.change.income >= 0 ? 'up' : 'down' }}
        </span>
    </div>

    <!-- Recurring merchants filed inconsistently -->
    <section v-if="patterns.length" class="mt-6">
        <div class="flex items-center justify-between">
            <h2 class="text-sm font-medium text-muted-foreground">
                {{ patterns.length }} repeating
                {{ patterns.length === 1 ? 'merchant needs' : 'merchants need' }} a category
            </h2>
            <button
                class="flex items-center gap-1 rounded-lg border border-border px-2 py-1 text-xs text-muted-foreground disabled:opacity-50"
                :disabled="naming"
                @click="nameAll"
            >
                <Loader2 v-if="naming" class="h-3 w-3 animate-spin" />
                <Sparkles v-else class="h-3 w-3" />
                {{ naming ? 'Thinking…' : 'Suggest names' }}
            </button>
        </div>
        <p class="mt-1 text-xs text-muted-foreground">
            The same person or shop filed under different categories. Pick one
            and every entry moves — and it stays filed that way from now on.
        </p>
        <p v-if="nameError" class="mt-1 text-xs text-rose-400">{{ nameError }}</p>

        <ul class="mt-2 space-y-2">
            <li
                v-for="p in patterns"
                :key="p.key"
                class="rounded-xl border border-amber-500/30 bg-amber-500/5 p-3"
            >
                <div class="flex items-baseline justify-between gap-2">
                    <p class="min-w-0 truncate text-sm font-medium">{{ p.label }}</p>
                    <span class="shrink-0 text-sm font-bold tabular-nums text-rose-400">
                        {{ p.total.toLocaleString() }}
                    </span>
                </div>
                <p class="mt-0.5 text-xs text-muted-foreground">
                    {{ p.count }} entries ·
                    <template v-if="p.conflicted">
                        split across
                        <span class="font-medium text-amber-500">{{ p.current.join(', ') }}</span>
                    </template>
                    <template v-else>all unlabelled</template>
                </p>

                <!-- One tap for the common case: a label it already carries. -->
                <div class="mt-2 flex flex-wrap gap-1.5">
                    <button
                        v-for="c in chipsFor(p)"
                        :key="c"
                        class="rounded-full border px-2 py-1 text-xs"
                        :class="
                            drafts[p.key] === c
                                ? 'border-primary bg-primary/10 font-medium text-primary'
                                : 'border-border text-muted-foreground'
                        "
                        @click="drafts[p.key] = c"
                    >
                        {{ c }}
                    </button>
                </div>

                <div class="mt-2 flex items-center gap-2">
                    <input
                        v-model="drafts[p.key]"
                        type="text"
                        placeholder="or type a new category"
                        class="min-w-0 flex-1 rounded-lg border border-input bg-background px-2 py-1.5 text-sm outline-none focus:ring-2 focus:ring-ring"
                        @keyup.enter="applyPattern(p)"
                    />
                    <button
                        class="shrink-0 rounded-lg bg-gradient-brand px-3 py-1.5 text-xs font-medium text-white disabled:opacity-40"
                        :disabled="!drafts[p.key]?.trim()"
                        @click="applyPattern(p)"
                    >
                        Move all
                    </button>
                    <button
                        class="shrink-0 rounded-lg border border-border px-2 py-1.5 text-xs text-muted-foreground"
                        @click="dismissPattern(p)"
                    >
                        Ignore
                    </button>
                </div>
            </li>
        </ul>
    </section>

    <!-- Where the money went -->
    <section class="mt-6">
        <h2 class="text-sm font-medium text-muted-foreground">Where it went</h2>

        <p
            v-if="!monthly.categories.length"
            class="mt-2 rounded-xl border border-dashed border-border p-6 text-center text-sm text-muted-foreground"
        >
            No settled spending this month.
        </p>

        <ul v-else class="mt-2 space-y-2">
            <li
                v-for="(c, i) in monthly.categories"
                :key="c.category"
                class="rounded-xl border border-border bg-card p-3"
            >
                <div v-if="renaming === c.category" class="flex items-center gap-2">
                    <input
                        v-model="renameTo"
                        type="text"
                        class="min-w-0 flex-1 rounded-lg border border-input bg-background px-2 py-1.5 text-sm outline-none focus:ring-2 focus:ring-ring"
                        @keyup.enter="saveRename"
                    />
                    <button
                        class="rounded-lg bg-gradient-brand px-3 py-1.5 text-xs text-white"
                        @click="saveRename"
                    >
                        Save
                    </button>
                    <button
                        class="rounded-lg border border-border px-3 py-1.5 text-xs text-muted-foreground"
                        @click="renaming = null"
                    >
                        Cancel
                    </button>
                </div>

                <template v-else>
                    <div class="flex items-baseline justify-between gap-2">
                        <!-- "Other" is a display bucket with no entries of its
                             own, so it expands in place instead of linking. -->
                        <component
                            :is="c.members?.length ? 'button' : Link"
                            v-bind="
                                c.members?.length
                                    ? {}
                                    : { href: categoryUrl(c.category) }
                            "
                            class="flex min-w-0 items-center gap-1 text-left text-sm font-medium"
                            @click="c.members?.length && toggleOther()"
                        >
                            <span class="truncate">{{ c.category }}</span>
                            <ChevronRight
                                class="h-3.5 w-3.5 shrink-0 text-muted-foreground/50 transition-transform"
                                :class="{ 'rotate-90': c.members?.length && otherOpen }"
                            />
                        </component>
                        <div class="flex shrink-0 items-baseline gap-2">
                            <span class="text-sm font-bold tabular-nums text-rose-400">
                                {{ c.total.toLocaleString() }}
                            </span>
                            <button
                                v-if="!c.members?.length"
                                class="p-1 text-muted-foreground/50"
                                @click.stop.prevent="startRename(c.category)"
                            >
                                <Pencil class="h-3 w-3" />
                            </button>
                        </div>
                    </div>
                    <div class="mt-2 h-1.5 overflow-hidden rounded-full bg-muted">
                        <div
                            class="h-full rounded-full"
                            :class="BAR_COLORS[i % BAR_COLORS.length]"
                            :style="{ width: `${c.share ?? 0}%` }"
                        ></div>
                    </div>
                    <p class="mt-1 text-xs text-muted-foreground">
                        {{ c.share ?? 0 }}% · {{ c.count }}
                        {{ c.count === 1 ? 'entry' : 'entries' }}
                    </p>

                    <!-- The small categories folded into "Other" -->
                    <ul v-if="c.members?.length && otherOpen" class="mt-2 space-y-1 border-t border-border pt-2">
                        <li v-for="m in c.members" :key="m.category">
                            <Link
                                :href="categoryUrl(m.category)"
                                class="flex items-baseline justify-between gap-2 text-xs"
                            >
                                <span class="truncate text-muted-foreground">{{ m.category }}</span>
                                <span class="shrink-0 tabular-nums">{{ m.total.toLocaleString() }}</span>
                            </Link>
                        </li>
                    </ul>
                </template>
            </li>
        </ul>
    </section>

    <!-- Weekly -->
    <section class="mt-6">
        <div class="flex items-center justify-between">
            <h2 class="text-sm font-medium text-muted-foreground">By week</h2>
            <div class="flex rounded-lg border border-border p-0.5 text-xs">
                <button
                    class="rounded-md px-2 py-1"
                    :class="weekTab === 'this' ? 'bg-primary/10 font-medium text-primary' : 'text-muted-foreground'"
                    @click="weekTab = 'this'"
                >
                    This week
                </button>
                <button
                    class="rounded-md px-2 py-1"
                    :class="weekTab === 'last' ? 'bg-primary/10 font-medium text-primary' : 'text-muted-foreground'"
                    @click="weekTab = 'last'"
                >
                    Last week
                </button>
            </div>
        </div>

        <div class="mt-2 rounded-xl border border-border bg-card p-3">
            <div class="flex items-baseline justify-between">
                <span class="text-xs text-muted-foreground">{{ week.label }}</span>
                <span class="text-sm font-bold tabular-nums text-rose-400">
                    {{ formatMmk(week.expenses) || '0 Ks' }}
                </span>
            </div>

            <ul v-if="week.categories.length" class="mt-3 space-y-1.5">
                <li v-for="c in week.categories" :key="c.category">
                    <Link
                        :href="categoryUrl(c.category)"
                        class="flex items-baseline justify-between gap-2 text-sm"
                    >
                        <span class="truncate text-muted-foreground">{{ c.category }}</span>
                        <span class="shrink-0 tabular-nums">{{ c.total.toLocaleString() }}</span>
                    </Link>
                </li>
            </ul>
            <p v-else class="mt-2 text-xs text-muted-foreground">Nothing settled this week.</p>
        </div>
    </section>

    <!-- Outstanding -->
    <section v-if="owedRows.length" class="mt-6 mb-2">
        <h2 class="text-sm font-medium text-muted-foreground">Still outstanding</h2>
        <ul class="mt-2 space-y-2">
            <li
                v-for="r in owedRows"
                :key="`${r.key}-${r.dir}`"
                class="flex items-center justify-between rounded-xl border bg-card p-3"
                :class="r.urgent ? 'border-rose-500/40' : 'border-border'"
            >
                <div>
                    <p class="text-sm font-medium">
                        {{ r.urgent ? '🔴' : '📆' }} {{ r.label }}
                    </p>
                    <p class="text-xs text-muted-foreground">
                        {{ r.count }} {{ r.count === 1 ? 'entry' : 'entries' }} {{ r.word }}
                    </p>
                </div>
                <span class="text-sm font-bold tabular-nums" :class="r.tone">
                    {{ r.total.toLocaleString() }}
                </span>
            </li>
        </ul>
    </section>
</template>
