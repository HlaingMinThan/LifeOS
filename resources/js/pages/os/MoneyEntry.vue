<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import {
    ArrowLeft,
    Check,
    ChevronRight,
    ImagePlus,
    RotateCcw,
    Save,
    Tag,
    Trash2,
} from 'lucide-vue-next';
import { computed, reactive, ref } from 'vue';
import DateTimeField from '@/components/DateTimeField.vue';
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
    category: string | null;
    image: string | null;
    created_at: string | null;
    contact?: { name: string } | null;
};

const props = defineProps<{ entry: Entry; categories: string[] }>();

const isPaid = computed(() => props.entry.status === 'paid');
const isIncome = computed(() => props.entry.direction === 'receivable');

const initial = () => ({
    direction: props.entry.direction as string,
    title: props.entry.title,
    amount_mmk: props.entry.amount_mmk as number | null,
    due_date: props.entry.due_date?.slice(0, 10) ?? '',
    note: props.entry.note ?? '',
    category: props.entry.category ?? '',
});
const form = reactive(initial());

// Update stays disabled until something actually changed, so the button
// reflects whether there is anything to save.
const dirty = computed(() => {
    const i = initial();
    return (
        form.direction !== i.direction ||
        form.title !== i.title ||
        form.amount_mmk !== i.amount_mmk ||
        form.due_date !== i.due_date ||
        form.note !== i.note ||
        form.category !== i.category
    );
});

const newImage = ref<File | null>(null);
const imagePreview = ref<string | null>(null);
const lightbox = ref(false);
const showCategoryPicker = ref(false);

function onImagePick(event: Event) {
    const file = (event.target as HTMLInputElement).files?.[0];
    if (!file) return;
    newImage.value = file;
    imagePreview.value = URL.createObjectURL(file);
}

function save() {
    const data = new FormData();
    data.append('_method', 'PATCH');
    data.append('direction', form.direction);
    data.append('title', form.title);
    data.append('amount_mmk', String(form.amount_mmk ?? ''));
    data.append('due_date', form.due_date || '');
    data.append('note', form.note || '');
    data.append('category', form.category || '');
    if (newImage.value) data.append('image', newImage.value);

    router.post(`/ledger/${props.entry.id}`, data, {
        preserveScroll: true,
        forceFormData: true,
        onSuccess: () => {
            newImage.value = null;
            imagePreview.value = null;
        },
    });
}

function pickCategory(name: string) {
    form.category = name;
    showCategoryPicker.value = false;
}

const toggle = () =>
    router.patch(`/ledger/${props.entry.id}/toggle`, {}, { preserveScroll: true });

// ?from=detail tells the server not to send us back here — this page is
// about to stop existing.
const remove = () => router.delete(`/ledger/${props.entry.id}?from=detail`);

const settledOn = computed(() => props.entry.paid_at ?? props.entry.due_date);
const backHref = computed(() =>
    settledOn.value ? `/money/day/${settledOn.value.slice(0, 10)}` : '/money',
);

const categoryHref = computed(() => {
    const name = props.entry.category ?? 'Uncategorized';
    const month = settledOn.value?.slice(0, 7);
    return `/money/category?name=${encodeURIComponent(name)}${month ? `&month=${month}` : ''}`;
});

function fmtDateTime(value: string | null): string {
    if (!value) return '';
    const d = new Date(value);
    if (isNaN(d.getTime())) return '';
    return d.toLocaleString('en-GB', {
        weekday: 'short',
        day: 'numeric',
        month: 'short',
        year: 'numeric',
        hour: 'numeric',
        minute: '2-digit',
    });
}
</script>

<template>
    <Head :title="entry.title" />

    <Link :href="backHref" class="mb-2 flex items-center gap-1 text-xs text-muted-foreground">
        <ArrowLeft class="h-3.5 w-3.5" /> Back
    </Link>

    <!-- Headline: amount, direction, status -->
    <div class="rounded-xl border border-border bg-card p-4 text-center">
        <span
            class="inline-block rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide"
            :class="isIncome ? 'bg-green-500/15 text-green-500' : 'bg-rose-400/15 text-rose-400'"
        >
            {{ isIncome ? 'Income' : 'Expense' }}
        </span>
        <p
            class="mt-2 text-3xl font-bold tabular-nums"
            :class="isIncome ? 'text-green-500' : 'text-rose-400'"
        >
            {{ isIncome ? '+' : '-' }}{{ entry.amount_mmk.toLocaleString() }}
        </p>
        <p class="mt-1 text-sm font-medium">{{ entry.title }}</p>
        <p v-if="entry.contact && entry.contact.name !== entry.title" class="text-xs text-muted-foreground">
            {{ entry.contact.name }}
        </p>

        <button
            class="mt-3 inline-flex items-center gap-1.5 rounded-full border px-3 py-1 text-xs font-medium"
            :class="
                isPaid
                    ? 'border-green-500/40 bg-green-500/10 text-green-500'
                    : 'border-amber-500/40 bg-amber-500/10 text-amber-500'
            "
            @click="toggle"
        >
            <component :is="isPaid ? Check : RotateCcw" class="h-3.5 w-3.5" />
            {{ isPaid ? 'Settled' : 'Still open' }} · tap to
            {{ isPaid ? 'reopen' : 'mark paid' }}
        </button>
    </div>

    <!-- Category -->
    <section class="mt-4">
        <h2 class="text-sm font-medium text-muted-foreground">Category</h2>
        <div class="mt-2 rounded-xl border border-border bg-card p-3">
            <div class="flex items-center gap-2">
                <Tag class="h-4 w-4 shrink-0 text-muted-foreground" />
                <input
                    v-model="form.category"
                    type="text"
                    placeholder="Uncategorized"
                    class="min-w-0 flex-1 rounded-lg border border-input bg-background px-2 py-1.5 text-sm outline-none focus:ring-2 focus:ring-ring"
                />
                <button
                    v-if="categories.length"
                    class="shrink-0 rounded-lg border border-border px-2 py-1.5 text-xs text-muted-foreground"
                    @click="showCategoryPicker = !showCategoryPicker"
                >
                    Pick
                </button>
            </div>

            <!-- Existing labels: tapping one guarantees the grouping matches -->
            <div v-if="showCategoryPicker" class="mt-2 flex flex-wrap gap-1.5">
                <button
                    v-for="c in categories"
                    :key="c"
                    class="rounded-full border px-2 py-1 text-xs"
                    :class="
                        form.category === c
                            ? 'border-primary bg-primary/10 font-medium text-primary'
                            : 'border-border text-muted-foreground'
                    "
                    @click="pickCategory(c)"
                >
                    {{ c }}
                </button>
            </div>

            <Link
                v-if="!dirty"
                :href="categoryHref"
                class="mt-2 flex items-center justify-between text-xs text-muted-foreground"
            >
                <span>See everything in {{ entry.category ?? 'Uncategorized' }}</span>
                <ChevronRight class="h-3.5 w-3.5" />
            </Link>
        </div>
    </section>

    <!-- Editable detail -->
    <section class="mt-4">
        <h2 class="text-sm font-medium text-muted-foreground">Details</h2>
        <div class="mt-2 space-y-2 rounded-xl border border-border bg-card p-3">
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
                placeholder="Note… (optional)"
                class="w-full rounded-lg border border-input bg-background px-2 py-1.5 text-sm outline-none focus:ring-2 focus:ring-ring"
            ></textarea>

            <button
                class="w-full rounded-lg bg-gradient-brand py-2 text-sm font-medium text-white disabled:opacity-40"
                :disabled="!dirty || !form.title.trim() || !form.amount_mmk"
                @click="save"
            >
                <Save class="mr-1 inline h-4 w-4" />
                {{ dirty ? 'Save changes' : 'Saved' }}
            </button>
        </div>
    </section>

    <!-- Screenshot -->
    <section class="mt-4">
        <h2 class="text-sm font-medium text-muted-foreground">Screenshot</h2>
        <div class="mt-2 rounded-xl border border-border bg-card p-3">
            <img
                v-if="imagePreview || entry.image"
                :src="imagePreview ?? `/storage/${entry.image}`"
                class="max-h-72 w-full cursor-pointer rounded-lg border border-border object-contain"
                @click="!imagePreview && (lightbox = true)"
            />
            <p v-else class="py-4 text-center text-sm text-muted-foreground">
                No screenshot attached.
            </p>

            <label
                class="mt-2 flex cursor-pointer items-center justify-center gap-1.5 rounded-lg border border-border px-3 py-1.5 text-sm text-muted-foreground"
            >
                <ImagePlus class="h-4 w-4" />
                {{ entry.image ? 'Replace' : 'Attach' }} screenshot
                <input type="file" accept="image/*" class="hidden" @change="onImagePick" />
            </label>
            <p v-if="newImage" class="mt-1 text-center text-xs text-amber-500">
                Not saved yet — tap Save changes above.
            </p>
        </div>
    </section>

    <!-- Meta -->
    <section class="mt-4">
        <h2 class="text-sm font-medium text-muted-foreground">Record</h2>
        <dl class="mt-2 space-y-1.5 rounded-xl border border-border bg-card p-3 text-xs">
            <div v-if="entry.paid_at" class="flex justify-between gap-2">
                <dt class="text-muted-foreground">Settled</dt>
                <dd class="text-right">{{ fmtDateTime(entry.paid_at) }}</dd>
            </div>
            <div v-if="entry.due_date" class="flex justify-between gap-2">
                <dt class="text-muted-foreground">Due</dt>
                <dd class="text-right">{{ fmtDateTime(entry.due_date) }}</dd>
            </div>
            <div v-if="entry.created_at" class="flex justify-between gap-2">
                <dt class="text-muted-foreground">Added</dt>
                <dd class="text-right">{{ fmtDateTime(entry.created_at) }}</dd>
            </div>
        </dl>
    </section>

    <!-- Danger -->
    <button
        class="mt-4 mb-2 flex w-full items-center justify-center gap-2 rounded-xl border border-rose-500/40 py-2.5 text-sm font-medium text-rose-400"
        @click="remove"
    >
        <Trash2 class="h-4 w-4" /> Delete this entry
    </button>

    <!-- Lightbox -->
    <Teleport to="body">
        <div
            v-if="lightbox"
            class="fixed inset-0 z-50 flex items-center justify-center bg-black/80 p-4"
            @click="lightbox = false"
        >
            <img :src="`/storage/${entry.image}`" class="max-h-[90vh] max-w-full rounded-xl" />
        </div>
    </Teleport>
</template>
