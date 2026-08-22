<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import { ArrowLeft, Bell } from 'lucide-vue-next';
import { computed, onMounted, ref } from 'vue';
import { disablePush, enablePush, pushState } from '@/lib/pushNotifications';
import type { PushState } from '@/lib/pushNotifications';

defineProps<{ hasSubscription: boolean }>();

const page = usePage();
const vapidPublicKey = computed(
    () => (page.props.vapidPublicKey as string | null) ?? '',
);

const state = ref<PushState>('unsupported');
const busy = ref(false);
const error = ref<string | null>(null);

// iOS only allows Web Push from an installed PWA (Add to Home Screen).
const isIosSafariTab = computed(() => {
    const ua = navigator.userAgent;
    const isIos = /iphone|ipad|ipod/i.test(ua);
    const standalone =
        window.matchMedia('(display-mode: standalone)').matches ||
        (navigator as unknown as { standalone?: boolean }).standalone === true;

    return isIos && !standalone;
});

onMounted(() => {
    state.value = pushState();
});

const enabled = computed(() => state.value === 'granted');

async function toggle() {
    if (busy.value) {
        return;
    }

    busy.value = true;
    error.value = null;

    try {
        if (enabled.value) {
            await disablePush();
            state.value = 'default';
        } else {
            state.value = await enablePush(vapidPublicKey.value);

            if (state.value === 'denied') {
                error.value =
                    'Notifications are blocked. Enable them for this site in your browser settings, then try again.';
            }
        }
    } catch (e) {
        error.value = e instanceof Error ? e.message : 'Something went wrong.';
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <Head title="Notifications" />

    <Link
        href="/profile"
        class="mb-2 flex items-center gap-1 text-xs text-muted-foreground"
    >
        <ArrowLeft class="h-3.5 w-3.5" /> Back
    </Link>

    <h1 class="text-gradient-brand text-2xl font-bold">Notifications</h1>
    <p class="mt-1 text-sm text-muted-foreground">
        Get a notification from Life OS — even when the app is closed — whenever
        your reminders, care tasks, or morning digest fire.
    </p>

    <!-- Not supported at all -->
    <div
        v-if="state === 'unsupported'"
        class="mt-6 rounded-xl border border-border bg-card p-4 text-sm text-muted-foreground"
    >
        This browser doesn't support push notifications. You'll still get every
        alert on Telegram.
    </div>

    <template v-else>
        <div class="mt-6 rounded-xl border border-primary/40 bg-card p-4">
            <div class="flex items-center justify-between gap-3">
                <div class="flex items-center gap-2">
                    <Bell class="h-4 w-4 shrink-0 text-primary" />
                    <div>
                        <p class="text-sm font-medium">
                            {{
                                enabled
                                    ? 'Notifications on'
                                    : 'Notifications off'
                            }}
                        </p>
                        <p class="text-xs text-muted-foreground">
                            {{
                                enabled
                                    ? 'This device will get Life OS notifications.'
                                    : 'Turn on to get them on this device.'
                            }}
                        </p>
                    </div>
                </div>

                <button
                    class="rounded-lg px-3 py-1.5 text-xs font-medium disabled:opacity-50"
                    :class="
                        enabled
                            ? 'border border-border text-muted-foreground'
                            : 'bg-gradient-brand text-white'
                    "
                    :disabled="busy"
                    @click="toggle"
                >
                    {{ busy ? 'Working…' : enabled ? 'Turn off' : 'Turn on' }}
                </button>
            </div>

            <p v-if="error" class="mt-3 text-xs text-destructive">
                {{ error }}
            </p>
        </div>

        <!-- iOS install hint -->
        <p
            v-if="isIosSafariTab"
            class="mt-3 rounded-xl border border-amber-500/30 bg-amber-500/5 p-3 text-xs text-amber-600 dark:text-amber-400"
        >
            On iPhone/iPad, add Life OS to your Home Screen first (Share →
            <strong>Add to Home Screen</strong>), then open it from there to
            turn on notifications.
        </p>
    </template>
</template>
