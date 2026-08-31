<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { ArrowLeft, Check, Plus, Target, Trash2 } from 'lucide-vue-next';
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
    focused: boolean;
    due_date: string | null;
    due_time: string | null;
    assigned_by?: { id: number; name: string } | null;
};

/** HTML note → plain text for the one-line row preview. */
function notePreview(html: string | null): string {
    if (!html) return '';
    return html.replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim();
}

const props = defineProps<{ date: string; todos: Todo[] }>();

const BUCKETS: Record<string, string> = {
    work: 'Work',
    personal: 'Personal',
    money_task: 'Money',
};

// Timeline order: timed (by time) → anytime → done. The left gutter shows
// each todo's own time, like a day view in other task apps.
const orderedTodos = computed(() => {
    const rank = (t: Todo) => (t.status === 'done' ? 2 : t.due_time ? 0 : 1);
    return [...props.todos].sort((a, b) => {
        const diff = rank(a) - rank(b);
        if (diff !== 0) return diff;
        return (a.due_time ?? '99:99').localeCompare(b.due_time ?? '99:99');
    });
});

const groupOf = (t?: Todo) =>
    !t ? '' : t.status === 'done' ? 'done' : t.due_time ? 'timed' : 'anytime';

/** Section label when the group changes (Anytime / Done); null otherwise. */
function sectionBreak(index: number): string | null {
    const g = groupOf(orderedTodos.value[index]);
    if (g === groupOf(orderedTodos.value[index - 1])) return null;
    if (g === 'anytime') return 'Anytime';
    if (g === 'done') return 'Done';
    return null;
}

// Special virtual "days": the dateless bucket and the overdue rollup.
const isRealDate = computed(() => /^\d{4}-\d{2}-\d{2}$/.test(props.date));
const heading = computed(() => {
    if (props.date === 'undated') return 'No date';
    if (props.date === 'overdue') return '🔴 Overdue';
    return new Date(props.date).toLocaleDateString('en-GB', {
        weekday: 'short',
        day: 'numeric',
        month: 'short',
        year: 'numeric',
    });
});

const showNewForm = ref(false);
const form = reactive({ title: '', note: '', bucket: 'personal', due_date: '', due_time: '' });

const toggle = (t: Todo) =>
    router.patch(`/todos/${t.id}/toggle`, {}, { preserveScroll: true });
const remove = (t: Todo) =>
    router.delete(`/todos/${t.id}`, { preserveScroll: true });
const focus = (t: Todo) =>
    router.patch(`/todos/${t.id}/focus`, {}, { preserveScroll: true });

function startNew() {
    form.title = '';
    form.note = '';
    form.bucket = 'personal';
    form.due_date = isRealDate.value ? props.date : '';
    form.due_time = '';
    showNewForm.value = true;
}

function closeForm() {
    showNewForm.value = false;
}

function saveForm() {
    router.post('/todos', {
        title: form.title,
        note: form.note || null,
        bucket: form.bucket,
        due_date: form.due_date || null,
        due_time: form.due_time || null,
    }, { preserveScroll: true, onSuccess: closeForm });
}

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
                Swipe right = done · swipe left = delete · tap to open.
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

    <ul v-if="todos.length" class="mt-6">
        <template v-for="(t, i) in orderedTodos" :key="t.id">
            <!-- Section break: Anytime / Done -->
            <li
                v-if="sectionBreak(i)"
                class="mb-2 mt-4 pl-16 text-xs font-medium uppercase tracking-wide text-muted-foreground"
            >
                {{ sectionBreak(i) }}
            </li>

            <li class="pb-2">
                <!-- Time gutter + swipeable card (tap body → detail) -->
                <div class="flex items-stretch gap-2">
                    <div
                        class="flex w-14 shrink-0 items-center justify-end pr-1 text-right text-xs font-semibold tabular-nums"
                        :class="t.due_time ? 'text-primary' : 'text-muted-foreground/40'"
                    >
                        {{ t.due_time ? formatTime(t.due_time) : '—' }}
                    </div>
                    <SwipeRow class="flex-1" @swipe-right="toggle(t)" @swipe-left="remove(t)">
                        <div
                            class="flex items-center gap-3 rounded-xl border bg-card p-3"
                            :class="[
                                t.status === 'done' ? 'opacity-50' : '',
                                t.focused ? 'border-primary/60' : 'border-border',
                            ]"
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
                            <Link :href="`/todos/${t.id}`" class="min-w-0 flex-1">
                                <p
                                    class="truncate text-sm font-medium"
                                    :class="{ 'line-through': t.status === 'done' }"
                                >
                                    <Target
                                        v-if="t.focused"
                                        class="mr-1 inline h-3.5 w-3.5 text-primary"
                                    />{{ t.title }}
                                </p>
                                <p v-if="notePreview(t.note)" class="truncate text-xs text-muted-foreground">
                                    {{ notePreview(t.note) }}
                                </p>
                                <span
                                    class="mt-0.5 inline-block rounded-full bg-muted px-1.5 py-0.5 text-[10px] font-medium text-muted-foreground"
                                >
                                    {{ BUCKETS[t.bucket] }}
                                </span>
                                <span
                                    v-if="t.assigned_by"
                                    class="mt-0.5 ml-1 inline-block rounded-full bg-primary/15 px-1.5 py-0.5 text-[10px] font-medium text-primary"
                                >
                                    from {{ t.assigned_by.name }}
                                </span>
                            </Link>
                            <button
                                class="shrink-0 p-2"
                                :class="t.focused ? 'text-primary' : 'text-muted-foreground/60'"
                                @click="focus(t)"
                            >
                                <Target class="h-4 w-4" />
                            </button>
                            <button class="shrink-0 p-2 text-muted-foreground/60" @click="remove(t)">
                                <Trash2 class="h-4 w-4" />
                            </button>
                        </div>
                    </SwipeRow>
                </div>
            </li>
        </template>
    </ul>
</template>
