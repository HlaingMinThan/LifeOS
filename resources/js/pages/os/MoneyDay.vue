<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { ArrowLeft, Check, ImagePlus, Pencil, Plus, RotateCcw, Trash2 } from 'lucide-vue-next';
import { computed, reactive, ref } from 'vue';
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

const heading = computed(() =>
    new Date(props.date).toLocaleDateString('en-GB', {
        weekday: 'short',
        day: 'numeric',
        month: 'short',
        year: 'numeric',
    }),
);

// Settled entries = Income & Expense
const incomeEntries = computed(() =>
    props.entries.filter((e) => e.direction === 'receivable' && e.status === 'paid'),
);
const expenseEntries = computed(() =>
    props.entries.filter((e) => e.direction === 'payable' && e.status === 'paid'),
);

// Open entries = Receivable & Payable (not yet settled)
const receivableEntries = computed(() =>
    props.entries.filter((e) => e.direction === 'receivable' && e.status === 'open'),
);
const payableEntries = computed(() =>
    props.entries.filter((e) => e.direction === 'payable' && e.status === 'open'),
);

const totalIncome = computed(() =>
    incomeEntries.value.reduce((s, e) => s + e.amount_mmk, 0),
);
const totalExpense = computed(() =>
    expenseEntries.value.reduce((s, e) => s + e.amount_mmk, 0),
);
const totalReceivable = computed(() =>
    receivableEntries.value.reduce((s, e) => s + e.amount_mmk, 0),
);
const totalPayable = computed(() =>
    payableEntries.value.reduce((s, e) => s + e.amount_mmk, 0),
);
const netProfit = computed(() => totalIncome.value - totalExpense.value);

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

// --- Create form ---
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
    if (imageFile.value) {
        data.append('image', imageFile.value);
    }

    const opts = { preserveScroll: true, onSuccess: closeForm, forceFormData: true };

    if (editingId.value) {
        data.append('_method', 'PATCH');
        router.post(`/ledger/${editingId.value}`, data, opts);
    } else {
        router.post('/ledger', data, opts);
    }
}

// Lightbox for viewing images
const lightboxSrc = ref<string | null>(null);
</script>

<template>
    <Head :title="heading" />

    <div class="flex items-start justify-between">
        <div>
            <Link
                href="/money"
                class="mb-2 flex items-center gap-1 text-xs text-muted-foreground"
            >
                <ArrowLeft class="h-3.5 w-3.5" /> Calendar
            </Link>
            <h1 class="text-2xl font-bold text-gradient-brand">{{ heading }}</h1>
            <p class="mt-1 text-sm text-muted-foreground">
                Swipe right = mark done · swipe left = delete.
            </p>
        </div>
        <button
            class="mt-1 flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-gradient-brand text-white shadow-md shadow-fuchsia-500/20 transition-transform active:scale-95"
            @click="startNew()"
        >
            <Plus class="h-5 w-5" />
        </button>
    </div>

    <!-- Settled summary: Income / Expense / Net Profit -->
    <div v-if="incomeEntries.length || expenseEntries.length" class="mt-4 grid grid-cols-3 gap-2">
        <div class="rounded-xl border border-border bg-card p-3 text-center">
            <p class="text-xs text-muted-foreground">Income</p>
            <p class="mt-1 text-base font-bold tabular-nums text-green-500">
                {{ totalIncome.toLocaleString() }}
            </p>
        </div>
        <div class="rounded-xl border border-border bg-card p-3 text-center">
            <p class="text-xs text-muted-foreground">Expense</p>
            <p class="mt-1 text-base font-bold tabular-nums text-rose-400">
                {{ totalExpense.toLocaleString() }}
            </p>
        </div>
        <div class="rounded-xl border border-border bg-card p-3 text-center">
            <p class="text-xs text-muted-foreground">Net Profit</p>
            <p
                class="mt-1 text-base font-bold tabular-nums"
                :class="netProfit >= 0 ? 'text-green-500' : 'text-rose-400'"
            >
                {{ (netProfit >= 0 ? '+' : '') + netProfit.toLocaleString() }}
            </p>
        </div>
    </div>

    <!-- Open summary: Receivable / Payable -->
    <div v-if="receivableEntries.length || payableEntries.length" class="mt-2 grid grid-cols-2 gap-2">
        <div class="rounded-xl border border-border bg-card p-3 text-center">
            <p class="text-xs text-muted-foreground">Receivable</p>
            <p class="mt-1 text-base font-bold tabular-nums text-blue-400">
                {{ totalReceivable.toLocaleString() }}
            </p>
        </div>
        <div class="rounded-xl border border-border bg-card p-3 text-center">
            <p class="text-xs text-muted-foreground">Payable</p>
            <p class="mt-1 text-base font-bold tabular-nums text-orange-400">
                {{ totalPayable.toLocaleString() }}
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
                Expense
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
        <textarea
            v-model="form.note"
            rows="2"
            placeholder="Note... (optional)"
            class="w-full rounded-lg border border-input bg-background px-2 py-1.5 text-sm outline-none focus:ring-2 focus:ring-ring"
        ></textarea>

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

    <div
        v-if="!entries.length && !showNewForm"
        class="mt-6 rounded-xl border border-dashed border-border p-8 text-center text-sm text-muted-foreground"
    >
        No activity on this day — tap + to add one.
    </div>

    <!-- Receivable (open, expected incoming) -->
    <section v-if="receivableEntries.length" class="mt-6">
        <h2 class="text-sm font-medium text-blue-400">Receivable</h2>
        <ul class="mt-2 space-y-2">
            <li v-for="e in receivableEntries" :key="e.id">
                <SwipeRow @swipe-right="toggle(e)" @swipe-left="remove(e)">
                    <div class="flex items-center gap-3 rounded-xl border border-border bg-card p-3">
                        <button
                            class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg border border-border text-muted-foreground"
                            @click="toggle(e)"
                        >
                            <Check class="h-4 w-4" />
                        </button>
                        <div class="min-w-0 flex-1">
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
                        </div>
                        <button class="shrink-0 p-2 text-muted-foreground/60" @click="startEdit(e)">
                            <Pencil class="h-4 w-4" />
                        </button>
                    </div>
                </SwipeRow>
            </li>
        </ul>
    </section>

    <!-- Payable (open, to pay) -->
    <section v-if="payableEntries.length" class="mt-6">
        <h2 class="text-sm font-medium text-orange-400">Payable</h2>
        <ul class="mt-2 space-y-2">
            <li v-for="e in payableEntries" :key="e.id">
                <SwipeRow @swipe-right="toggle(e)" @swipe-left="remove(e)">
                    <div class="flex items-center gap-3 rounded-xl border border-border bg-card p-3">
                        <button
                            class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg border border-border text-muted-foreground"
                            @click="toggle(e)"
                        >
                            <Check class="h-4 w-4" />
                        </button>
                        <div class="min-w-0 flex-1">
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
                        </div>
                        <button class="shrink-0 p-2 text-muted-foreground/60" @click="startEdit(e)">
                            <Pencil class="h-4 w-4" />
                        </button>
                    </div>
                </SwipeRow>
            </li>
        </ul>
    </section>

    <!-- Income (settled receivable) — sorted by time -->
    <section v-if="incomeEntries.length" class="mt-6">
        <h2 class="text-sm font-medium text-green-500">Income</h2>
        <ul class="mt-2 space-y-2">
            <li v-for="e in incomeEntries" :key="e.id">
                <SwipeRow @swipe-right="toggle(e)" @swipe-left="remove(e)">
                    <div class="flex items-center gap-3 rounded-xl border border-border bg-card p-3 opacity-75">
                        <button
                            class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg border border-green-500/40 bg-green-500/10 text-green-500"
                            @click="toggle(e)"
                        >
                            <RotateCcw class="h-4 w-4" />
                        </button>
                        <div class="min-w-0 flex-1">
                            <div class="flex items-baseline justify-between gap-2">
                                <p class="truncate text-sm font-medium">
                                    <span class="line-through">{{ e.title }}</span>
                                    <span v-if="e.note" class="font-normal text-muted-foreground"> · {{ e.note }}</span>
                                </p>
                                <p class="shrink-0 text-sm font-bold tabular-nums text-green-500">
                                    +{{ e.amount_mmk.toLocaleString() }}
                                </p>
                            </div>
                            <p v-if="fmtTime(e.paid_at) || (e.contact && e.contact.name !== e.title)" class="truncate text-xs text-muted-foreground">
                                <span v-if="fmtTime(e.paid_at)">{{ fmtTime(e.paid_at) }}</span>
                                <template v-if="e.contact && e.contact.name !== e.title">
                                    <span v-if="fmtTime(e.paid_at)"> · </span>{{ e.contact.name }}
                                </template>
                            </p>
                        </div>
                        <img
                            v-if="e.image"
                            :src="`/storage/${e.image}`"
                            class="h-10 w-10 shrink-0 cursor-pointer rounded-lg border border-border object-cover"
                            @click="lightboxSrc = `/storage/${e.image}`"
                        />
                        <button class="shrink-0 p-2 text-muted-foreground/60" @click="startEdit(e)">
                            <Pencil class="h-4 w-4" />
                        </button>
                    </div>
                </SwipeRow>
            </li>
        </ul>
    </section>

    <!-- Expense (settled payable) — sorted by time -->
    <section v-if="expenseEntries.length" class="mt-6">
        <h2 class="text-sm font-medium text-rose-400">Expense</h2>
        <ul class="mt-2 space-y-2">
            <li v-for="e in expenseEntries" :key="e.id">
                <SwipeRow @swipe-right="toggle(e)" @swipe-left="remove(e)">
                    <div class="flex items-center gap-3 rounded-xl border border-border bg-card p-3 opacity-75">
                        <button
                            class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg border border-rose-400/40 bg-rose-400/10 text-rose-400"
                            @click="toggle(e)"
                        >
                            <RotateCcw class="h-4 w-4" />
                        </button>
                        <div class="min-w-0 flex-1">
                            <div class="flex items-baseline justify-between gap-2">
                                <p class="truncate text-sm font-medium">
                                    <span class="line-through">{{ e.title }}</span>
                                    <span v-if="e.note" class="font-normal text-muted-foreground"> · {{ e.note }}</span>
                                </p>
                                <p class="shrink-0 text-sm font-bold tabular-nums text-rose-400">
                                    -{{ e.amount_mmk.toLocaleString() }}
                                </p>
                            </div>
                            <p v-if="fmtTime(e.paid_at) || (e.contact && e.contact.name !== e.title)" class="truncate text-xs text-muted-foreground">
                                <span v-if="fmtTime(e.paid_at)">{{ fmtTime(e.paid_at) }}</span>
                                <template v-if="e.contact && e.contact.name !== e.title">
                                    <span v-if="fmtTime(e.paid_at)"> · </span>{{ e.contact.name }}
                                </template>
                            </p>
                        </div>
                        <img
                            v-if="e.image"
                            :src="`/storage/${e.image}`"
                            class="h-10 w-10 shrink-0 cursor-pointer rounded-lg border border-border object-cover"
                            @click="lightboxSrc = `/storage/${e.image}`"
                        />
                        <button class="shrink-0 p-2 text-muted-foreground/60" @click="startEdit(e)">
                            <Pencil class="h-4 w-4" />
                        </button>
                    </div>
                </SwipeRow>
            </li>
        </ul>
    </section>

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
