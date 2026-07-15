<?php

namespace App\Support\Tenancy;

use Illuminate\Support\Facades\Context;

// Active-family accessor backed by Laravel Context. Context is request-scoped (Octane-safe: no static
// leaks between requests) AND is automatically serialized into queued jobs and restored in the worker,
// so CurrentFamily::id() resolves correctly inside background jobs with zero per-job wiring.
class CurrentFamily
{
    public static function set(int $familyId): void
    {
        Context::add('family_id', $familyId);
    }

    public static function id(): ?int
    {
        return Context::get('family_id');
    }

    public static function forget(): void
    {
        Context::forget('family_id');
    }
}
