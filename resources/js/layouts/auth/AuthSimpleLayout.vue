<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { Sparkles } from 'lucide-vue-next';
import { home } from '@/routes';

defineProps<{
    title?: string;
    description?: string;
}>();
</script>

<template>
    <div
        class="relative flex min-h-svh flex-col items-center justify-center gap-6 overflow-hidden bg-background p-6 md:p-10"
    >
        <!-- Decorative brand glow -->
        <div
            class="pointer-events-none absolute -top-32 -left-32 h-96 w-96 rounded-full bg-purple-600/25 blur-3xl"
            aria-hidden="true"
        ></div>
        <div
            class="pointer-events-none absolute -right-32 -bottom-32 h-96 w-96 rounded-full bg-pink-500/20 blur-3xl"
            aria-hidden="true"
        ></div>

        <div class="relative w-full max-w-sm">
            <div
                class="flex flex-col gap-8 rounded-2xl border border-border bg-card/80 p-8 shadow-xl shadow-purple-900/10 backdrop-blur"
            >
                <div class="flex flex-col items-center gap-4">
                    <Link
                        :href="home()"
                        class="flex flex-col items-center gap-2 font-medium"
                    >
                        <div
                            class="mb-1 flex h-12 w-12 items-center justify-center rounded-2xl bg-gradient-brand shadow-lg shadow-fuchsia-500/30"
                        >
                            <Sparkles class="h-6 w-6 text-white" />
                        </div>
                        <span class="text-lg font-bold text-gradient-brand">Life OS</span>
                    </Link>
                    <div class="space-y-2 text-center">
                        <h1 class="text-xl font-semibold">{{ title }}</h1>
                        <p class="text-center text-sm text-muted-foreground">
                            {{ description }}
                        </p>
                    </div>
                </div>
                <slot />
            </div>
        </div>
    </div>
</template>
