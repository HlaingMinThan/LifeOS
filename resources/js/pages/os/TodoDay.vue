<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { ArrowLeft, Check, Pencil, Plus, Trash2 } from 'lucide-vue-next';
import { computed, onMounted, reactive, ref } from 'vue';
import DateTimeField from '@/components/DateTimeField.vue';
import SwipeRow from '@/components/SwipeRow.vue';
import { formatTime } from '@/lib/format';

type Todo = {
    id: number;
    title: string;
    note: string | null;
    bucket: string;
    status: string;
    due_date: string | null;
    due_time: string | null;
};

const props = defineProps<{ date: string; todos: Todo[] }>();

const BUCKETS: Record<string, string> = {
    work: 'Work',
    personal: 'Personal',
    money_task: 'Money',
};

const isUndated = computed(() => props.date === 'undated');
const heading = computed(() =>
    isUndated.value
        ? 'No date'
        : new Date(props.date).toLocaleDateString('en-GB', {
              weekday: 'short',
              day: 'numeric',
              month: 'short',
              year: 'numeric',
          }),
);

const editingId = ref<number | null>(null);
const showNewForm = ref(false);
const form = reactive({ title: '', note: '', bucket: 'personal', due_date: '', due_time: '' });

const toggle = (t: Todo) =>
    router.patch(`/todos/${t.id}/toggle`, {}, { preserveScroll: true });
const remove = (t: Todo) =>
    router.delete(`/todos/${t.id}`, { preserveScroll: true });

function startNew() {
    editingId.value = null;
    form.title = '';
    form.note = '';
    form.bucket = 'personal';
    form.due_date = isUndated.value ? '' : props.date;
    form.due_time = '';
    showNewForm.value = true;
}

function startEdit(t: Todo) {
    showNewForm.value = false;
    editingId.value = t.id;
    form.title = t.title;
    form.note = t.note ?? '';
    form.bucket = t.bucket;
    form.due_date = t.due_date?.slice(0, 10) ?? '';
    form.due_time = t.due_time?.slice(0, 5) ?? '';
}

function closeForm() {
    editingId.value = null;
    showNewForm.value = false;
}

function saveForm() {
    const payload = {
        title: form.title,
        note: form.note || null,
        bucket: form.bucket,
        due_date: form.due_date || null,
        due_time: form.due_time || null,
    };
    const opts = { preserveScroll: true, onSuccess: closeForm };

    if (editingId.value) router.patch(`/todos/${editingId.value}`, payload, opts);
    else router.post('/todos', payload, opts);
}

const inBucket = (bucket: string) => props.todos.filter((t) => t.bucket === bucket);

// The calendar's + button lands here with ?new=1 — open the form directly.
onMounted(() => {
    if (new URLSearchParams(window.location.search).get('new')) {
        startNew();
    }
});
</script>

<template>
    <Head :title="heading" />

    <div class="flex items-start justify-between">
        <div>
            <Link
                href="/todos"
                class="mb-2 flex items-center gap-1 text-xs text-muted-foreground"
            >
                <ArrowLeft class="h-3.5 w-3.5" /> Calendar
            </Link>
            <h1 class="text-2xl font-bold text-gradient-brand">{{ heading }}</h1>
            <p class="mt-1 text-sm text-muted-foreground">
                Swipe right = done · swipe left = delete · ✎ to edit.
            </p>
        </div>
        <button
            class="mt-1 flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-gradient-brand text-white shadow-md shadow-fuchsia-500/20 transition-transform active:scale-95"
            @click="startNew"
        >
            <Plus class="h-5 w-5" />
        </button>
    </div>

    <!-- Quick create (pre-dated to this day) -->
    <Transition name="form">
    <div v-if="showNewForm" class="mt-4 space-y-2 rounded-xl border border-primary/40 bg-card p-3">
        <input
            v-model="form.title"
            type="text"
            placeholder="What needs doing?"
            class="w-full rounded-lg border border-input bg-background px-2 py-1.5 text-sm outline-none focus:ring-2 focus:ring-ring"
        />
        <textarea
            v-model="form.note"
            rows="2"
            placeholder="Details… (optional)"
            class="w-full rounded-lg border border-input bg-background px-2 py-1.5 text-sm outline-none focus:ring-2 focus:ring-ring"
        ></textarea>
        <div class="flex gap-2">
            <DateTimeField v-model="form.due_date" mode="date" class="flex-1" placeholder="Due date" />
            <DateTimeField v-model="form.due_time" mode="time" class="w-32" placeholder="Time" />
        </div>
        <div class="flex items-center gap-2">
            <select
                v-model="form.bucket"
                class="flex-1 rounded-lg border border-input bg-background px-2 py-1.5 text-sm outline-none"
            >
                <option value="work">Work</option>
                <option value="personal">Personal</option>
                <option value="money_task">Money</option>
            </select>
            <button
                class="rounded-lg bg-gradient-brand px-4 py-1.5 text-sm text-white disabled:opacity-50"
                :disabled="!form.title.trim()"
                @click="saveForm"
            >
                Add
            </button>
            <button
                class="rounded-lg border border-border px-3 py-1.5 text-sm text-muted-foreground"
                @click="closeForm"
            >
                Cancel
            </button>
        </div>
    </div>
    </Transition>

    <div
        v-if="!todos.length && !showNewForm"
        class="mt-6 rounded-xl border border-dashed border-border p-8 text-center text-sm text-muted-foreground"
    >
        Nothing here — tap + to add one for this day.
    </div>

    <template v-for="(label, bucket) in BUCKETS" :key="bucket">
        <section v-if="inBucket(bucket).length" class="mt-6">
            <h2 class="text-sm font-medium text-muted-foreground">{{ label }}</h2>
            <ul class="mt-2 space-y-2">
                <li v-for="t in inBucket(bucket)" :key="t.id">
                    <!-- Edit mode -->
                    <div
                        v-if="editingId === t.id"
                        class="space-y-2 rounded-xl border border-primary/40 bg-card p-3"
                    >
                        <input
                            v-model="form.title"
                            type="text"
                            class="w-full rounded-lg border border-input bg-background px-2 py-1.5 text-sm outline-none focus:ring-2 focus:ring-ring"
                        />
                        <textarea
                            v-model="form.note"
                            rows="2"
                            placeholder="Details…"
                            class="w-full rounded-lg border border-input bg-background px-2 py-1.5 text-sm outline-none focus:ring-2 focus:ring-ring"
                        ></textarea>
                        <div class="flex gap-2">
                            <DateTimeField v-model="form.due_date" mode="date" class="flex-1" placeholder="Due date" />
                            <DateTimeField v-model="form.due_time" mode="time" class="w-32" placeholder="Time" />
                        </div>
                        <div class="flex items-center gap-2">
                            <select
                                v-model="form.bucket"
                                class="flex-1 rounded-lg border border-input bg-background px-2 py-1.5 text-sm outline-none"
                            >
                                <option value="work">Work</option>
                                <option value="personal">Personal</option>
                                <option value="money_task">Money</option>
                            </select>
                            <button
                                class="rounded-lg bg-gradient-brand px-4 py-1.5 text-sm text-white disabled:opacity-50"
                                :disabled="!form.title.trim()"
                                @click="saveForm"
                            >
                                Save
                            </button>
                            <button
                                class="rounded-lg border border-border px-3 py-1.5 text-sm text-muted-foreground"
                                @click="closeForm"
                            >
                                Cancel
                            </button>
                        </div>
                    </div>

                    <!-- Display mode -->
                    <SwipeRow v-else @swipe-right="toggle(t)" @swipe-left="remove(t)">
                        <div
                            class="flex items-center gap-3 rounded-xl border border-border bg-card p-3"
                            :class="{ 'opacity-50': t.status === 'done' }"
                        >
                            <button
                                class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg border"
                                :class="
                                    t.status === 'done'
                                        ? 'border-green-500/40 bg-green-500/10 text-green-500'
                                        : 'border-border text-muted-foreground'
                                "
                                @click="toggle(t)"
                            >
                                <Check class="h-4 w-4" />
                            </button>
                            <div class="min-w-0 flex-1">
                                <p
                                    class="truncate text-sm font-medium"
                                    :class="{ 'line-through': t.status === 'done' }"
                                >
                                    {{ t.title }}
                                </p>
                                <p v-if="t.note" class="truncate text-xs text-muted-foreground">
                                    {{ t.note }}
                                </p>
                                <p v-if="t.due_time" class="text-xs text-muted-foreground">
                                    ⏰ {{ formatTime(t.due_time) }}
                                </p>
                            </div>
                            <button class="shrink-0 p-2 text-muted-foreground/60" @click="startEdit(t)">
                                <Pencil class="h-4 w-4" />
                            </button>
                            <button class="shrink-0 p-2 text-muted-foreground/60" @click="remove(t)">
                                <Trash2 class="h-4 w-4" />
                            </button>
                        </div>
                    </SwipeRow>
                </li>
            </ul>
        </section>
    </template>
</template>
