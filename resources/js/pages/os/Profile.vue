<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import {
    ChevronRight,
    Download,
    LogOut,
    Palette,
    Send,
    ShieldCheck,
    UserPen,
} from 'lucide-vue-next';
import { computed } from 'vue';

const user = computed(
    () => usePage().props.auth.user as { name: string; email: string },
);

const initials = computed(() =>
    user.value.name
        .split(' ')
        .map((part) => part[0])
        .slice(0, 2)
        .join('')
        .toUpperCase(),
);

const menu = [
    { href: '/settings/profile', label: 'Edit profile', description: 'Name and email', icon: UserPen },
    { href: '/settings/telegram', label: 'Telegram', description: 'Use Life OS from your phone', icon: Send },
    { href: '/settings/security', label: 'Security', description: 'Password and two-factor', icon: ShieldCheck },
    { href: '/settings/appearance', label: 'Appearance', description: 'Light / dark mode', icon: Palette },
    { href: '/onboard', label: 'Import data', description: 'Brain-dump onboarding', icon: Download },
];
</script>

<template>
    <Head title="Profile" />

    <h1 class="text-2xl font-bold text-gradient-brand">Profile</h1>

    <div class="mt-6 flex flex-col items-center gap-3 rounded-2xl border border-border bg-card p-6">
        <div
            class="flex h-20 w-20 items-center justify-center rounded-full bg-gradient-brand text-2xl font-bold text-white shadow-lg shadow-fuchsia-500/25"
        >
            {{ initials }}
        </div>
        <div class="text-center">
            <p class="text-lg font-semibold">{{ user.name }}</p>
            <p class="text-sm text-muted-foreground">{{ user.email }}</p>
        </div>
    </div>

    <ul class="mt-4 space-y-2">
        <li v-for="item in menu" :key="item.href">
            <Link
                :href="item.href"
                class="flex items-center gap-3 rounded-xl border border-border bg-card p-3 transition-colors hover:border-primary/40"
            >
                <span
                    class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-primary/10 text-primary"
                >
                    <component :is="item.icon" class="h-4 w-4" />
                </span>
                <span class="min-w-0 flex-1">
                    <span class="block text-sm font-medium">{{ item.label }}</span>
                    <span class="block text-xs text-muted-foreground">{{ item.description }}</span>
                </span>
                <ChevronRight class="h-4 w-4 shrink-0 text-muted-foreground/50" />
            </Link>
        </li>
    </ul>

    <Link
        href="/logout"
        method="post"
        as="button"
        class="mt-6 flex w-full items-center justify-center gap-2 rounded-xl border border-red-500/30 bg-red-500/5 py-3 text-sm font-medium text-red-500 transition-colors hover:bg-red-500/10"
    >
        <LogOut class="h-4 w-4" /> Log out
    </Link>

    <p class="mt-3 text-center text-xs text-muted-foreground">
        Logging out lands on the login page — tick "Remember me" there to skip
        this next time.
    </p>
</template>
