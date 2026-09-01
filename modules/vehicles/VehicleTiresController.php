<?php

namespace Modules\Vehicles;

use App\Http\Controllers\Controller;
use App\Support\Modules\ModuleRegistry;
use App\Support\Tenancy\CurrentFamily;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * V1.1 Phase 2: TYRES — a per-vehicle tab listing the car's tyre sets (the mounted one highlighted), with
 * add/edit/delete, a "mount this set" swap that enforces the one-set-on-the-car invariant, and a seasonal
 * "remind me to change" action that spawns an idempotent task in the Tasks module.
 *
 * Same tenancy shape as the other vehicle controllers: route-model binding resolves {vehicle}/{set} before
 * the `family` middleware sets the tenant, so every action guards the family (and the set's vehicle) explicitly.
 */
class VehicleTiresController extends Controller
{
    /** Tyres tab: every set for the car (mounted first), plus whether the Tasks reminder can be offered. */
    public function index(Request $request, Vehicle $vehicle): Response
    {
        $this->authorizeVehicle($vehicle);

        return Inertia::render('vehicles/tires/index', [
            'vehicle' => $this->vehiclePayload($vehicle),
            'unitSystem' => VehicleSetting::current()->unit_system,
            'sets' => $vehicle->tireSets()->get()->map(fn (VehicleTireSet $s) => $this->setPayload($s))->values(),
            // The seasonal reminder only makes sense when the family actually has Tasks on.
            'canRemind' => app(ModuleRegistry::class)->isEnabled('tasks', (int) CurrentFamily::id()),
        ]);
    }

    /** Add a tyre set. Creating one flagged mounted swaps it onto the car (keeping the single-mounted rule). */
    public function store(Request $request, Vehicle $vehicle): RedirectResponse
    {
        $this->authorizeVehicle($vehicle);
        $data = $this->validateSet($request);

        $set = $vehicle->tireSets()->create([
            ...$this->attributes($data),
            'is_mounted' => false, // never trust the create flag directly — mount() owns the invariant
            'created_by' => $request->user()->id,
        ]);

        // If the user marked this set as the one currently on the car, run the guarded swap so exactly one
        // set stays mounted.
        if ($data['is_mounted'] ?? false) {
            $this->swapMounted($vehicle, $set);
        }

        return back();
    }

    /** Edit a set's details. Mounting is a separate action (mount) — editing never changes what's on the car. */
    public function update(Request $request, Vehicle $vehicle, VehicleTireSet $set): RedirectResponse
    {
        $this->authorizeSet($vehicle, $set);
        $data = $this->validateSet($request);

        $set->update($this->attributes($data));

        return back();
    }

    public function destroy(Request $request, Vehicle $vehicle, VehicleTireSet $set): RedirectResponse
    {
        $this->authorizeSet($vehicle, $set);
        $set->delete();

        return back();
    }

    /**
     * Mount this set: make it the (only) set on the car. Swaps the flag in one transaction and stamps when /
     * at what mileage it went on, so the Timeline can show the change. Idempotent — mounting the already-
     * mounted set just re-stamps today's reading.
     */
    public function mount(Request $request, Vehicle $vehicle, VehicleTireSet $set): RedirectResponse
    {
        $this->authorizeSet($vehicle, $set);
        $this->swapMounted($vehicle, $set);

        return back();
    }

    /**
     * Seasonal reminder: create an idempotent "Change to … tyres" task in the Tasks module for this set.
     * Calling twice while the first task is still open returns the same task — never a duplicate.
     */
    public function remind(Request $request, Vehicle $vehicle, VehicleTireSet $set): RedirectResponse
    {
        $this->authorizeSet($vehicle, $set);

        // Only when Tasks is enabled for the family — otherwise there is nowhere for the reminder to land.
        if (app(ModuleRegistry::class)->isEnabled('tasks', (int) CurrentFamily::id())) {
            TireTasks::remind($set, $vehicle, $request->user());
        }

        return back();
    }

    // ---- helpers ---------------------------------------------------------------------------------------

    /**
     * Make $set the only mounted set on the vehicle, stamping its install point at the current odometer. The
     * un-mount of every OTHER set + the mount of this one happen together, so the single-mounted invariant
     * can never be observed broken.
     */
    private function swapMounted(Vehicle $vehicle, VehicleTireSet $set): void
    {
        DB::transaction(function () use ($vehicle, $set) {
            $vehicle->tireSets()->where('id', '!=', $set->id)->update(['is_mounted' => false]);
            $set->update([
                'is_mounted' => true,
                'installed_on' => now()->toDateString(),
                'installed_mileage' => (int) $vehicle->current_mileage,
            ]);
        });
    }

    /**
     * Shared validation for add/edit. `season` is constrained to the known list; the rest are optional
     * lightweight fields. `is_mounted` is accepted only on create (the frontend uses mount() to change it).
     *
     * @return array<string, mixed>
     */
    private function validateSet(Request $request): array
    {
        return $request->validate([
            'season' => ['required', Rule::in(VehicleTireSet::SEASONS)],
            'brand' => ['nullable', 'string', 'max:120'],
            'size' => ['nullable', 'string', 'max:60'],
            'installed_on' => ['nullable', 'date'],
            'installed_mileage' => ['nullable', 'integer', 'min:0'],
            'tread_depth_mm' => ['nullable', 'numeric', 'min:0', 'max:30'],
            'storage_location' => ['nullable', 'string', 'max:120'],
            'is_mounted' => ['sometimes', 'boolean'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);
    }

    /**
     * The persistable column set (everything except the mount flag, which mount()/swapMounted() owns).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function attributes(array $data): array
    {
        return [
            'season' => $data['season'],
            'brand' => $data['brand'] ?? null,
            'size' => $data['size'] ?? null,
            'installed_on' => $data['installed_on'] ?? null,
            'installed_mileage' => $data['installed_mileage'] ?? null,
            'tread_depth_mm' => $data['tread_depth_mm'] ?? null,
            'storage_location' => $data['storage_location'] ?? null,
            'notes' => $data['notes'] ?? null,
        ];
    }

    /** @return array<string, mixed> */
    private function setPayload(VehicleTireSet $set): array
    {
        return $set->toPayload();
    }

    /** The compact vehicle header shape every per-car screen needs (title + odometer + state dot). */
    private function vehiclePayload(Vehicle $vehicle): array
    {
        return [
            'id' => $vehicle->id,
            'make' => $vehicle->make,
            'model' => $vehicle->model,
            'year' => $vehicle->year,
            'version' => $vehicle->version,
            'currentMileage' => (int) $vehicle->current_mileage,
            ...$vehicle->maintenanceSummary(),
        ];
    }

    private function authorizeVehicle(Vehicle $vehicle): void
    {
        abort_unless((int) $vehicle->family_id === (int) CurrentFamily::id(), 404);
    }

    /** Guard the vehicle's family AND that the set belongs to this vehicle. */
    private function authorizeSet(Vehicle $vehicle, VehicleTireSet $set): void
    {
        $this->authorizeVehicle($vehicle);
        abort_unless((int) $set->vehicle_id === (int) $vehicle->id, 404);
    }
}
