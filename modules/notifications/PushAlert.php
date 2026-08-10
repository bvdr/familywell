<?php

namespace Modules\Notifications;

use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

// One generic notification reused for every trigger. It always writes to the in-app log (the
// `database` channel), and additionally sends a Web Push unless the recipient muted this category.
// `url` is where tapping opens the app; `category` becomes the SW notification tag so same-category
// pushes stack into one running-count notification instead of piling up.
class PushAlert extends Notification
{
    public function __construct(
        public string $title,
        public string $body,
        public string $url = '/dashboard',
        public ?string $category = null,
    ) {}

    /**
     * Always log in-app; push only when the recipient wants this category. This is what makes
     * "mute" stop the phone buzz while keeping the item in the in-app feed.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        $channels = ['database'];
        if (! $this->category || ! method_exists($notifiable, 'wantsPush') || $notifiable->wantsPush($this->category)) {
            $channels[] = WebPushChannel::class;
        }

        return $channels;
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
                'categoryLabel' => $this->category ? NotificationCategories::label($this->category) : null,
            ]);

        if ($this->category) {
            $message->tag($this->category); // same tag => the SW stacks them into one notification
        }

        return $message;
    }

    /** The in-app log row payload. @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'title' => $this->title,
            'body' => $this->body,
            'url' => $this->url,
            'category' => $this->category,
            'categoryLabel' => $this->category ? NotificationCategories::label($this->category) : null,
        ];
    }
}
