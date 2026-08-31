<script setup lang="ts">
import { Head, router, usePage } from '@inertiajs/vue3';
import { Check, Users } from 'lucide-vue-next';
import { computed } from 'vue';

const props = defineProps<{
    token: string;
    ownerName: string | null;
    email: string;
    status: string;
    mismatch: boolean;
}>();

const page = usePage();
const error = computed(() => (page.props.errors as Record<string, string>)?.token);

const accept = () => router.post(`/invite/${props.token}`);
</script>

<template>
    <Head title="Team invitation" />

    <div class="mx-auto mt-10 max-w-sm rounded-2xl border border-border bg-card p-6 text-center">
        <div
            class="mx-auto flex h-14 w-14 items-center justify-center rounded-2xl bg-gradient-brand text-white shadow-lg shadow-fuchsia-500/25"
        >
            <Users class="h-7 w-7" />
        </div>

        <h1 class="mt-4 text-xl font-bold text-gradient-brand">
            {{ ownerName }} invited you
        </h1>
        <p class="mt-2 text-sm text-muted-foreground">
            They'll be able to send you tasks, which land in your own todo list. Only what they
            assign is visible to them — the rest of your Life OS stays private.
        </p>

        <p v-if="error" class="mt-4 text-sm text-red-500">{{ error }}</p>

        <p v-else-if="mismatch" class="mt-4 text-sm text-amber-500">
            This invitation was sent to {{ email }}. Sign in with that account to accept it.
        </p>

        <template v-else-if="status === 'accepted'">
            <p class="mt-4 text-sm text-green-500">You're already on this team ✓</p>
        </template>

        <button
            v-else
            class="mt-5 flex w-full items-center justify-center gap-2 rounded-xl bg-gradient-brand py-3 text-sm font-semibold text-white shadow-md shadow-fuchsia-500/20"
            @click="accept"
        >
            <Check class="h-4 w-4" /> Accept invitation
        </button>
    </div>
</template>
