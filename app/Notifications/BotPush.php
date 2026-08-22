<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * A PWA push mirroring what the bot just sent to Telegram, so the user is
 * alerted in the Life OS app itself — even when it is backgrounded or closed.
 *
 * The Telegram message is still sent separately; this rides alongside it.
 * Only the `webpush` channel: nothing here should touch mail or the database.
 */
class BotPush extends Notification
{
    public function __construct(
        private string $title,
        private ?string $body = null,
    ) {}

    /** @return array<int, class-string> */
    public function via(object $notifiable): array
    {
        return [WebPushChannel::class];
    }

    public function toWebPush(object $notifiable, self $notification): WebPushMessage
    {
        $message = (new WebPushMessage)
            ->title($this->title)
            ->icon('/icons/icon-192.png')
            // Tapping the notification opens the app here (see public/sw.js).
            ->data(['url' => '/']);

        if ($this->body !== null) {
            $message->body($this->body);
        }

        return $message;
    }
}
