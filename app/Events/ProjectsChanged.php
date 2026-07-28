<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ProjectsChanged implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public int $familyId) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel("family.{$this->familyId}")];
    }

    public function broadcastAs(): string
    {
        return 'ProjectsChanged';
    }
}
