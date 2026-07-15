<?php

use App\Broadcasting\FamilyChannel;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

// Members-only private channel; the authorization logic lives in FamilyChannel::join (unit-tested).
Broadcast::channel('family.{familyId}', FamilyChannel::class);
