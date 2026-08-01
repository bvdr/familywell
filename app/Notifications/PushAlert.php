<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

// One generic Web Push notification reused for every trigger (task assigned, shared with you, ...).
// `url` is where tapping the notification opens the app (a same-origin path).
class PushAlert extends Notification
{
    public function __construct(
        public string $title,
        public string $body,
        public string $url = '/dashboard',
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return [WebPushChannel::class];
    }

    public function toWebPush(object $notifiable, Notification $notification): WebPushMessage
    {
        return (new WebPushMessage)
            ->title($this->title)
            ->body($this->body)
            ->icon('/icons/icon-192.png')
            ->badge('/icons/icon-192.png')
            ->data(['url' => $this->url]);
    }
}
