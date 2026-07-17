<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * PWA push notifications. Enabling/disabling is a per-browser action handled
 * client-side (permission + subscription); this page just hosts it and tracks
 * the one-time register nudge. The VAPID public key is shared globally.
 */
class NotificationController extends Controller
{
    public function edit(Request $request): Response
    {
        return Inertia::render('os/Notifications', [
            // Whether any device is subscribed — informational; the toggle
            // itself reads this browser's live permission state.
            'hasSubscription' => $request->user()->pushSubscriptions()->exists(),
        ]);
    }

    /** "Not now" on the Home nudge — stop asking. */
    public function dismissPrompt(Request $request): RedirectResponse
    {
        $request->user()->forceFill(['notification_prompt_dismissed_at' => now()])->save();

        return back();
    }
}
