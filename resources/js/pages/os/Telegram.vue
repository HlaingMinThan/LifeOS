<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ArrowLeft, Check, Send } from 'lucide-vue-next';
import { computed } from 'vue';

const props = defineProps<{
    hasToken: boolean;
    botUsername: string | null;
    chatId: string | null;
    linkedAt: string | null;
    usesWebhook: boolean;
    webhook: { registered: boolean; error: string | null } | null;
}>();

// Three states, in order: no token → token but no chat → connected.
const step = computed(() => {
    if (!props.hasToken) {
        return 'token';
    }

    if (!props.chatId) {
        return 'link';
    }

    return 'connected';
});

const botLink = computed(() =>
    props.botUsername ? `https://t.me/${props.botUsername}` : null,
);

const tokenForm = useForm({ token: '' });
const detectForm = useForm({});
const disconnectForm = useForm({});
const webhookForm = useForm({});

const saveToken = () =>
    tokenForm.post('/settings/telegram/token', { preserveScroll: true });
const detect = () =>
    detectForm.post('/settings/telegram/detect', { preserveScroll: true });
const disconnect = () =>
    disconnectForm.delete('/settings/telegram', { preserveScroll: true });
const registerWebhook = () =>
    webhookForm.post('/settings/telegram/webhook', { preserveScroll: true });

// Delivery is healthy only when a webhook is registered here AND Telegram
// reports no recent delivery error. Poller environments have no webhook to check.
const webhookHealthy = computed(
    () => props.webhook?.registered && !props.webhook.error,
);
</script>

<template>
    <Head title="Telegram" />

    <Link
        href="/profile"
        class="mb-2 flex items-center gap-1 text-xs text-muted-foreground"
    >
        <ArrowLeft class="h-3.5 w-3.5" /> Back
    </Link>

    <h1 class="text-gradient-brand text-2xl font-bold">Telegram</h1>
    <p class="mt-1 text-sm text-muted-foreground">
        Optional. Connect a bot to use Life OS from your phone — type things
        from anywhere, get your morning digest and reminders.
    </p>

    <!-- Connected -->
    <div v-if="step === 'connected'" class="mt-6 space-y-3">
        <div class="rounded-xl border border-primary/40 bg-card p-4">
            <div class="flex items-center gap-2">
                <div
                    class="bg-gradient-brand flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-white"
                >
                    <Check class="h-4 w-4" />
                </div>
                <div class="min-w-0">
                    <p class="text-sm font-medium">Connected</p>
                    <a
                        v-if="botLink"
                        :href="botLink"
                        target="_blank"
                        rel="noopener"
                        class="truncate text-xs text-muted-foreground underline underline-offset-4"
                        >@{{ botUsername }}</a
                    >
                </div>
            </div>

            <p class="mt-3 text-xs text-muted-foreground">
                Send your bot anything — "paid gon khaung 500k" — or try
                <code class="rounded bg-muted px-1">today</code>.
            </p>

            <!-- Poller environment: no webhook exists, delivery is a process. -->
            <p
                v-if="!usesWebhook"
                class="mt-2 border-t border-border pt-2 text-xs text-muted-foreground"
            >
                No public HTTPS here, so delivery needs
                <code class="rounded bg-muted px-1"
                    >php artisan telegram:listen</code
                >
                running.
            </p>

            <!-- Webhook environment: show the real registration state, not a guess. -->
            <div v-else class="mt-2 border-t border-border pt-2">
                <p v-if="webhookHealthy" class="text-xs text-muted-foreground">
                    Delivery: webhook active.
                </p>

                <template v-else>
                    <p class="text-xs text-amber-500">
                        <template v-if="webhook?.error">
                            Last delivery error: {{ webhook.error }}
                        </template>
                        <template v-else>
                            Webhook not registered — messages won't reach Life
                            OS yet.
                        </template>
                    </p>
                    <p
                        v-if="webhookForm.errors.webhook"
                        class="mt-1 text-xs text-destructive"
                    >
                        {{ webhookForm.errors.webhook }}
                    </p>
                    <button
                        class="bg-gradient-brand mt-2 rounded-lg px-3 py-1.5 text-xs font-medium text-white disabled:opacity-50"
                        :disabled="webhookForm.processing"
                        @click="registerWebhook"
                    >
                        {{
                            webhookForm.processing
                                ? 'Registering…'
                                : webhook?.error
                                  ? 'Re-register webhook'
                                  : 'Register webhook'
                        }}
                    </button>
                </template>
            </div>
        </div>

        <button
            class="rounded-lg border border-border px-4 py-2 text-sm text-muted-foreground"
            :disabled="disconnectForm.processing"
            @click="disconnect"
        >
            Disconnect
        </button>
    </div>

    <!-- Step 1 + 2: create the bot, paste the token -->
    <div v-else-if="step === 'token'" class="mt-6 space-y-4">
        <ol class="space-y-3">
            <li class="flex gap-3 rounded-xl border border-border bg-card p-3">
                <span class="text-sm font-semibold text-primary">1</span>
                <p class="text-sm text-muted-foreground">
                    Open
                    <a
                        href="https://t.me/BotFather"
                        target="_blank"
                        rel="noopener"
                        class="font-medium text-foreground underline underline-offset-4"
                        >@BotFather</a
                    >
                    in Telegram.
                </p>
            </li>
            <li class="flex gap-3 rounded-xl border border-border bg-card p-3">
                <span class="text-sm font-semibold text-primary">2</span>
                <p class="text-sm text-muted-foreground">
                    Send <code class="rounded bg-muted px-1">/newbot</code> and
                    follow the prompts — any name works.
                </p>
            </li>
            <li class="flex gap-3 rounded-xl border border-border bg-card p-3">
                <span class="text-sm font-semibold text-primary">3</span>
                <p class="text-sm break-words text-muted-foreground">
                    It replies with a token like
                    <code class="rounded bg-muted px-1">123456789:AAE-xxxx</code
                    >. Paste the whole line below.
                </p>
            </li>
        </ol>

        <form class="space-y-2" @submit.prevent="saveToken">
            <label class="text-xs text-muted-foreground" for="token">
                Bot token
            </label>
            <input
                id="token"
                v-model="tokenForm.token"
                type="password"
                autocomplete="off"
                placeholder="123456789:AAE-xxxxxxxxxxxxxxxxxx"
                class="w-full rounded-lg border border-input bg-background px-3 py-2 text-sm outline-none focus:ring-2 focus:ring-ring"
            />
            <p v-if="tokenForm.errors.token" class="text-xs text-destructive">
                {{ tokenForm.errors.token }}
            </p>

            <button
                type="submit"
                class="bg-gradient-brand w-full rounded-lg py-2 text-sm font-medium text-white disabled:opacity-50"
                :disabled="tokenForm.processing || !tokenForm.token"
            >
                {{
                    tokenForm.processing
                        ? 'Checking with Telegram…'
                        : 'Save token'
                }}
            </button>
        </form>
    </div>

    <!-- Step 3: link the chat -->
    <div v-else class="mt-6 space-y-4">
        <div class="rounded-xl border border-primary/40 bg-card p-4">
            <div class="flex items-center gap-2">
                <Send class="h-4 w-4 shrink-0 text-primary" />
                <p class="text-sm font-medium">Almost there</p>
            </div>
            <p class="mt-2 text-sm text-muted-foreground">
                Open
                <a
                    v-if="botLink"
                    :href="botLink"
                    target="_blank"
                    rel="noopener"
                    class="font-medium text-foreground underline underline-offset-4"
                    >@{{ botUsername }}</a
                >
                <span v-else>your new bot</span>
                and press <strong class="text-foreground">Start</strong>. That
                is how it learns which chat is yours.
            </p>
        </div>

        <p v-if="detectForm.errors.detect" class="text-xs text-destructive">
            {{ detectForm.errors.detect }}
        </p>

        <button
            class="bg-gradient-brand w-full rounded-lg py-2 text-sm font-medium text-white disabled:opacity-50"
            :disabled="detectForm.processing"
            @click="detect"
        >
            {{ detectForm.processing ? 'Looking…' : "I've pressed Start" }}
        </button>

        <button
            class="w-full rounded-lg border border-border px-4 py-2 text-sm text-muted-foreground"
            :disabled="disconnectForm.processing"
            @click="disconnect"
        >
            Use a different bot
        </button>
    </div>
</template>
