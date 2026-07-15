<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

// ShouldBroadcastNow: broadcast synchronously so a ping goes out without a queue worker.
class FamilyPinged implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public int $familyId) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel("family.{$this->familyId}")];
    }

    public function broadcastAs(): string
    {
        return 'FamilyPinged';
    }
}
