<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { Check, RotateCcw, Trash2 } from 'lucide-vue-next';
import SwipeRow from '@/components/SwipeRow.vue';
import { formatDate, formatMmk } from '@/lib/format';

type Entry = {
    id: number;
    direction: 'payable' | 'receivable';
    title: string;
    amount_mmk: number;
    status: string;
    due_date: string | null;
    contact?: { name: string } | null;
};

const props = defineProps<{ entries: Entry[] }>();

const toggle = (e: Entry) =>
    router.patch(`/ledger/${e.id}/toggle`, {}, { preserveScroll: true });
const remove = (e: Entry) =>
    router.delete(`/ledger/${e.id}`, { preserveScroll: true });

const section = (direction: string) =>
    props.entries.filter((e) => e.direction === direction);
</script>

<template>
    <Head title="Money" />

    <h1 class="text-2xl font-semibold">Money</h1>
    <p class="mt-1 text-sm text-muted-foreground">Payables and receivables.</p>

    <div
        v-if="!entries.length"
        class="mt-6 rounded-xl border border-dashed border-border p-8 text-center text-sm text-muted-foreground"
    >
        Nothing yet — type "arkar ဆီက 1 သိန်း ရစရာရှိတယ်" in the Home magic box.
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
                        <p class="truncate text-sm font-medium" :class="{ 'line-through': e.status === 'paid' }">
                            {{ e.contact?.name ?? e.title }}
                        </p>
                        <p class="text-xs text-muted-foreground">
                            {{ formatMmk(e.amount_mmk) }}
                            <span v-if="e.due_date"> · due {{ formatDate(e.due_date) }}</span>
                            <span v-if="e.status !== 'open'"> · {{ e.status }}</span>
                        </p>
                    </div>
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
