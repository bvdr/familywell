<?php

namespace Modules\Vehicles;

use App\Models\User;
use App\Support\Tenancy\BelongsToFamily;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A service provider / garage (V1.1) — a lightweight, family-scoped contact that maintenance history and
 * repairs can reference instead of a free-text garage name. Owned by the Vehicles module; every family
 * member may manage the shared list (the family scope is the boundary, like vehicles themselves).
 *
 * NOTE: not App\Providers or a Laravel service provider — this is the domain entity from spec §12. The FQCN
 * Modules\Vehicles\ServiceProvider keeps it unambiguous at every call site.
 */
class ServiceProvider extends Model
{
    use BelongsToFamily;

    protected $table = 'vehicles_service_providers';

    protected $fillable = ['name', 'address', 'phone', 'website', 'notes', 'created_by', 'family_id'];

    /** Maintenance performed at this provider (newest first). */
    public function maintenanceHistory(): HasMany
    {
        return $this->hasMany(VehicleMaintenanceHistory::class, 'service_provider_id')->latest('performed_at')->latest('id');
    }

    /** Repairs done at this provider (newest first). */
    public function repairs(): HasMany
    {
        return $this->hasMany(Repair::class, 'service_provider_id')->latest('occurred_on')->latest('id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return array<string, mixed> */
    public function toPayload(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'address' => $this->address,
            'phone' => $this->phone,
            'website' => $this->website,
            'notes' => $this->notes,
            'createdBy' => $this->created_by,
        ];
    }
}
