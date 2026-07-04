<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { Check, Pencil, Plus, RotateCcw, Trash2 } from 'lucide-vue-next';
import { reactive, ref } from 'vue';
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

const props = defineProps<{ entries: Entry[] }>();

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

const section = (direction: string) =>
    props.entries.filter((e) => e.direction === direction);
</script>

<template>
    <Head title="Money" />

    <div class="flex items-start justify-between">
        <div>
            <h1 class="text-2xl font-semibold">Money</h1>
            <p class="mt-1 text-sm text-muted-foreground">Payables and receivables.</p>
        </div>
        <button
            class="mt-1 flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-primary text-primary-foreground"
            @click="startNew()"
        >
            <Plus class="h-5 w-5" />
        </button>
    </div>

    <!-- Create / edit form -->
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
                I owe 💸
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
                Owed to me 💰
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
            <input
                v-model="form.due_date"
                type="date"
                class="w-40 rounded-lg border border-input bg-background px-2 py-1.5 text-sm outline-none"
            />
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
                class="flex-1 rounded-lg bg-primary py-2 text-sm font-medium text-primary-foreground disabled:opacity-50"
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

    <div
        v-if="!entries.length && !showNewForm"
        class="mt-6 rounded-xl border border-dashed border-border p-8 text-center text-sm text-muted-foreground"
    >
        Nothing yet — tap + or type "arkar ဆီက 1 သိန်း ရစရာရှိတယ်" in the Home magic box.
    </div>

    <template v-for="dir in ['payable', 'receivable']" :key="dir">
        <section v-if="section(dir).length" class="mt-6">
            <h2 class="text-sm font-medium text-muted-foreground">
                {{ dir === 'payable' ? 'You owe' : 'Owed to you' }}
            </h2>
            <ul class="mt-2 space-y-2">
                <li v-for="e in section(dir)" :key="e.id">
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
                                <p
                                    class="truncate text-sm font-medium"
                                    :class="{ 'line-through': e.status === 'paid' }"
                                >
                                    {{ e.contact?.name ?? e.title }}
                                </p>
                                <p class="text-xs text-muted-foreground">
                                    {{ formatMmk(e.amount_mmk) }}
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
        </section>
    </template>
</template>
