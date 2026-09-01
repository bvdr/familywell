<?php

namespace Modules\Vehicles;

use App\Http\Controllers\Controller;
use App\Models\Family;
use App\Support\Media\StoredImage;
use App\Support\Tenancy\CurrentFamily;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Phase 1: the My Cars list, the Add/Edit vehicle form, and the per-car Overview with first-class
 * mileage (quick presets + manual, logged history). Maintenance / repairs / fuel / documents and their
 * cross-module workflows arrive in later phases — the Overview shows stubbed sections for them.
 *
 * Vehicles are collaborative family data: any member may add, edit, update mileage on, or delete any
 * family vehicle (the family scope is the boundary, mirroring inventory stock). Route-model binding
 * resolves the {vehicle} before the `family` middleware sets the active tenant, so the BelongsToFamily
 * scope can't be relied on for the bound routes — every one guards the family explicitly via
 * authorizeVehicle() (same reasoning as InventoryController::show()).
 */
class VehiclesController extends Controller
{
    /** How many odometer readings the Overview history shows. */
    private const MILEAGE_HISTORY_LIMIT = 30;

    /** My Cars: one card per vehicle (make/model, year · engine, current mileage, placeholder status). */
    public function index(): Response
    {
        $vehicles = Vehicle::query()->orderBy('make')->orderBy('model')->get()
            ->map(fn (Vehicle $v) => $this->cardPayload($v))->values();

        return Inertia::render('vehicles/index', [
            'vehicles' => $vehicles,
        ]);
    }

    /** Add Vehicle: the empty form. */
    public function create(): Response
    {
        return Inertia::render('vehicles/form', [
            'vehicle' => null,
            'members' => $this->memberOptions(),
            'fuelTypes' => Vehicle::FUEL_TYPES,
            'transmissions' => Vehicle::TRANSMISSIONS,
        ]);
    }

    /** Create a vehicle and open its odometer history with the mileage the user entered. */
    public function store(Request $request): RedirectResponse
    {
        $data = $this->validateVehicle($request);
        $data['current_mileage'] = (int) ($data['current_mileage'] ?? 0);
        $data['created_by'] = $request->user()->id;

        $vehicle = Vehicle::create($data);

        // Every vehicle starts its odometer history with an opening reading, so the history and
        // current_mileage agree from the very first day (the overview never shows an empty log next to a
        // non-zero odometer).
        $this->logMileage($vehicle, $vehicle->current_mileage, $request->user()->id);

        return redirect()->route('vehicles.show', $vehicle);
    }

    /** Vehicle Overview: big current mileage, the quick-update controls (client-side) and history. */
    public function show(Request $request, Vehicle $vehicle): Response
    {
        $this->authorizeVehicle($vehicle);

        $names = $this->memberNames();
        $history = $vehicle->mileageLogs()->limit(self::MILEAGE_HISTORY_LIMIT)->get()
            ->map(fn (VehicleMileageLog $l) => [
                ...$l->toPayload(),
                'recorder' => $names[$l->recorded_by] ?? null,
            ])->values();

        return Inertia::render('vehicles/overview', [
            'vehicle' => $this->cardPayload($vehicle),
            'owner' => $vehicle->owner_id ? ($names[$vehicle->owner_id] ?? null) : null,
            'primaryDriver' => $vehicle->primary_driver_id ? ($names[$vehicle->primary_driver_id] ?? null) : null,
            'history' => $history,
        ]);
    }

    /** Edit Vehicle: the form pre-filled. Mileage is NOT edited here — it is managed on the Overview. */
    public function edit(Request $request, Vehicle $vehicle): Response
    {
        $this->authorizeVehicle($vehicle);

        return Inertia::render('vehicles/form', [
            'vehicle' => $this->cardPayload($vehicle),
            'members' => $this->memberOptions(),
            'fuelTypes' => Vehicle::FUEL_TYPES,
            'transmissions' => Vehicle::TRANSMISSIONS,
        ]);
    }

    /**
     * Edit the vehicle's identity / ownership / obligations. Deliberately does NOT touch current_mileage:
     * the odometer is first-class and only ever moves through updateMileage(), which enforces the
     * no-backwards rule and writes a history row — so there is exactly one path that changes mileage.
     */
    public function update(Request $request, Vehicle $vehicle): RedirectResponse
    {
        $this->authorizeVehicle($vehicle);
        $data = $this->validateVehicle($request);
        unset($data['current_mileage']);   // odometer is managed separately (see updateMileage)

        $vehicle->update($data);

        return back();
    }

    /**
     * Quick mileage update: the daily path from the Overview (a +10/+50/+100/+500 preset or a manual
     * entry, both submitted as the new ABSOLUTE odometer reading). Logs the reading and moves
     * current_mileage.
     *
     * Mileage never goes backwards silently. A reading below the current odometer is BLOCKED with a
     * validation error unless the caller passes `correction: true` — the explicit "this fixes a wrong
     * reading" escape hatch. We chose block-unless-explicit (not a silent warning) because a fat-fingered
     * low reading would otherwise corrupt every future maintenance-due calculation, and those errors are
     * expensive to notice later; making the user confirm a correction is the cheap, safe default.
     */
    public function updateMileage(Request $request, Vehicle $vehicle): RedirectResponse
    {
        $this->authorizeVehicle($vehicle);

        $data = $request->validate([
            'mileage' => ['required', 'integer', 'min:0'],
            'correction' => ['sometimes', 'boolean'],
        ]);

        $mileage = (int) $data['mileage'];
        $isCorrection = (bool) ($data['correction'] ?? false);

        if ($mileage < $vehicle->current_mileage && ! $isCorrection) {
            throw ValidationException::withMessages([
                'mileage' => __('vehicles.mileage_backwards', ['current' => $vehicle->current_mileage]),
            ]);
        }

        // A correction (even a lower one) is authoritative: it becomes the new odometer and is recorded
        // in the history like any other reading. A normal update is monotonic by the guard above.
        $vehicle->update(['current_mileage' => $mileage]);
        $this->logMileage($vehicle, $mileage, $request->user()->id);

        return back();
    }

    /** Delete a vehicle. Its mileage log rows cascade (FK), so history is removed with it, never orphaned. */
    public function destroy(Request $request, Vehicle $vehicle): RedirectResponse
    {
        $this->authorizeVehicle($vehicle);
        StoredImage::delete($vehicle->photo_path);   // free the R2 objects before the row goes
        $vehicle->delete();

        return redirect()->route('vehicles.index');
    }

    // ---- Cover photo (R2, same pattern as project covers / spend receipts) ------------------------------

    public function uploadPhoto(Request $request, Vehicle $vehicle): RedirectResponse
    {
        $this->authorizeVehicle($vehicle);
        $request->validate([
            'photo' => ['required', 'image', 'max:12288'],       // full image, up to 12 MB (client compresses first)
            'photo_thumb' => ['nullable', 'image', 'max:4096'],  // small client-generated variant for cards
        ]);

        StoredImage::delete($vehicle->photo_path);
        $vehicle->update([
            'photo_path' => StoredImage::store($request->file('photo'), $request->file('photo_thumb'), 'vehicles/'.CurrentFamily::id()),
        ]);

        return back();
    }

    public function photoFile(Request $request, Vehicle $vehicle): StreamedResponse
    {
        $this->authorizeVehicle($vehicle);
        abort_unless($vehicle->photo_path, 404);

        // ?v=thumb serves the small variant (used by list cards); default serves the full image.
        return StoredImage::response($vehicle->photo_path, $request->query('v'));
    }

    public function deletePhoto(Request $request, Vehicle $vehicle): RedirectResponse
    {
        $this->authorizeVehicle($vehicle);
        StoredImage::delete($vehicle->photo_path);
        $vehicle->update(['photo_path' => null]);

        return back();
    }

    // ---- helpers ---------------------------------------------------------------------------------------

    /**
     * Shared validation for Add/Edit. current_mileage is only consumed on store (see update()). fuel_type
     * / transmission validate against the model's code lists; everything optional but make/model.
     *
     * @return array<string, mixed>
     */
    private function validateVehicle(Request $request): array
    {
        return $request->validate([
            'make' => ['required', 'string', 'max:80'],
            'model' => ['required', 'string', 'max:80'],
            'year' => ['nullable', 'integer', 'min:1900', 'max:2100'],
            'version' => ['nullable', 'string', 'max:80'],
            'fuel_type' => ['nullable', Rule::in(Vehicle::FUEL_TYPES)],
            'transmission' => ['nullable', Rule::in(Vehicle::TRANSMISSIONS)],
            'vin' => ['nullable', 'string', 'max:40'],
            'registration_number' => ['nullable', 'string', 'max:40'],
            'color' => ['nullable', 'string', 'max:40'],
            'purchase_date' => ['nullable', 'date'],
            'purchase_price' => ['nullable', 'numeric', 'min:0'],
            'owner_id' => ['nullable', 'integer', Rule::in($this->memberIds())],
            'primary_driver_id' => ['nullable', 'integer', Rule::in($this->memberIds())],
            'current_mileage' => ['nullable', 'integer', 'min:0'],
            'license_plate' => ['nullable', 'string', 'max:40'],
            'insurance_company' => ['nullable', 'string', 'max:80'],
            'road_tax_status' => ['nullable', 'string', 'max:80'],
            'inspection_status' => ['nullable', 'string', 'max:80'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
    }

    /** The card/detail wire shape: the model payload plus the auth-gated photo URLs (built here). */
    private function cardPayload(Vehicle $vehicle): array
    {
        return [
            ...$vehicle->toPayload(),
            // Thumb for cards, full image for the overview hero. Null when there is no photo.
            'photoThumbUrl' => $vehicle->photo_path ? route('vehicles.photo.show', $vehicle).'?v=thumb' : null,
            'photoUrl' => $vehicle->photo_path ? route('vehicles.photo.show', $vehicle) : null,
        ];
    }

    /** Write one odometer reading. family_id is filled by BelongsToFamily on create. */
    private function logMileage(Vehicle $vehicle, int $mileage, ?int $userId): void
    {
        VehicleMileageLog::create([
            'vehicle_id' => $vehicle->id,
            'mileage' => $mileage,
            'recorded_by' => $userId,
            'recorded_at' => now(),
        ]);
    }

    /**
     * Guard a vehicle belongs to the active family (route-model binding runs before the tenant is set,
     * so the global scope can't be relied on here — same reasoning as InventoryController::show()).
     */
    private function authorizeVehicle(Vehicle $vehicle): void
    {
        abort_unless((int) $vehicle->family_id === (int) CurrentFamily::id(), 404);
    }

    /** id => name for the active family's members (owner/driver labels + the log recorder fallback). */
    private function memberNames(): array
    {
        return Family::findOrFail(CurrentFamily::id())->members->pluck('name', 'id')->all();
    }

    /** Valid owner/driver ids for the family (for the `in:` validation rules). */
    private function memberIds(): array
    {
        return Family::findOrFail(CurrentFamily::id())->members->pluck('id')->all();
    }

    /** [{id,name}] for the owner/driver pickers on the form. */
    private function memberOptions(): array
    {
        return Family::findOrFail(CurrentFamily::id())->members
            ->map(fn ($m) => ['id' => $m->id, 'name' => $m->name])->values()->all();
    }
}
