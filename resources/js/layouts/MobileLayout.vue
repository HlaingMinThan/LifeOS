<script setup lang="ts">
import BottomNav from '@/components/BottomNav.vue';
</script>

<template>
    <div class="min-h-screen bg-background text-foreground">
        <main class="mx-auto w-full max-w-md px-4 pt-6 pb-24">
            <slot />
        </main>
        <BottomNav />
    </div>
</template>
