<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { ArrowLeft, Check, Target, Trash2 } from 'lucide-vue-next';
import { reactive } from 'vue';
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

const form = reactive({
    title: props.todo.title,
    note: props.todo.note,
    bucket: props.todo.bucket,
    due_date: props.todo.due_date?.slice(0, 10) ?? '',
    due_time: props.todo.due_time?.slice(0, 5) ?? '',
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
        @blur="save"
    />

    <!-- Status + focus + delete -->
    <div class="mt-3 flex items-center gap-2">
        <button
            class="flex flex-1 items-center justify-center gap-2 rounded-lg border py-2 text-sm font-medium"
            :class="
                todo.status === 'done'
                    ? 'border-green-500/40 bg-green-500/10 text-green-500'
                    : 'border-border text-muted-foreground'
            "
            @click="toggleDone"
        >
            <Check class="h-4 w-4" />
            {{ todo.status === 'done' ? 'Done' : 'Mark done' }}
        </button>
        <button
            class="flex items-center justify-center gap-1 rounded-lg border px-3 py-2 text-sm font-medium"
            :class="
                todo.focused
                    ? 'border-primary bg-primary/10 text-primary'
                    : 'border-border text-muted-foreground'
            "
            @click="toggleFocus"
        >
            <Target class="h-4 w-4" />
            {{ todo.focused ? 'Focused' : 'Focus' }}
        </button>
        <button
            class="flex h-10 w-10 items-center justify-center rounded-lg border border-border text-muted-foreground"
            @click="remove"
        >
            <Trash2 class="h-4 w-4" />
        </button>
    </div>

    <!-- Description editor -->
    <label class="mt-5 block text-xs font-medium uppercase tracking-wide text-muted-foreground">
        Description
    </label>
    <div class="mt-1" @focusout="save">
        <RichEditor v-model="form.note" />
    </div>

    <!-- Schedule + bucket -->
    <label class="mt-5 block text-xs font-medium uppercase tracking-wide text-muted-foreground">
        Schedule
    </label>
    <div class="mt-1 flex gap-2">
        <DateTimeField v-model="form.due_date" mode="date" class="flex-1" placeholder="Due date" @update:modelValue="save" />
        <DateTimeField v-model="form.due_time" mode="time" class="w-32" placeholder="Time" @update:modelValue="save" />
    </div>
    <select
        v-model="form.bucket"
        class="mt-2 w-full rounded-lg border border-input bg-card px-2 py-2 text-sm outline-none"
        @change="save"
    >
        <option value="work">Work</option>
        <option value="personal">Personal</option>
        <option value="money_task">Money</option>
    </select>
</template>
