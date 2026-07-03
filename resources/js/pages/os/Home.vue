<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { Send } from 'lucide-vue-next';
import { ref } from 'vue';

const text = ref('');

// Day 2: POST /inbox/parse → confirm chip → /inbox/apply
const submit = () => {};
</script>

<template>
    <Head title="Home" />

    <h1 class="text-2xl font-semibold">Catch up</h1>
    <p class="mt-1 text-sm text-muted-foreground">
        Everything that needs you, on one screen.
    </p>

    <form class="mt-4 flex gap-2" @submit.prevent="submit">
        <input
            v-model="text"
            type="text"
            placeholder="paid gon khaung 500k…"
            class="h-12 flex-1 rounded-xl border border-input bg-card px-4 text-base shadow-sm outline-none placeholder:text-muted-foreground focus:ring-2 focus:ring-ring"
        />
        <button
            type="submit"
            class="flex h-12 w-12 items-center justify-center rounded-xl bg-primary text-primary-foreground shadow-sm disabled:opacity-50"
            :disabled="!text.trim()"
        >
            <Send class="h-5 w-5" />
        </button>
    </form>

    <div class="mt-6 space-y-4">
        <section
            v-for="section in [
                { title: 'You owe', empty: 'No open payables' },
                { title: 'Owed to you', empty: 'No open receivables' },
                { title: 'Today', empty: 'Nothing due today' },
                { title: 'Overdue', empty: 'Nothing overdue 🎉' },
            ]"
            :key="section.title"
            class="rounded-xl border border-border bg-card p-4"
        >
            <h2 class="text-sm font-medium text-muted-foreground">
                {{ section.title }}
            </h2>
            <p class="mt-2 text-sm text-muted-foreground/70">
                {{ section.empty }}
            </p>
        </section>
    </div>
</template>
