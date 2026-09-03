<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { ArrowLeft, Check, ImagePlus, Pencil, Plus, RotateCcw, Trash2 } from 'lucide-vue-next';
import { computed, nextTick, reactive, ref } from 'vue';
import DateTimeField from '@/components/DateTimeField.vue';
import SwipeRow from '@/components/SwipeRow.vue';
import { formatMmk } from '@/lib/format';

type Entry = {
    id: number;
    direction: 'payable' | 'receivable';
    title: string;
    amount_mmk: number;
    status: string;
    due_date: string | null;
    paid_at: string | null;
    note: string | null;
    image: string | null;
    contact?: { name: string } | null;
};

const props = defineProps<{ date: string; entries: Entry[] }>();

// --- Tab ---
const activeTab = ref<'transactions' | 'open'>('transactions');

const heading = computed(() =>
    new Date(props.date).toLocaleDateString('en-GB', {
        weekday: 'short',
        day: 'numeric',
        month: 'short',
        year: 'numeric',
    }),
);

// Settled = transactions (Income & Expense)
const incomeEntries = computed(() =>
    props.entries.filter((e) => e.direction === 'receivable' && e.status === 'paid'),
);
const expenseEntries = computed(() =>
    props.entries.filter((e) => e.direction === 'payable' && e.status === 'paid'),
);
// Merge and sort by time for a chronological timeline
const transactionEntries = computed(() =>
    [...incomeEntries.value, ...expenseEntries.value].sort((a, b) => {
        if (!a.paid_at && !b.paid_at) return 0;
        if (!a.paid_at) return 1;
        if (!b.paid_at) return -1;
        return new Date(a.paid_at).getTime() - new Date(b.paid_at).getTime();
    }),
);

// Open = Receivable & Payable
const receivableEntries = computed(() =>
    props.entries.filter((e) => e.direction === 'receivable' && e.status === 'open'),
);
const payableEntries = computed(() =>
    props.entries.filter((e) => e.direction === 'payable' && e.status === 'open'),
);

const totalIncome = computed(() => incomeEntries.value.reduce((s, e) => s + e.amount_mmk, 0));
const totalExpense = computed(() => expenseEntries.value.reduce((s, e) => s + e.amount_mmk, 0));
const totalReceivable = computed(() => receivableEntries.value.reduce((s, e) => s + e.amount_mmk, 0));
const totalPayable = computed(() => payableEntries.value.reduce((s, e) => s + e.amount_mmk, 0));
const netProfit = computed(() => totalIncome.value - totalExpense.value);

const openCount = computed(() => receivableEntries.value.length + payableEntries.value.length);
const settledCount = computed(() => transactionEntries.value.length);

const toggle = (e: Entry) =>
    router.patch(`/ledger/${e.id}/toggle`, {}, { preserveScroll: true });
const remove = (e: Entry) =>
    router.delete(`/ledger/${e.id}`, { preserveScroll: true });

function fmtTime(paidAt: string | null): string {
    if (!paidAt) return '';
    const d = new Date(paidAt);
    if (isNaN(d.getTime())) return '';
    return d.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit', hour12: true });
}

// --- Create / edit form ---
const showNewForm = ref(false);
const editingId = ref<number | null>(null);
const imageFile = ref<File | null>(null);
const imagePreview = ref<string | null>(null);
const form = reactive({
    direction: 'payable' as string,
    title: '',
    amount_mmk: null as number | null,
    due_date: '',
    note: '',
});

function startNew(direction: string = 'payable') {
    editingId.value = null;
    form.direction = direction;
    form.title = '';
    form.amount_mmk = null;
    form.due_date = props.date;
    form.note = '';
    imageFile.value = null;
    imagePreview.value = null;
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
    imageFile.value = null;
    imagePreview.value = e.image ? `/storage/${e.image}` : null;
    nextTick(() => window.scrollTo({ top: document.body.scrollHeight, behavior: 'smooth' }));
}

function closeForm() {
    editingId.value = null;
    showNewForm.value = false;
    imageFile.value = null;
    imagePreview.value = null;
}

function onImagePick(event: Event) {
    const file = (event.target as HTMLInputElement).files?.[0];
    if (!file) return;
    imageFile.value = file;
    imagePreview.value = URL.createObjectURL(file);
}

function saveForm() {
    const data = new FormData();
    data.append('direction', form.direction);
    data.append('title', form.title);
    data.append('amount_mmk', String(form.amount_mmk ?? ''));
    data.append('due_date', form.due_date || '');
    data.append('note', form.note || '');
    if (imageFile.value) data.append('image', imageFile.value);

    const opts = { preserveScroll: true, onSuccess: closeForm, forceFormData: true };

    if (editingId.value) {
        data.append('_method', 'PATCH');
        router.post(`/ledger/${editingId.value}`, data, opts);
    } else {
        router.post('/ledger', data, opts);
    }
}

const lightboxSrc = ref<string | null>(null);
</script>

<template>
    <Head :title="heading" />

    <!-- Header -->
    <div class="flex items-start justify-between">
        <div>
            <Link href="/money" class="mb-2 flex items-center gap-1 text-xs text-muted-foreground">
                <ArrowLeft class="h-3.5 w-3.5" /> Calendar
            </Link>
            <h1 class="text-2xl font-bold text-gradient-brand">{{ heading }}</h1>
        </div>
        <button
            class="mt-1 flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-gradient-brand text-white shadow-md shadow-fuchsia-500/20 transition-transform active:scale-95"
            @click="startNew()"
        >
            <Plus class="h-5 w-5" />
        </button>
    </div>

    <!-- Tabs -->
    <div class="mt-4 flex rounded-xl border border-border bg-card p-1">
        <button
            class="flex-1 rounded-lg py-2 text-center text-sm font-medium transition-colors"
            :class="activeTab === 'transactions'
                ? 'bg-gradient-brand text-white shadow-sm'
                : 'text-muted-foreground hover:text-foreground'"
            @click="activeTab = 'transactions'"
        >
            Transactions
            <span v-if="settledCount" class="ml-1 text-xs opacity-75">({{ settledCount }})</span>
        </button>
        <button
            class="flex-1 rounded-lg py-2 text-center text-sm font-medium transition-colors"
            :class="activeTab === 'open'
                ? 'bg-gradient-brand text-white shadow-sm'
                : 'text-muted-foreground hover:text-foreground'"
            @click="activeTab = 'open'"
        >
            Open
            <span v-if="openCount" class="ml-1 text-xs opacity-75">({{ openCount }})</span>
        </button>
    </div>

    <!-- ===== TRANSACTIONS TAB ===== -->
    <template v-if="activeTab === 'transactions'">
        <!-- Summary cards -->
        <div class="mt-4 grid grid-cols-3 gap-2">
            <div class="rounded-xl border border-border bg-card p-3 text-center">
                <p class="text-[10px] uppercase tracking-wide text-muted-foreground">Income</p>
                <p class="mt-0.5 text-lg font-bold tabular-nums text-green-500">
                    {{ totalIncome ? totalIncome.toLocaleString() : '-' }}
                </p>
            </div>
            <div class="rounded-xl border border-border bg-card p-3 text-center">
                <p class="text-[10px] uppercase tracking-wide text-muted-foreground">Expense</p>
                <p class="mt-0.5 text-lg font-bold tabular-nums text-rose-400">
                    {{ totalExpense ? totalExpense.toLocaleString() : '-' }}
                </p>
            </div>
            <div class="rounded-xl border border-border bg-card p-3 text-center">
                <p class="text-[10px] uppercase tracking-wide text-muted-foreground">Net</p>
                <p
                    class="mt-0.5 text-lg font-bold tabular-nums"
                    :class="netProfit >= 0 ? 'text-green-500' : 'text-rose-400'"
                >
                    {{ settledCount ? (netProfit >= 0 ? '+' : '') + netProfit.toLocaleString() : '-' }}
                </p>
            </div>
        </div>

        <!-- Timeline list -->
        <ul v-if="transactionEntries.length" class="mt-4 space-y-2">
            <li v-for="e in transactionEntries" :key="e.id">
                <SwipeRow @swipe-right="toggle(e)" @swipe-left="remove(e)">
                    <div class="flex items-center gap-3 rounded-xl border border-border bg-card p-3">
                        <!-- Time badge -->
                        <div class="flex w-14 shrink-0 flex-col items-center">
                            <span class="text-xs font-medium tabular-nums text-muted-foreground">
                                {{ fmtTime(e.paid_at) || '--:--' }}
                            </span>
                            <span
                                class="mt-0.5 rounded-full px-1.5 py-0.5 text-[10px] font-semibold uppercase"
                                :class="e.direction === 'receivable'
                                    ? 'bg-green-500/15 text-green-500'
                                    : 'bg-rose-400/15 text-rose-400'"
                            >
                                {{ e.direction === 'receivable' ? 'IN' : 'OUT' }}
                            </span>
                        </div>

                        <!-- Details -->
                        <Link :href="`/ledger/${e.id}`" class="min-w-0 flex-1">
                            <div class="flex items-baseline justify-between gap-2">
                                <p class="truncate text-sm font-medium">
                                    {{ e.title }}
                                    <span v-if="e.note" class="font-normal text-muted-foreground"> · {{ e.note }}</span>
                                </p>
                                <p
                                    class="shrink-0 text-sm font-bold tabular-nums"
                                    :class="e.direction === 'receivable' ? 'text-green-500' : 'text-rose-400'"
                                >
                                    {{ e.direction === 'receivable' ? '+' : '-' }}{{ e.amount_mmk.toLocaleString() }}
                                </p>
                            </div>
                            <p v-if="e.contact && e.contact.name !== e.title" class="text-xs text-muted-foreground">
                                {{ e.contact.name }}
                            </p>
                        </Link>

                        <!-- Screenshot thumb -->
                        <img
                            v-if="e.image"
                            :src="`/storage/${e.image}`"
                            class="h-10 w-10 shrink-0 cursor-pointer rounded-lg border border-border object-cover"
                            @click.stop="lightboxSrc = `/storage/${e.image}`"
                        />

                        <button class="shrink-0 p-1 text-muted-foreground/50" @click="startEdit(e)">
                            <Pencil class="h-3.5 w-3.5" />
                        </button>
                    </div>
                </SwipeRow>
            </li>
        </ul>

        <div
            v-else-if="!showNewForm && !editingId"
            class="mt-6 rounded-xl border border-dashed border-border p-8 text-center text-sm text-muted-foreground"
        >
            No transactions yet — upload a screenshot or tap +.
        </div>
    </template>

    <!-- ===== OPEN TAB ===== -->
    <template v-if="activeTab === 'open'">
        <!-- Summary cards -->
        <div class="mt-4 grid grid-cols-2 gap-2">
            <div class="rounded-xl border border-border bg-card p-3 text-center">
                <p class="text-[10px] uppercase tracking-wide text-muted-foreground">Receivable</p>
                <p class="mt-0.5 text-lg font-bold tabular-nums text-blue-400">
                    {{ totalReceivable ? totalReceivable.toLocaleString() : '-' }}
                </p>
            </div>
            <div class="rounded-xl border border-border bg-card p-3 text-center">
                <p class="text-[10px] uppercase tracking-wide text-muted-foreground">Payable</p>
                <p class="mt-0.5 text-lg font-bold tabular-nums text-orange-400">
                    {{ totalPayable ? totalPayable.toLocaleString() : '-' }}
                </p>
            </div>
        </div>

        <!-- Receivable -->
        <section v-if="receivableEntries.length" class="mt-4">
            <h2 class="mb-2 text-xs font-semibold uppercase tracking-wide text-blue-400">Receivable</h2>
            <ul class="space-y-2">
                <li v-for="e in receivableEntries" :key="e.id">
                    <SwipeRow @swipe-right="toggle(e)" @swipe-left="remove(e)">
                        <div class="flex items-center gap-3 rounded-xl border border-border bg-card p-3">
                            <button
                                class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg border border-blue-400/30 text-blue-400 transition-colors hover:bg-blue-400/10"
                                @click="toggle(e)"
                            >
                                <Check class="h-4 w-4" />
                            </button>
                            <Link :href="`/ledger/${e.id}`" class="min-w-0 flex-1">
                                <div class="flex items-baseline justify-between gap-2">
                                    <p class="truncate text-sm font-medium">
                                        {{ e.title }}
                                        <span v-if="e.note" class="font-normal text-muted-foreground"> · {{ e.note }}</span>
                                    </p>
                                    <p class="shrink-0 text-sm font-bold tabular-nums text-blue-400">
                                        {{ e.amount_mmk.toLocaleString() }}
                                    </p>
                                </div>
                                <p v-if="e.contact && e.contact.name !== e.title" class="text-xs text-muted-foreground">
                                    {{ e.contact.name }}
                                </p>
                            </Link>
                            <button class="shrink-0 p-1 text-muted-foreground/50" @click="startEdit(e)">
                                <Pencil class="h-3.5 w-3.5" />
                            </button>
                        </div>
                    </SwipeRow>
                </li>
            </ul>
        </section>

        <!-- Payable -->
        <section v-if="payableEntries.length" class="mt-4">
            <h2 class="mb-2 text-xs font-semibold uppercase tracking-wide text-orange-400">Payable</h2>
            <ul class="space-y-2">
                <li v-for="e in payableEntries" :key="e.id">
                    <SwipeRow @swipe-right="toggle(e)" @swipe-left="remove(e)">
                        <div class="flex items-center gap-3 rounded-xl border border-border bg-card p-3">
                            <button
                                class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg border border-orange-400/30 text-orange-400 transition-colors hover:bg-orange-400/10"
                                @click="toggle(e)"
                            >
                                <Check class="h-4 w-4" />
                            </button>
                            <Link :href="`/ledger/${e.id}`" class="min-w-0 flex-1">
                                <div class="flex items-baseline justify-between gap-2">
                                    <p class="truncate text-sm font-medium">
                                        {{ e.title }}
                                        <span v-if="e.note" class="font-normal text-muted-foreground"> · {{ e.note }}</span>
                                    </p>
                                    <p class="shrink-0 text-sm font-bold tabular-nums text-orange-400">
                                        {{ e.amount_mmk.toLocaleString() }}
                                    </p>
                                </div>
                                <p v-if="e.contact && e.contact.name !== e.title" class="text-xs text-muted-foreground">
                                    {{ e.contact.name }}
                                </p>
                            </Link>
                            <button class="shrink-0 p-1 text-muted-foreground/50" @click="startEdit(e)">
                                <Pencil class="h-3.5 w-3.5" />
                            </button>
                        </div>
                    </SwipeRow>
                </li>
            </ul>
        </section>

        <div
            v-if="!openCount && !showNewForm && !editingId"
            class="mt-6 rounded-xl border border-dashed border-border p-8 text-center text-sm text-muted-foreground"
        >
            No open items on this day.
        </div>
    </template>

    <!-- Create / edit form (always accessible) -->
    <Transition name="form">
    <div
        v-if="showNewForm || editingId"
        class="mt-4 space-y-2 rounded-xl border border-primary/40 bg-card p-3"
    >
        <div class="grid grid-cols-2 gap-2">
            <button
                class="rounded-lg border py-2 text-sm"
                :class="form.direction === 'payable'
                    ? 'border-primary bg-primary/10 font-medium text-primary'
                    : 'border-border text-muted-foreground'"
                @click="form.direction = 'payable'"
            >
                Expense
            </button>
            <button
                class="rounded-lg border py-2 text-sm"
                :class="form.direction === 'receivable'
                    ? 'border-primary bg-primary/10 font-medium text-primary'
                    : 'border-border text-muted-foreground'"
                @click="form.direction = 'receivable'"
            >
                Income
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
        <input
            v-model="form.note"
            type="text"
            placeholder="Note (optional)"
            class="w-full rounded-lg border border-input bg-background px-2 py-1.5 text-sm outline-none focus:ring-2 focus:ring-ring"
        />

        <!-- Image upload -->
        <div class="flex items-center gap-2">
            <label
                class="flex cursor-pointer items-center gap-1.5 rounded-lg border border-border px-3 py-1.5 text-sm text-muted-foreground hover:bg-muted"
            >
                <ImagePlus class="h-4 w-4" />
                Screenshot
                <input type="file" accept="image/*" class="hidden" @change="onImagePick" />
            </label>
            <span v-if="imageFile" class="truncate text-xs text-muted-foreground">{{ imageFile.name }}</span>
        </div>
        <img
            v-if="imagePreview"
            :src="imagePreview"
            class="max-h-40 rounded-lg border border-border object-cover"
        />

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

    <!-- Lightbox overlay -->
    <Teleport to="body">
        <div
            v-if="lightboxSrc"
            class="fixed inset-0 z-50 flex items-center justify-center bg-black/80 p-4"
            @click="lightboxSrc = null"
        >
            <img :src="lightboxSrc" class="max-h-[90vh] max-w-full rounded-xl" />
        </div>
    </Teleport>
</template>
