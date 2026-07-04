<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { Heart, Pause, Pencil, Play, Plus, Trash2 } from 'lucide-vue-next';
import { reactive, ref } from 'vue';
import { formatTime } from '@/lib/format';

type CareTask = {
    id: number;
    title: string;
    schedule_type: string;
    time_of_day: string | null;
    weekday: number | null;
    random_min_days: number | null;
    random_max_days: number | null;
    next_run_at: string | null;
    active: boolean;
};

defineProps<{ tasks: CareTask[] }>();

const SCHEDULE_LABELS: Record<string, string> = {
    daily: 'Daily',
    weekly: 'Weekly',
    random: 'Surprise 🎲',
};
const WEEKDAYS = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];

const showForm = ref(false);
const editingId = ref<number | null>(null);
const form = reactive({
    title: '',
    schedule_type: 'weekly',
    time_of_day: '09:00',
    weekday: 1,
    random_min_days: 7,
    random_max_days: 20,
});

function openNew() {
    editingId.value = null;
    form.title = '';
    form.schedule_type = 'weekly';
    form.time_of_day = '09:00';
    form.weekday = 1;
    form.random_min_days = 7;
    form.random_max_days = 20;
    showForm.value = true;
}

function openEdit(t: CareTask) {
    editingId.value = t.id;
    form.title = t.title;
    form.schedule_type = t.schedule_type;
    form.time_of_day = t.time_of_day?.slice(0, 5) ?? '09:00';
    form.weekday = t.weekday ?? 1;
    form.random_min_days = t.random_min_days ?? 7;
    form.random_max_days = t.random_max_days ?? 20;
    showForm.value = true;
}

function save() {
    const payload = {
        title: form.title,
        schedule_type: form.schedule_type,
        time_of_day: form.time_of_day,
        weekday: form.schedule_type === 'weekly' ? form.weekday : null,
        random_min_days: form.schedule_type === 'random' ? form.random_min_days : null,
        random_max_days: form.schedule_type === 'random' ? form.random_max_days : null,
    };
    const opts = { preserveScroll: true, onSuccess: () => (showForm.value = false) };

    if (editingId.value) router.patch(`/care/${editingId.value}`, payload, opts);
    else router.post('/care', payload, opts);
}

const toggleActive = (t: CareTask) =>
    router.patch(`/care/${t.id}/toggle`, {}, { preserveScroll: true });
const remove = (t: CareTask) =>
    router.delete(`/care/${t.id}`, { preserveScroll: true });

function nextRun(t: CareTask): string {
    if (!t.next_run_at) return '';
    return new Date(t.next_run_at).toLocaleDateString('en-GB', {
        weekday: 'short',
        day: 'numeric',
        month: 'short',
    });
}

function scheduleSummary(t: CareTask): string {
    const time = t.time_of_day ? ` ${formatTime(t.time_of_day)}` : '';
    if (t.schedule_type === 'daily') return `Daily${time}`;
    if (t.schedule_type === 'weekly') return `${WEEKDAYS[t.weekday ?? 1]}${time}`;
    return `Every ${t.random_min_days}–${t.random_max_days} days 🎲`;
}
</script>

<template>
    <Head title="Care" />

    <div class="flex items-start justify-between">
        <div>
            <h1 class="text-2xl font-bold text-gradient-brand">Care</h1>
            <p class="mt-1 text-sm text-muted-foreground">
                Recurring care tasks and surprises.
            </p>
        </div>
        <button
            class="mt-1 flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-gradient-brand text-white shadow-md shadow-fuchsia-500/20 transition-transform active:scale-95"
            @click="openNew"
        >
            <Plus class="h-5 w-5" />
        </button>
    </div>

    <!-- Add / edit form -->
    <Transition name="form">
    <div v-if="showForm" class="mt-4 space-y-2 rounded-xl border border-primary/40 bg-card p-4">
        <input
            v-model="form.title"
            type="text"
            placeholder="e.g. Send flowers to Kaly lay"
            class="w-full rounded-lg border border-input bg-background px-3 py-2 text-sm outline-none focus:ring-2 focus:ring-ring"
        />
        <div class="flex gap-2">
            <select
                v-model="form.schedule_type"
                class="flex-1 rounded-lg border border-input bg-background px-2 py-2 text-sm outline-none"
            >
                <option value="daily">Daily</option>
                <option value="weekly">Weekly</option>
                <option value="random">Surprise (random) 🎲</option>
            </select>
            <input
                v-model="form.time_of_day"
                type="time"
                class="w-28 rounded-lg border border-input bg-background px-2 py-2 text-sm outline-none"
            />
        </div>
        <select
            v-if="form.schedule_type === 'weekly'"
            v-model.number="form.weekday"
            class="w-full rounded-lg border border-input bg-background px-2 py-2 text-sm outline-none"
        >
            <option v-for="(day, i) in WEEKDAYS" :key="i" :value="i">{{ day }}</option>
        </select>
        <div v-if="form.schedule_type === 'random'" class="flex items-center gap-2 text-sm">
            <span class="text-muted-foreground">Every</span>
            <input
                v-model.number="form.random_min_days"
                type="number"
                min="1"
                max="90"
                class="w-16 rounded-lg border border-input bg-background px-2 py-2 text-sm outline-none"
            />
            <span class="text-muted-foreground">to</span>
            <input
                v-model.number="form.random_max_days"
                type="number"
                min="1"
                max="90"
                class="w-16 rounded-lg border border-input bg-background px-2 py-2 text-sm outline-none"
            />
            <span class="text-muted-foreground">days — unpredictable on purpose</span>
        </div>
        <div class="flex gap-2 pt-1">
            <button
                class="flex-1 rounded-lg bg-gradient-brand py-2 text-sm font-medium text-white disabled:opacity-50"
                :disabled="!form.title.trim()"
                @click="save"
            >
                {{ editingId ? 'Save changes' : 'Add care task' }}
            </button>
            <button
                class="rounded-lg border border-border px-4 py-2 text-sm text-muted-foreground"
                @click="showForm = false"
            >
                Cancel
            </button>
        </div>
    </div>
    </Transition>

    <div
        v-if="!tasks.length && !showForm"
        class="mt-6 rounded-xl border border-dashed border-border p-8 text-center text-sm text-muted-foreground"
    >
        Nothing yet — tap + to add one, or type "သောကြာနေ့ ပန်းစည်း ပို့ရန်" in the magic box.
    </div>

    <ul v-else class="mt-6 space-y-2">
        <li
            v-for="t in tasks"
            :key="t.id"
            class="flex items-center gap-3 rounded-xl border border-border bg-card p-3"
            :class="{ 'opacity-50': !t.active }"
        >
            <Heart class="h-4 w-4 shrink-0 text-rose-400" />
            <div class="min-w-0 flex-1">
                <p class="truncate text-sm font-medium">{{ t.title }}</p>
                <p class="text-xs text-muted-foreground">
                    {{ scheduleSummary(t) }}
                    <span v-if="t.active && t.next_run_at"> · next {{ nextRun(t) }}</span>
                    <span v-if="!t.active"> · paused</span>
                </p>
            </div>
            <button class="shrink-0 p-2 text-muted-foreground/60" @click="toggleActive(t)">
                <Play v-if="!t.active" class="h-4 w-4" />
                <Pause v-else class="h-4 w-4" />
            </button>
            <button class="shrink-0 p-2 text-muted-foreground/60" @click="openEdit(t)">
                <Pencil class="h-4 w-4" />
            </button>
            <button class="shrink-0 p-2 text-muted-foreground/60" @click="remove(t)">
                <Trash2 class="h-4 w-4" />
            </button>
        </li>
    </ul>
</template>
