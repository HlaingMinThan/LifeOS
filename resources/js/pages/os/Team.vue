<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import {
    ArrowLeft,
    Check,
    Copy,
    Eye,
    Mail,
    Send,
    Trash2,
    UserPlus,
} from 'lucide-vue-next';
import { ref } from 'vue';

type Member = {
    id: number;
    email: string;
    status: string;
    name: string;
    username: string | null;
    has_account: boolean;
    invite_url: string | null;
    open_count: number;
};

defineProps<{
    members: Member[];
    memberOf: { id: number; owner_name: string; owner_email: string }[];
    myUsername: string;
}>();

const form = useForm({ email: '' });
const copied = ref<number | null>(null);

const invite = () =>
    form.post('/settings/team', {
        preserveScroll: true,
        onSuccess: () => form.reset('email'),
    });

const remove = (m: Member) => {
    const what = m.status === 'accepted' ? `Remove ${m.name} from your team?` : 'Withdraw this invitation?';
    if (confirm(what)) router.delete(`/settings/team/${m.id}`, { preserveScroll: true });
};

const resend = (m: Member) =>
    router.post(`/settings/team/${m.id}/resend`, {}, { preserveScroll: true });

async function copyLink(m: Member) {
    if (!m.invite_url) return;
    try {
        await navigator.clipboard.writeText(m.invite_url);
        copied.value = m.id;
        setTimeout(() => (copied.value = null), 2000);
    } catch {
        window.prompt('Copy this invite link:', m.invite_url);
    }
}
</script>

<template>
    <Head title="Team" />

    <Link href="/profile" class="mb-2 flex items-center gap-1 text-xs text-muted-foreground">
        <ArrowLeft class="h-3.5 w-3.5" /> Profile
    </Link>

    <h1 class="text-2xl font-bold text-gradient-brand">Team</h1>
    <p class="mt-1 text-sm text-muted-foreground">
        Assign work with <span class="font-medium text-foreground">@username</span> from the
        magic box or Telegram.
    </p>

    <!-- Invite -->
    <form class="mt-4 space-y-2 rounded-xl border border-border bg-card p-3" @submit.prevent="invite">
        <label class="text-xs font-medium uppercase tracking-wide text-muted-foreground">
            Invite by email
        </label>
        <div class="flex gap-2">
            <input
                v-model="form.email"
                type="email"
                required
                placeholder="teammate@example.com"
                class="h-11 flex-1 rounded-lg border border-input bg-background px-3 text-sm outline-none focus:ring-2 focus:ring-ring"
            />
            <button
                type="submit"
                class="flex h-11 w-11 shrink-0 items-center justify-center rounded-lg bg-gradient-brand text-white shadow-md shadow-fuchsia-500/20 disabled:opacity-50"
                :disabled="form.processing || !form.email"
            >
                <UserPlus class="h-5 w-5" />
            </button>
        </div>
        <p v-if="form.errors.email" class="text-xs text-red-500">{{ form.errors.email }}</p>
        <p class="text-xs text-muted-foreground">
            They get an email — or copy the invite link and send it yourself.
        </p>
    </form>

    <!-- My team -->
    <h2 class="mt-6 text-sm font-medium text-muted-foreground">My team</h2>
    <div
        v-if="!members.length"
        class="mt-2 rounded-xl border border-dashed border-border p-6 text-center text-sm text-muted-foreground"
    >
        No one yet — invite someone above.
    </div>

    <ul v-else class="mt-2 space-y-2">
        <li
            v-for="m in members"
            :key="m.id"
            class="rounded-xl border border-border bg-card p-3"
            :class="{ 'opacity-60': m.status === 'revoked' }"
        >
            <div class="flex items-center gap-3">
                <div
                    class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-gradient-brand text-xs font-bold text-white"
                >
                    {{ m.name.slice(0, 2).toUpperCase() }}
                </div>
                <div class="min-w-0 flex-1">
                    <p class="truncate text-sm font-medium">{{ m.name }}</p>
                    <p class="truncate text-xs text-muted-foreground">
                        <span v-if="m.username">@{{ m.username }} · </span>{{ m.email }}
                    </p>
                </div>

                <!-- View is only meaningful once they have accepted -->
                <Link
                    v-if="m.status === 'accepted'"
                    :href="`/settings/team/${m.id}`"
                    class="shrink-0 p-2 text-muted-foreground/70 hover:text-primary"
                    title="View assigned tasks"
                >
                    <Eye class="h-4 w-4" />
                </Link>
                <button
                    v-if="m.status === 'pending'"
                    class="shrink-0 p-2 text-muted-foreground/70"
                    title="Resend invite"
                    @click="resend(m)"
                >
                    <Send class="h-4 w-4" />
                </button>
                <button
                    class="shrink-0 p-2 text-muted-foreground/60 hover:text-red-500"
                    title="Remove"
                    @click="remove(m)"
                >
                    <Trash2 class="h-4 w-4" />
                </button>
            </div>

            <div class="mt-2 flex flex-wrap items-center gap-2">
                <span
                    class="rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide"
                    :class="{
                        'bg-green-500/15 text-green-500': m.status === 'accepted',
                        'bg-amber-500/15 text-amber-500': m.status === 'pending',
                        'bg-muted text-muted-foreground': m.status === 'revoked',
                    }"
                >
                    {{ m.status }}
                </span>
                <span v-if="m.status === 'accepted' && m.open_count" class="text-xs text-muted-foreground">
                    {{ m.open_count }} open task{{ m.open_count > 1 ? 's' : '' }}
                </span>
                <button
                    v-if="m.invite_url"
                    class="ml-auto flex items-center gap-1 rounded-full border border-border px-2 py-1 text-[11px] text-muted-foreground"
                    @click="copyLink(m)"
                >
                    <Check v-if="copied === m.id" class="h-3 w-3 text-green-500" />
                    <Copy v-else class="h-3 w-3" />
                    {{ copied === m.id ? 'Copied' : 'Copy invite link' }}
                </button>
            </div>
        </li>
    </ul>

    <!-- Teams I am in: who can put work in my list -->
    <template v-if="memberOf.length">
        <h2 class="mt-6 text-sm font-medium text-muted-foreground">I'm in their team</h2>
        <ul class="mt-2 space-y-2">
            <li
                v-for="t in memberOf"
                :key="t.id"
                class="flex items-center gap-3 rounded-xl border border-border bg-card p-3"
            >
                <Mail class="h-4 w-4 shrink-0 text-muted-foreground" />
                <div class="min-w-0">
                    <p class="truncate text-sm font-medium">{{ t.owner_name }}</p>
                    <p class="truncate text-xs text-muted-foreground">can assign tasks to you</p>
                </div>
            </li>
        </ul>
    </template>

    <p class="mt-6 text-center text-xs text-muted-foreground">
        Your handle is <span class="font-medium text-foreground">@{{ myUsername }}</span>
    </p>
</template>
