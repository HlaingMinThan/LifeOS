<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Check, ChevronLeft, ChevronRight, ImagePlus, Loader2, Pencil, Plus, Trash2 } from 'lucide-vue-next';
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

type DayCount = { income: number; expense: number };

const props = defineProps<{
    month: string;
    counts: Record<string, DayCount>;
    overdue: Entry[];
    thisWeek: Entry[];
    later: Entry[];
    noDate: Entry[];
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
const todayIso = new Date().toLocaleDateString('sv-SE');

function iso(day: number): string {
    return `${props.month}-${String(day).padStart(2, '0')}`;
}

function shiftMonth(delta: number) {
    const d = new Date(year.value, monthIndex.value + delta, 1);
    const target = `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`;
    router.get('/money', { month: target }, { preserveState: false });
}

const openDay = (day: number) => router.visit(`/money/day/${iso(day)}`);

// --- Entry actions ---
const editingId = ref<number | null>(null);
const showNewForm = ref(false);
const form = reactive({
    direction: 'payable' as string,
    title: '',
    amount_mmk: null as number | null,
    due_date: '',
    note: '',
});

// Screenshot upload state
const imageFile = ref<File | null>(null);
const imagePreview = ref<string | null>(null);
const storedImagePath = ref<string | null>(null);
const parsedTime = ref<string | null>(null);
const parsing = ref(false);
const parseError = ref<string | null>(null);

const toggle = (e: Entry) =>
    router.patch(`/ledger/${e.id}/toggle`, {}, { preserveScroll: true });
const remove = (e: Entry) =>
    router.delete(`/ledger/${e.id}`, { preserveScroll: true });

function startNew(direction: string = 'payable') {
    editingId.value = null;
    form.direction = direction;
    form.title = '';
    form.amount_mmk = null;
    form.due_date = '';
    form.note = '';
    imageFile.value = null;
    imagePreview.value = null;
    storedImagePath.value = null;
    parsedTime.value = null;
    parseError.value = null;
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
    imagePreview.value = null;
    storedImagePath.value = null;
    parsedTime.value = null;
    parseError.value = null;
    window.scrollTo({ top: 0, behavior: 'smooth' });
}

function closeForm() {
    editingId.value = null;
    showNewForm.value = false;
    imageFile.value = null;
    imagePreview.value = null;
    storedImagePath.value = null;
    parsedTime.value = null;
    parseError.value = null;
}

async function onScreenshotPick(event: Event) {
    const file = (event.target as HTMLInputElement).files?.[0];
    if (!file) return;

    imageFile.value = file;
    imagePreview.value = URL.createObjectURL(file);
    parseError.value = null;
    parsing.value = true;

    // Open the form if not already open
    if (!showNewForm.value && !editingId.value) {
        showNewForm.value = true;
    }

    try {
        const formData = new FormData();
        formData.append('image', file);

        const csrfToken = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content;
        const res = await fetch('/ledger/parse-screenshot', {
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': csrfToken || '', Accept: 'application/json' },
            body: formData,
        });

        const data = await res.json();

        if (!res.ok) {
            parseError.value = data.error || 'Parse failed';
            if (data.image) storedImagePath.value = data.image;
            return;
        }

        // Auto-fill the form with parsed data
        form.direction = data.direction || 'payable';
        form.title = data.title || '';
        form.amount_mmk = data.amount_mmk || null;
        form.due_date = data.due || todayIso;
        form.note = data.note || '';
        parsedTime.value = data.time || null;
        storedImagePath.value = data.image;
    } catch {
        parseError.value = 'Could not reach the server.';
    } finally {
        parsing.value = false;
    }
}

function saveForm() {
    const data = new FormData();
    data.append('direction', form.direction);
    data.append('title', form.title);
    data.append('amount_mmk', String(form.amount_mmk ?? ''));
    data.append('due_date', form.due_date || '');
    data.append('note', form.note || '');

    // If we have a stored image path from parse-screenshot, send it.
    // If the user picked a new file manually (without parsing), upload it.
    if (storedImagePath.value) {
        data.append('stored_image', storedImagePath.value);
    } else if (imageFile.value) {
        data.append('image', imageFile.value);
    }
    if (parsedTime.value) {
        data.append('paid_time', parsedTime.value);
    }

    const opts = { preserveScroll: true, onSuccess: closeForm, forceFormData: true };

    if (editingId.value) {
        data.append('_method', 'PATCH');
        router.post(`/ledger/${editingId.value}`, data, opts);
    } else {
        router.post('/ledger', data, opts);
    }
}

// Open balance summary (from all open lists combined)
const allOpen = computed(() => [...props.overdue, ...props.thisWeek, ...props.later, ...props.noDate]);
const incoming = computed(() =>
    allOpen.value.filter((e) => e.direction === 'receivable').reduce((s, e) => s + e.amount_mmk, 0),
);
const toPay = computed(() =>
    allOpen.value.filter((e) => e.direction === 'payable').reduce((s, e) => s + e.amount_mmk, 0),
);
const net = computed(() => incoming.value - toPay.value);

const groups = computed(() =>
    [
        { key: 'overdue', label: 'Overdue', items: props.overdue },
        { key: 'week', label: 'This week', items: props.thisWeek },
        { key: 'later', label: 'Later', items: props.later },
        { key: 'nodate', label: 'No date', items: props.noDate },
    ].filter((g) => g.items.length > 0),
);
</script>

<template>
    <Head title="Money" />

    <div class="flex items-start justify-between">
        <div>
            <h1 class="text-2xl font-bold text-gradient-brand">Money</h1>
            <p class="mt-1 text-sm text-muted-foreground">Tap a day to see income & expense.</p>
        </div>
        <div class="flex items-center gap-2">
            <!-- Screenshot upload button (always visible) -->
            <label
                class="mt-1 flex h-9 w-9 shrink-0 cursor-pointer items-center justify-center rounded-full border border-border text-muted-foreground transition-transform active:scale-95"
                :class="{ 'animate-pulse': parsing }"
            >
                <Loader2 v-if="parsing" class="h-5 w-5 animate-spin" />
                <ImagePlus v-else class="h-5 w-5" />
                <input type="file" accept="image/*" class="hidden" @change="onScreenshotPick" />
            </label>
            <button
                class="mt-1 flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-gradient-brand text-white shadow-md shadow-fuchsia-500/20 transition-transform active:scale-95"
                @click="startNew()"
            >
                <Plus class="h-5 w-5" />
            </button>
        </div>
    </div>

    <!-- Create / edit form (above everything) -->
    <Transition name="form">
    <div
        v-if="showNewForm || editingId"
        class="mt-4 space-y-2 rounded-xl border border-primary/40 bg-card p-3"
    >
        <!-- Screenshot preview -->
        <div v-if="imagePreview" class="relative">
            <img :src="imagePreview" class="max-h-40 w-full rounded-lg border border-border object-cover" />
            <div
                v-if="parsing"
                class="absolute inset-0 flex items-center justify-center rounded-lg bg-black/50"
            >
                <div class="flex items-center gap-2 text-sm text-white">
                    <Loader2 class="h-4 w-4 animate-spin" />
                    Reading screenshot...
                </div>
            </div>
        </div>
        <p v-if="parseError" class="text-xs text-rose-400">{{ parseError }} — fill in manually below.</p>

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

        <!-- Attach screenshot if form opened without one -->
        <div v-if="!imagePreview" class="flex items-center gap-2">
            <label
                class="flex cursor-pointer items-center gap-1.5 rounded-lg border border-border px-3 py-1.5 text-sm text-muted-foreground hover:bg-muted"
            >
                <ImagePlus class="h-4 w-4" />
                Attach screenshot
                <input type="file" accept="image/*" class="hidden" @change="onScreenshotPick" />
            </label>
        </div>

        <div class="flex gap-2">
            <button
                class="flex-1 rounded-lg bg-gradient-brand py-2 text-sm font-medium text-white disabled:opacity-50"
                :disabled="!form.title.trim() || !form.amount_mmk || parsing"
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

    <!-- Open balance summary -->
    <div v-if="allOpen.length" class="mt-4 grid grid-cols-3 gap-2">
        <div class="rounded-xl border border-border bg-card p-3 text-center">
            <p class="text-xs text-muted-foreground">Incoming</p>
            <p class="mt-1 text-base font-bold tabular-nums text-green-500">
                {{ incoming.toLocaleString() }}
            </p>
        </div>
        <div class="rounded-xl border border-border bg-card p-3 text-center">
            <p class="text-xs text-muted-foreground">To pay</p>
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
                        v-if="counts[iso(day)].income"
                        class="rounded-full px-1 font-semibold"
                        :class="iso(day) === todayIso ? 'bg-white/25 text-white' : 'bg-green-500/20 text-green-500'"
                    >
                        +{{ counts[iso(day)].income }}
                    </span>
                    <span
                        v-if="counts[iso(day)].expense"
                        class="rounded-full px-1 font-semibold"
                        :class="iso(day) === todayIso ? 'bg-white/25 text-white' : 'bg-rose-400/20 text-rose-400'"
                    >
                        -{{ counts[iso(day)].expense }}
                    </span>
                </span>
            </button>
        </div>
    </div>

    <div
        v-if="!allOpen.length && !Object.keys(counts).length && !showNewForm"
        class="mt-6 rounded-xl border border-dashed border-border p-8 text-center text-sm text-muted-foreground"
    >
        Nothing yet — tap + or upload a screenshot.
    </div>

    <!-- Open entry lists: Overdue, This Week, Later, No Date -->
    <template v-for="group in groups" :key="group.key">
        <section class="mt-6">
            <h2 class="text-sm font-medium text-muted-foreground">
                {{ group.label }}
            </h2>
            <ul class="mt-2 space-y-2">
                <li v-for="e in group.items" :key="e.id">
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
                                    <p class="truncate text-sm font-medium">{{ e.title }}</p>
                                    <p
                                        class="shrink-0 text-sm font-bold tabular-nums"
                                        :class="e.direction === 'payable' ? 'text-rose-400' : 'text-green-500'"
                                    >
                                        {{ e.amount_mmk.toLocaleString() }}
                                    </p>
                                </div>
                                <p class="text-xs text-muted-foreground">
                                    {{ e.direction === 'payable' ? 'to pay' : 'incoming' }}
                                    <span v-if="e.contact && e.contact.name !== e.title">
                                        · {{ e.contact.name }}
                                    </span>
                                    <span v-if="e.due_date"> · due {{ formatDate(e.due_date) }}</span>
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
        </section>
    </template>
</template>
