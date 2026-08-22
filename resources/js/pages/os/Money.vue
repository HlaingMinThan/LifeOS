<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { Check, Pencil, Plus, RotateCcw, Trash2 } from 'lucide-vue-next';
import { computed, reactive, ref } from 'vue';
import DateTimeField from '@/components/DateTimeField.vue';
import SwipeRow from '@/components/SwipeRow.vue';
import { formatDate, formatMmk } from '@/lib/format';

type Entry = {
    id: number;
    direction: 'payable' | 'receivable';
    title: string;
    amount_mmk: number;
    status: string;
    due_date: string | null;
    note: string | null;
    contact?: { name: string } | null;
};

const props = defineProps<{
    open: Entry[];
    settled: Entry[];
    settledCount: number;
}>();

const editingId = ref<number | null>(null);
const showNewForm = ref(false);
const form = reactive({
    direction: 'payable' as string,
    title: '',
    amount_mmk: null as number | null,
    due_date: '',
    note: '',
});

const toggle = (e: Entry) =>
    router.patch(`/ledger/${e.id}/toggle`, {}, { preserveScroll: true });
const remove = (e: Entry) =>
    router.delete(`/ledger/${e.id}`, { preserveScroll: true });

// --- Open balance summary ---
const open = computed(() => props.open);
const incoming = computed(() =>
    open.value.filter((e) => e.direction === 'receivable').reduce((s, e) => s + e.amount_mmk, 0),
);
const toPay = computed(() =>
    open.value.filter((e) => e.direction === 'payable').reduce((s, e) => s + e.amount_mmk, 0),
);
const net = computed(() => incoming.value - toPay.value);

// --- Urgency grouping (dates are Y-m-d strings, safe to compare) ---
const todayIso = new Date().toLocaleDateString('sv-SE');
const weekIso = new Date(Date.now() + 6 * 86400000).toLocaleDateString('sv-SE');

const groups = computed(() => [
    {
        key: 'overdue',
        label: '🔴 Overdue',
        items: open.value.filter((e) => e.due_date && e.due_date < todayIso),
    },
    {
        key: 'week',
        label: '📅 This week',
        items: open.value.filter(
            (e) => e.due_date && e.due_date >= todayIso && e.due_date <= weekIso,
        ),
    },
    {
        key: 'later',
        label: '🗓 Later',
        items: open.value.filter((e) => e.due_date && e.due_date > weekIso),
    },
    {
        key: 'nodate',
        label: '📝 No date',
        items: open.value.filter((e) => !e.due_date),
    },
    {
        key: 'settled',
        label: '✓ Settled',
        items: props.settled,
    },
]);

const showAllSettled = () =>
    router.get('/money', { all_settled: 1 }, { preserveScroll: true });

function startNew(direction: string = 'payable') {
    editingId.value = null;
    form.direction = direction;
    form.title = '';
    form.amount_mmk = null;
    form.due_date = '';
    form.note = '';
    showNewForm.value = true;
}

function startEdit(e: Entry) {
    showNewForm.value = false;
    editingId.value = e.id;
    form.direction = e.direction;
    form.title = e.title;
    form.amount_mmk = e.amount_mmk;
    form.due_date = e.due_date?.slice(0, 10) ?? '';
    form.note = e.note ?? '';
}

function closeForm() {
    editingId.value = null;
    showNewForm.value = false;
}

function saveForm() {
    const payload = {
        direction: form.direction,
        title: form.title,
        amount_mmk: form.amount_mmk,
        due_date: form.due_date || null,
        note: form.note || null,
    };
    const opts = { preserveScroll: true, onSuccess: closeForm };

    if (editingId.value) router.patch(`/ledger/${editingId.value}`, payload, opts);
    else router.post('/ledger', payload, opts);
}

</script>

<template>
    <Head title="Money" />

    <div class="flex items-start justify-between">
        <div>
            <h1 class="text-2xl font-bold text-gradient-brand">Money</h1>
            <p class="mt-1 text-sm text-muted-foreground">What's due, soonest first.</p>
        </div>
        <button
            class="mt-1 flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-gradient-brand text-white shadow-md shadow-fuchsia-500/20 transition-transform active:scale-95"
            @click="startNew()"
        >
            <Plus class="h-5 w-5" />
        </button>
    </div>

    <!-- Open balance summary -->
    <div v-if="open.length" class="mt-4 grid grid-cols-3 gap-2">
        <div class="rounded-xl border border-border bg-card p-3 text-center">
            <p class="text-xs text-muted-foreground">💰 Incoming</p>
            <p class="mt-1 text-base font-bold tabular-nums text-green-500">
                {{ incoming.toLocaleString() }}
            </p>
        </div>
        <div class="rounded-xl border border-border bg-card p-3 text-center">
            <p class="text-xs text-muted-foreground">💸 To pay</p>
            <p class="mt-1 text-base font-bold tabular-nums text-rose-400">
                {{ toPay.toLocaleString() }}
            </p>
        </div>
        <div class="rounded-xl border border-border bg-card p-3 text-center">
            <p class="text-xs text-muted-foreground">Net</p>
            <p
                class="mt-1 text-base font-bold tabular-nums"
                :class="net >= 0 ? 'text-green-500' : 'text-rose-400'"
            >
                {{ (net >= 0 ? '+' : '') + net.toLocaleString() }}
            </p>
        </div>
    </div>

    <!-- Create / edit form -->
    <Transition name="form">
    <div
        v-if="showNewForm || editingId"
        class="mt-4 space-y-2 rounded-xl border border-primary/40 bg-card p-3"
    >
        <div class="grid grid-cols-2 gap-2">
            <button
                class="rounded-lg border py-2 text-sm"
                :class="
                    form.direction === 'payable'
                        ? 'border-primary bg-primary/10 font-medium text-primary'
                        : 'border-border text-muted-foreground'
                "
                @click="form.direction = 'payable'"
            >
                Expense 💸
            </button>
            <button
                class="rounded-lg border py-2 text-sm"
                :class="
                    form.direction === 'receivable'
                        ? 'border-primary bg-primary/10 font-medium text-primary'
                        : 'border-border text-muted-foreground'
                "
                @click="form.direction = 'receivable'"
            >
                Income 💰
            </button>
        </div>
        <input
            v-model="form.title"
            type="text"
            placeholder="Who / what?"
            class="w-full rounded-lg border border-input bg-background px-2 py-1.5 text-sm outline-none focus:ring-2 focus:ring-ring"
        />
        <div class="flex gap-2">
            <input
                v-model.number="form.amount_mmk"
                type="number"
                min="1"
                placeholder="Amount (Ks)"
                class="flex-1 rounded-lg border border-input bg-background px-2 py-1.5 text-sm outline-none focus:ring-2 focus:ring-ring"
            />
            <DateTimeField v-model="form.due_date" mode="date" class="w-40" placeholder="Due date" />
        </div>
        <p v-if="form.amount_mmk" class="text-xs text-muted-foreground">
            = {{ formatMmk(form.amount_mmk) }}
        </p>
        <textarea
            v-model="form.note"
            rows="2"
            placeholder="Note… (optional)"
            class="w-full rounded-lg border border-input bg-background px-2 py-1.5 text-sm outline-none focus:ring-2 focus:ring-ring"
        ></textarea>
        <div class="flex gap-2">
            <button
                class="flex-1 rounded-lg bg-gradient-brand py-2 text-sm font-medium text-white disabled:opacity-50"
                :disabled="!form.title.trim() || !form.amount_mmk"
                @click="saveForm"
            >
                {{ editingId ? 'Save changes' : 'Add entry' }}
            </button>
            <button
                class="rounded-lg border border-border px-4 py-2 text-sm text-muted-foreground"
                @click="closeForm"
            >
                Cancel
            </button>
        </div>
    </div>
    </Transition>

    <div
        v-if="!open.length && !settledCount && !showNewForm"
        class="mt-6 rounded-xl border border-dashed border-border p-8 text-center text-sm text-muted-foreground"
    >
        Nothing yet — tap + or type "arkar ဆီက 1 သိန်း ရစရာရှိတယ်" in the Home magic box.
    </div>

    <template v-for="group in groups" :key="group.key">
        <section v-if="group.items.length" class="mt-6">
            <h2 class="text-sm font-medium text-muted-foreground">
                {{ group.label }}
            </h2>
            <ul class="mt-2 space-y-2">
                <li v-for="e in group.items" :key="e.id">
                    <SwipeRow @swipe-right="toggle(e)" @swipe-left="remove(e)">
                        <div
                            class="flex items-center gap-3 rounded-xl border border-border bg-card p-3"
                            :class="{ 'opacity-50': e.status !== 'open' }"
                        >
                            <button
                                class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg border"
                                :class="
                                    e.status === 'open'
                                        ? 'border-border text-muted-foreground'
                                        : 'border-green-500/40 bg-green-500/10 text-green-500'
                                "
                                @click="toggle(e)"
                            >
                                <RotateCcw v-if="e.status !== 'open'" class="h-4 w-4" />
                                <Check v-else class="h-4 w-4" />
                            </button>
                            <div class="min-w-0 flex-1">
                                <div class="flex items-baseline justify-between gap-2">
                                    <p
                                        class="truncate text-sm font-medium"
                                        :class="{ 'line-through': e.status === 'paid' }"
                                    >
                                        {{ e.title }}
                                    </p>
                                    <p
                                        class="shrink-0 text-sm font-bold tabular-nums"
                                        :class="
                                            e.status !== 'open'
                                                ? 'text-muted-foreground'
                                                : e.direction === 'payable'
                                                  ? 'text-rose-400'
                                                  : 'text-green-500'
                                        "
                                    >
                                        {{ e.amount_mmk.toLocaleString() }}
                                    </p>
                                </div>
                                <p class="text-xs text-muted-foreground">
                                    {{ e.direction === 'payable' ? '💸 to pay' : '💰 incoming' }}
                                    <span v-if="e.contact && e.contact.name !== e.title">
                                        · {{ e.contact.name }}
                                    </span>
                                    <span v-if="e.due_date"> · due {{ formatDate(e.due_date) }}</span>
                                    <span v-if="e.status !== 'open'"> · {{ e.status }}</span>
                                </p>
                                <p v-if="e.note" class="truncate text-xs text-muted-foreground/70">
                                    {{ e.note }}
                                </p>
                            </div>
                            <button class="shrink-0 p-2 text-muted-foreground/60" @click="startEdit(e)">
                                <Pencil class="h-4 w-4" />
                            </button>
                            <button class="shrink-0 p-2 text-muted-foreground/60" @click="remove(e)">
                                <Trash2 class="h-4 w-4" />
                            </button>
                        </div>
                    </SwipeRow>
                </li>
            </ul>
            <button
                v-if="group.key === 'settled' && settledCount > settled.length"
                class="mt-2 w-full rounded-xl border border-dashed border-border py-2 text-xs text-muted-foreground"
                @click="showAllSettled"
            >
                Show all {{ settledCount }} settled entries
            </button>
        </section>
    </template>
</template>
