<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

// One push to the admin PWA (see App\Support\AdminPush). Push only: there is no in-app feed for it. The icon
// and url resolve against the admin's origin (its service worker shows it), so they are admin paths.
class AdminAlert extends Notification
{
    public function __construct(public string $title, public string $body, public string $url) {}

    public function via(object $notifiable): array
    {
        return [WebPushChannel::class];
    }

    public function toWebPush(object $notifiable, Notification $notification): WebPushMessage
    {
        return (new WebPushMessage)->title($this->title)->body($this->body)
            ->icon('/icon-192.png')->data(['url' => $this->url])
            ->tag((string) $notification->id); // each event its own notification, never collapsed
    }
}
