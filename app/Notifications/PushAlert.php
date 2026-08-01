<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

// One generic Web Push notification reused for every trigger. `url` is where tapping opens the app.
// `category` (when set) becomes the notification tag so the service worker can stack same-category
// pushes into a single, running-count notification instead of a pile of separate ones.
class PushAlert extends Notification
{
    // Human labels the service worker shows on a stacked notification, keyed by category.
    private const LABELS = [
        'tasks' => 'Tasks',
        'shopping' => 'Shopping',
        'spending' => 'Spending',
        'projects' => 'Projects',
        'sharing' => 'Shared with you',
    ];

    public function __construct(
        public string $title,
        public string $body,
        public string $url = '/dashboard',
        public ?string $category = null,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return [WebPushChannel::class];
    }

    public function toWebPush(object $notifiable, Notification $notification): WebPushMessage
    {
        $message = (new WebPushMessage)
            ->title($this->title)
            ->body($this->body)
            ->icon('/icons/icon-192.png')
            ->badge('/icons/icon-192.png')
            ->data([
                'url' => $this->url,
                'category' => $this->category,
                'categoryLabel' => $this->category ? (self::LABELS[$this->category] ?? 'FamilyWell') : null,
            ]);

        if ($this->category) {
            $message->tag($this->category); // same tag => the SW stacks them into one notification
        }

        return $message;
    }
}
