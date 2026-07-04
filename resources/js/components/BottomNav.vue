<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { Heart, Home, Lightbulb, ListTodo, Wallet } from 'lucide-vue-next';

const page = usePage();

const tabs = [
    { href: '/', label: 'Home', icon: Home },
    { href: '/money', label: 'Money', icon: Wallet },
    { href: '/todos', label: 'Todos', icon: ListTodo },
    { href: '/care', label: 'Care', icon: Heart },
    { href: '/ideas', label: 'Ideas', icon: Lightbulb },
];

const isActive = (href: string) =>
    href === '/' ? page.url === '/' : page.url.startsWith(href);
</script>

<template>
    <nav
        class="fixed inset-x-0 bottom-0 z-50 border-t border-border bg-background/95 backdrop-blur pb-[env(safe-area-inset-bottom)]"
    >
        <div class="mx-auto grid max-w-md grid-cols-5">
            <Link
                v-for="tab in tabs"
                :key="tab.href"
                :href="tab.href"
                class="flex flex-col items-center gap-1 py-2 text-xs transition-colors"
                :class="
                    isActive(tab.href)
                        ? 'font-medium text-primary'
                        : 'text-muted-foreground hover:text-foreground'
                "
            >
                <span
                    class="flex h-7 w-12 items-center justify-center rounded-full transition-all"
                    :class="
                        isActive(tab.href)
                            ? 'bg-gradient-brand text-white shadow-md shadow-fuchsia-500/25'
                            : ''
                    "
                >
                    <component :is="tab.icon" class="h-5 w-5" />
                </span>
                <span>{{ tab.label }}</span>
            </Link>
        </div>
    </nav>
</template>
