import { apiSend } from '@/lib/api';

/**
 * Web Push subscription lifecycle for the PWA. The server delivers a BotPush
 * whenever the bot messages Telegram; this is how a browser opts in to receive
 * those as native notifications (public/sw.js shows them).
 */

export type PushState = 'unsupported' | 'default' | 'granted' | 'denied';

export function pushSupported(): boolean {
    return (
        'serviceWorker' in navigator &&
        'PushManager' in window &&
        'Notification' in window
    );
}

export function pushState(): PushState {
    if (!pushSupported()) {
        return 'unsupported';
    }

    return Notification.permission as PushState;
}

/** VAPID public key (base64url) → the Uint8Array applicationServerKey expects. */
function urlBase64ToUint8Array(base64: string): Uint8Array {
    const padding = '='.repeat((4 - (base64.length % 4)) % 4);
    const normalized = (base64 + padding).replace(/-/g, '+').replace(/_/g, '/');
    const raw = atob(normalized);

    return Uint8Array.from([...raw].map((c) => c.charCodeAt(0)));
}

/**
 * Ask permission, subscribe, and register the subscription server-side.
 * Idempotent — re-subscribing reuses the existing browser subscription.
 * Returns the resulting permission state so the UI can reflect it.
 */
export async function enablePush(vapidPublicKey: string): Promise<PushState> {
    if (!pushSupported()) {
        return 'unsupported';
    }

    const permission = await Notification.requestPermission();

    if (permission !== 'granted') {
        return permission as PushState;
    }

    const registration = await navigator.serviceWorker.ready;
    const subscription =
        (await registration.pushManager.getSubscription()) ??
        (await registration.pushManager.subscribe({
            userVisibleOnly: true,
            applicationServerKey: urlBase64ToUint8Array(vapidPublicKey),
        }));

    const json = subscription.toJSON();
    await apiSend('POST', '/push/subscribe', {
        endpoint: subscription.endpoint,
        keys: json.keys,
    });

    return 'granted';
}

/** Unsubscribe this browser and drop the server-side row. */
export async function disablePush(): Promise<void> {
    if (!pushSupported()) {
        return;
    }

    const registration = await navigator.serviceWorker.ready;
    const subscription = await registration.pushManager.getSubscription();

    if (!subscription) {
        return;
    }

    const { endpoint } = subscription;
    await subscription.unsubscribe();
    await apiSend('DELETE', '/push/subscribe', { endpoint });
}
