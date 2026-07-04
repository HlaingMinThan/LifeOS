<script setup lang="ts">
import { ref } from 'vue';

// Touch swipe: right past the threshold emits swipe-right (toggle),
// left emits swipe-left (delete). Vertical movement is left to the
// scroller. Buttons in the slot keep working for desktop.
const props = withDefaults(defineProps<{ threshold?: number }>(), { threshold: 70 });
const emit = defineEmits<{ 'swipe-left': []; 'swipe-right': [] }>();

const dx = ref(0);
const dragging = ref(false);
let startX: number | null = null;
let startY: number | null = null;
let horizontal: boolean | null = null;

function onStart(e: TouchEvent) {
    startX = e.touches[0].clientX;
    startY = e.touches[0].clientY;
    horizontal = null;
    dragging.value = true;
}

function onMove(e: TouchEvent) {
    if (!dragging.value || startX === null || startY === null) return;
    const moveX = e.touches[0].clientX - startX;
    const moveY = e.touches[0].clientY - startY;

    if (horizontal === null && (Math.abs(moveX) > 8 || Math.abs(moveY) > 8)) {
        horizontal = Math.abs(moveX) > Math.abs(moveY);
    }
    if (!horizontal) return;

    dx.value = Math.max(-110, Math.min(110, moveX));
}

function onEnd() {
    if (dx.value <= -props.threshold) emit('swipe-left');
    else if (dx.value >= props.threshold) emit('swipe-right');
    dx.value = 0;
    dragging.value = false;
    startX = startY = null;
}
</script>

<template>
    <div class="relative overflow-hidden rounded-xl">
        <div
            v-if="dx > 0"
            class="absolute inset-0 flex items-center rounded-xl bg-green-500/20 px-4 text-green-500"
        >
            ✓
        </div>
        <div
            v-if="dx < 0"
            class="absolute inset-0 flex items-center justify-end rounded-xl bg-red-500/20 px-4 text-red-500"
        >
            🗑
        </div>
        <div
            class="relative"
            :style="{
                transform: `translateX(${dx}px)`,
                transition: dragging ? 'none' : 'transform 150ms ease',
            }"
            @touchstart.passive="onStart"
            @touchmove.passive="onMove"
            @touchend="onEnd"
            @touchcancel="onEnd"
        >
            <slot />
        </div>
    </div>
</template>
