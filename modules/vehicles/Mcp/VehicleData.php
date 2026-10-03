<?php

namespace Modules\Vehicles\Mcp;

use App\Support\Modules\ModuleRegistry;
use App\Support\Tenancy\CurrentFamily;
use Modules\Vehicles\Vehicle;
use Modules\Vehicles\VehicleOverview;
use Modules\Vehicles\VehicleSetting;

// Shared lookups + payload shapes for the Vehicles MCP tools. Cars are family-wide (no per-car sharing:
// every member sees and edits the same cars), so the BelongsToFamily scope under CurrentFamily IS the
// access rule. Distances are always kilometres in and out (miles are only a display preference).
class VehicleData
{
    public static function vehicle(mixed $id): ?Vehicle
    {
        return Vehicle::find((int) $id);
    }

    public static function summary(Vehicle $v): array
    {
        $status = VehicleOverview::status($v);

        return [
            'id' => $v->id,
            'name' => trim("{$v->make} {$v->model}".($v->year ? " ({$v->year})" : '')),
            'make' => $v->make,
            'model' => $v->model,
            'year' => $v->year,
            'license_plate' => $v->license_plate ?: $v->registration_number,
            'fuel_type' => $v->fuel_type,
            'current_mileage_km' => (int) $v->current_mileage,
            'status' => $status['overall'], // good | attention
            'maintenance_needing_attention' => $status['maintenanceAttention'],
            'documents_expiring' => $status['documentsExpiring'],
            'url' => self::url("/m/vehicles/{$v->id}"),
        ];
    }

    /** The family's display unit, so the assistant can phrase distances the way the family reads them. */
    public static function displayUnit(): string
    {
        return VehicleSetting::current()->unit_system === 'imperial' ? 'miles' : 'km';
    }

    public static function spendingEnabled(): bool
    {
        return app(ModuleRegistry::class)->isEnabled('spending', (int) CurrentFamily::id());
    }

    public static function url(string $path): string
    {
        return rtrim((string) config('app.url'), '/').$path;
    }
}
