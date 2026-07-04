<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { Check, Loader2, Trash2 } from 'lucide-vue-next';
import { ref } from 'vue';
import { apiPost } from '@/lib/api';
import { formatMmk } from '@/lib/format';

type Parsed = {
    action: string;
    target: string | null;
    amount_mmk: number | null;
    due: string | null;
    bucket: string | null;
    confidence: number;
};
type Item = { raw_text: string; parsed: Parsed };

const ACTION_LABELS: Record<string, string> = {
    mark_paid: 'Mark paid',
    add_payable: 'Expense',
    add_receivable: 'Income',
    income_received: 'Income received',
    add_todo: 'Todo',
    complete_todo: 'Complete todo',
    add_care_task: 'Care task',
    add_idea: 'Idea',
    unknown: 'Not sure…',
};
const ACTION_OPTIONS = Object.entries(ACTION_LABELS).filter(([k]) => k !== 'unknown');

const text = ref('');
const items = ref<Item[]>([]);
const state = ref<'input' | 'parsing' | 'review' | 'applying' | 'done'>('input');
const error = ref('');
const result = ref<{ applied: number; failed: string[] } | null>(null);

async function parseDump() {
    if (!text.value.trim()) return;
    state.value = 'parsing';
    error.value = '';
    try {
        const res = await apiPost<{ items: Item[] }>('/onboard/dump', { text: text.value });
        items.value = res.items;
        state.value = 'review';
    } catch (e) {
        error.value = (e as Error).message;
        state.value = 'input';
    }
}

function remove(index: number) {
    items.value.splice(index, 1);
}

async function confirmAll() {
    state.value = 'applying';
    error.value = '';
    try {
        const keep = items.value.filter((i) => i.parsed.action !== 'unknown');
        result.value = await apiPost<{ applied: number; failed: string[] }>(
            '/onboard/confirm',
            { items: keep },
        );
        state.value = 'done';
        setTimeout(() => router.visit('/'), 2500);
    } catch (e) {
        error.value = (e as Error).message;
        state.value = 'review';
    }
}
</script>

<template>
    <Head title="Import" />

    <h1 class="text-2xl font-semibold">Brain dump</h1>
    <p class="mt-1 text-sm text-muted-foreground">
        Paste everything — one item per line, any language. Review, then confirm.
    </p>

    <p v-if="error" class="mt-3 text-sm text-red-500">{{ error }}</p>

    <template v-if="state === 'input' || state === 'parsing'">
        <textarea
            v-model="text"
            rows="12"
            placeholder="ဂွန်ခေါင်ကို 5 သိန်း ပေးရမယ်&#10;arkar ဆီက 1 သိန်း ရစရာ&#10;fb page video content&#10;mushroom idea&#10;…"
            class="mt-4 w-full rounded-xl border border-input bg-card p-4 text-sm shadow-sm outline-none placeholder:text-muted-foreground focus:ring-2 focus:ring-ring"
        ></textarea>
        <button
            class="mt-3 flex h-12 w-full items-center justify-center gap-2 rounded-xl bg-primary font-medium text-primary-foreground disabled:opacity-50"
            :disabled="!text.trim() || state === 'parsing'"
            @click="parseDump"
        >
            <Loader2 v-if="state === 'parsing'" class="h-4 w-4 animate-spin" />
            {{ state === 'parsing' ? 'Parsing each line…' : 'Parse it' }}
        </button>
        <p v-if="state === 'parsing'" class="mt-2 text-center text-xs text-muted-foreground">
            Each line goes through Claude — a long list takes a minute.
        </p>
    </template>

    <template v-if="state === 'review' || state === 'applying'">
        <p class="mt-4 text-sm text-muted-foreground">
            {{ items.length }} items — fix anything that's wrong, remove what you don't want.
        </p>
        <ul class="mt-3 space-y-2">
            <li
                v-for="(item, i) in items"
                :key="i"
                class="rounded-xl border p-3"
                :class="item.parsed.action === 'unknown' ? 'border-amber-500/40' : 'border-border bg-card'"
            >
                <p class="truncate text-xs text-muted-foreground">{{ item.raw_text }}</p>
                <div class="mt-2 flex items-center gap-2">
                    <select
                        v-model="item.parsed.action"
                        class="rounded-lg border border-input bg-card px-2 py-1 text-xs outline-none"
                    >
                        <option v-if="item.parsed.action === 'unknown'" value="unknown" disabled>
                            Not sure…
                        </option>
                        <option v-for="[value, label] in ACTION_OPTIONS" :key="value" :value="value">
                            {{ label }}
                        </option>
                    </select>
                    <input
                        v-model="item.parsed.target"
                        type="text"
                        class="min-w-0 flex-1 rounded-lg border border-input bg-card px-2 py-1 text-xs outline-none"
                    />
                    <span v-if="item.parsed.amount_mmk" class="shrink-0 text-xs text-muted-foreground">
                        {{ formatMmk(item.parsed.amount_mmk) }}
                    </span>
                    <button class="shrink-0 p-1 text-muted-foreground/60" @click="remove(i)">
                        <Trash2 class="h-3.5 w-3.5" />
                    </button>
                </div>
            </li>
        </ul>
        <button
            class="mt-4 flex h-12 w-full items-center justify-center gap-2 rounded-xl bg-primary font-medium text-primary-foreground disabled:opacity-50"
            :disabled="!items.length || state === 'applying'"
            @click="confirmAll"
        >
            <Loader2 v-if="state === 'applying'" class="h-4 w-4 animate-spin" />
            <Check v-else class="h-4 w-4" />
            Confirm {{ items.filter((i) => i.parsed.action !== 'unknown').length }} items
        </button>
        <p class="mt-2 text-center text-xs text-muted-foreground">
            "Not sure…" rows are skipped unless you pick an action for them.
        </p>
    </template>

    <div v-if="state === 'done' && result" class="mt-6 rounded-xl border border-green-500/30 bg-green-500/5 p-4 text-sm">
        <p class="text-green-600 dark:text-green-400">✓ {{ result.applied }} items imported</p>
        <p v-if="result.failed.length" class="mt-2 text-amber-500">
            Skipped: {{ result.failed.join(' · ') }}
        </p>
        <p class="mt-2 text-muted-foreground">Taking you home…</p>
    </div>
</template>
