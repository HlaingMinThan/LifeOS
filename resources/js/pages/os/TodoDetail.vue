<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { ArrowLeft, Check, Save, Target, Trash2 } from 'lucide-vue-next';
import { computed, reactive } from 'vue';
import DateTimeField from '@/components/DateTimeField.vue';
import RichEditor from '@/components/RichEditor.vue';

type Todo = {
    id: number;
    title: string;
    note: string | null;
    bucket: string;
    status: string;
    focused: boolean;
    due_date: string | null;
    due_time: string | null;
};

const props = defineProps<{ todo: Todo }>();

const initial = () => ({
    title: props.todo.title,
    note: props.todo.note,
    bucket: props.todo.bucket,
    due_date: props.todo.due_date?.slice(0, 10) ?? '',
    due_time: props.todo.due_time?.slice(0, 5) ?? '',
});
const form = reactive(initial());

// Enable the Update button only when something actually changed.
const dirty = computed(() => {
    const i = initial();
    return (
        form.title !== i.title ||
        (form.note ?? '') !== (i.note ?? '') ||
        form.bucket !== i.bucket ||
        form.due_date !== i.due_date ||
        form.due_time !== i.due_time
    );
});

function save() {
    router.patch(`/todos/${props.todo.id}`, {
        title: form.title,
        note: form.note || null,
        bucket: form.bucket,
        due_date: form.due_date || null,
        due_time: form.due_time || null,
    }, { preserveScroll: true });
}

const toggleDone = () =>
    router.patch(`/todos/${props.todo.id}/toggle`, {}, { preserveScroll: true });
const toggleFocus = () =>
    router.patch(`/todos/${props.todo.id}/focus`, {}, { preserveScroll: true });
const remove = () => router.delete(`/todos/${props.todo.id}`);

const backHref = props.todo.due_date
    ? `/todos/day/${props.todo.due_date.slice(0, 10)}`
    : '/todos/day/undated';
</script>

<template>
    <Head :title="todo.title" />

    <Link :href="backHref" class="mb-2 flex items-center gap-1 text-xs text-muted-foreground">
        <ArrowLeft class="h-3.5 w-3.5" /> Back
    </Link>

    <input
        v-model="form.title"
        type="text"
        class="w-full rounded-lg border border-transparent bg-transparent text-2xl font-bold text-gradient-brand outline-none focus:border-input focus:bg-card focus:px-2"
    />

    <!-- Status + focus + delete -->
    <div class="mt-3 flex items-center gap-2">
        <button
            class="flex flex-1 items-center justify-center gap-2 rounded-lg border py-2 text-sm font-medium transition-colors"
            :class="
                todo.status === 'done'
                    ? 'border-green-500 bg-green-500/15 text-green-600 dark:text-green-400'
                    : 'border-green-500/50 text-green-600 hover:bg-green-500/10 dark:text-green-400'
            "
            @click="toggleDone"
        >
            <Check class="h-4 w-4" />
            {{ todo.status === 'done' ? 'Done' : 'Mark done' }}
        </button>
        <button
            class="flex items-center justify-center gap-1 rounded-lg border px-3 py-2 text-sm font-medium transition-colors"
            :class="
                todo.focused
                    ? 'border-blue-500 bg-blue-500/15 text-blue-600 dark:text-blue-400'
                    : 'border-blue-500/50 text-blue-600 hover:bg-blue-500/10 dark:text-blue-400'
            "
            @click="toggleFocus"
        >
            <Target class="h-4 w-4" />
            {{ todo.focused ? 'Focused' : 'Focus' }}
        </button>
        <button
            class="flex h-10 w-10 items-center justify-center rounded-lg border border-red-500/40 text-red-500 transition-colors hover:bg-red-500/10"
            @click="remove"
        >
            <Trash2 class="h-4 w-4" />
        </button>
    </div>

    <!-- Description editor -->
    <label class="mt-5 block text-xs font-medium uppercase tracking-wide text-muted-foreground">
        Description
    </label>
    <div class="mt-1">
        <RichEditor v-model="form.note" />
    </div>

    <!-- Schedule + bucket -->
    <label class="mt-5 block text-xs font-medium uppercase tracking-wide text-muted-foreground">
        Schedule
    </label>
    <div class="mt-1 flex gap-2">
        <DateTimeField v-model="form.due_date" mode="date" class="flex-1" placeholder="Due date" />
        <DateTimeField v-model="form.due_time" mode="time" class="w-32" placeholder="Time" />
    </div>
    <select
        v-model="form.bucket"
        class="mt-2 w-full rounded-lg border border-input bg-card px-2 py-2 text-sm outline-none"
    >
        <option value="work">Work</option>
        <option value="personal">Personal</option>
        <option value="money_task">Money</option>
    </select>

    <!-- Explicit save -->
    <button
        class="mt-5 flex w-full items-center justify-center gap-2 rounded-xl bg-gradient-brand py-3 text-sm font-semibold text-white shadow-md shadow-fuchsia-500/20 transition-opacity active:scale-[0.99] disabled:opacity-40"
        :disabled="!dirty || !form.title.trim()"
        @click="save"
    >
        <Save class="h-4 w-4" /> {{ dirty ? 'Update' : 'Saved' }}
    </button>
</template>
