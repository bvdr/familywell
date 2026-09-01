<?php

namespace Modules\Vehicles;

use App\Http\Controllers\Controller;
use App\Models\Family;
use App\Support\Media\StoredImage;
use App\Support\Modules\ModuleRegistry;
use App\Support\Tenancy\CurrentFamily;
use Illuminate\Http\JsonResponse;
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
    public function index(Request $request): Response
    {
        // Eager-load the schedules the card summary rolls up, so My Cars stays O(1) queries as cars grow.
        $vehicles = Vehicle::query()->with('maintenanceSchedules')->orderBy('make')->orderBy('model')->get()
            ->map(fn (Vehicle $v) => $this->cardPayload($v))->values();

        return Inertia::render('vehicles/index', [
            'vehicles' => $vehicles,
            'unitSystem' => VehicleSetting::current()->unit_system,
            // The settings gear is admin-only (writes are gated server-side); hide the control for members.
            'canManage' => $request->user()->can('manageMembers', Family::findOrFail(CurrentFamily::id())),
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

    /** Vehicle Overview: big mileage + overall status, Next up, Recent events, quick actions and history. */
    public function show(Request $request, Vehicle $vehicle): Response
    {
        $this->authorizeVehicle($vehicle);

        // Eager-load the relations the P5 roll-up reads (status + next + recent), so the Overview stays a
        // handful of queries regardless of how much history a car has accrued.
        $vehicle->load(['maintenanceSchedules', 'documents', 'maintenanceHistory', 'repairs', 'partRequests']);

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
            'unitSystem' => VehicleSetting::current()->unit_system,
            // P5: the combined Good/Attention roll-up (maintenance due/overdue + document expiries), the
            // soonest maintenance + document to come up, and the recent maintenance/repair events with cost.
            'status' => VehicleOverview::status($vehicle),
            'next' => VehicleOverview::next($vehicle),
            'recent' => VehicleOverview::recent($vehicle),
            // The at-a-glance counts the Overview's Repairs / Documents / Notes tiles show (from the loaded
            // relations, so no extra queries).
            'sections' => [
                'repairs' => $vehicle->repairs->count(),
                'documents' => $vehicle->documents->count(),
                'documentsExpiring' => $vehicle->documents->filter(fn (VehicleDocument $d) => $d->isExpiring())->count(),
                'notes' => $vehicle->noteLinks()->count(),
            ],
            // V1.1: parts requested/bought via Shopping for this car, plus whether Shopping is on (the
            // "Add part" surface only appears when there's a shopping list to add to).
            'parts' => $vehicle->partRequests->map(fn (VehiclePartRequest $p) => $p->toPayload())->values(),
            'canShopping' => app(ModuleRegistry::class)->isEnabled('shopping', (int) CurrentFamily::id()),
        ]);
    }

    /**
     * Vehicle Timeline (spec 16): the car's whole life as one chronological feed across every event type,
     * grouped by year/month on the client. All reads go through the family-scoped relations (+ the guarded
     * vehicle), so the feed is inherently family-scoped.
     */
    public function timeline(Request $request, Vehicle $vehicle): Response
    {
        $this->authorizeVehicle($vehicle);

        // Eager-load every source the merge reads (incl. the linked provider for maintenance/repairs, so
        // providerName() resolves without an N+1), so the timeline is a fixed handful of queries.
        $vehicle->load(['maintenanceHistory.serviceProvider', 'repairs.serviceProvider', 'documents', 'mileageLogs', 'tireSets']);

        return Inertia::render('vehicles/timeline', [
            'vehicle' => $this->cardPayload($vehicle),
            'unitSystem' => VehicleSetting::current()->unit_system,
            'entries' => VehicleTimeline::forVehicle($vehicle),
        ]);
    }

    /**
     * JSON feed for the home-screen dashboard widget: one compact line per car (name / mileage / status)
     * plus only the things needing attention (maintenance due, expiring documents) — EXCEPTIONS/next-ups,
     * never the full picture. Family-scoped (BelongsToFamily) and gated by `module:vehicles` on the route,
     * so a family without the module simply 404s and the widget stays hidden. Cars with nothing live are
     * omitted, so a healthy fleet returns an empty list and the widget renders nothing.
     */
    public function widget(): JsonResponse
    {
        $unit = VehicleSetting::current()->unit_system;
        $vehicles = Vehicle::query()->with(['maintenanceSchedules', 'documents'])->orderBy('make')->orderBy('model')->get();

        $cars = $vehicles->map(function (Vehicle $vehicle) use ($unit) {
            $status = VehicleOverview::status($vehicle);
            if ($status['overall'] !== 'attention') {
                return null; // exceptions only — a car with nothing live never appears
            }

            $next = VehicleOverview::next($vehicle);

            return [
                'id' => $vehicle->id,
                'name' => trim("{$vehicle->make} {$vehicle->model}"),
                'mileageText' => VehicleUnits::distanceText((int) $vehicle->current_mileage, $unit),
                'attention' => $status['maintenanceAttention'] + $status['documentsExpiring'],
                'items' => $this->widgetItems($vehicle, $next, $unit),
            ];
        })->filter()->values();

        return response()->json(['cars' => $cars]);
    }

    /**
     * The short "needs attention" lines for one car in the widget: due/overdue maintenance schedules
     * (name + km/date remaining) and expiring documents (title + expiry). A few, most-urgent first.
     *
     * @param  array{maintenance:?array<string,mixed>, document:?array<string,mixed>}  $next
     * @return list<array<string,mixed>>
     */
    private function widgetItems(Vehicle $vehicle, array $next, string $unit): array
    {
        $items = [];

        // Maintenance that needs attention now (due/overdue) — most urgent first.
        $mileage = (int) $vehicle->current_mileage;
        $due = $vehicle->maintenanceSchedules
            ->filter(fn (VehicleMaintenanceSchedule $s) => $s->active
                && MaintenanceStatus::needsAttention($s->statusFor($mileage)['status']))
            ->sortByDesc(fn (VehicleMaintenanceSchedule $s) => MaintenanceStatus::rank($s->statusFor($mileage)['status']));

        foreach ($due as $schedule) {
            $s = $schedule->statusFor($mileage);
            $items[] = [
                'kind' => 'maintenance',
                'label' => $schedule->name,
                'status' => $s['status'],
                // A ready-to-show "how far off" line, unit-aware (e.g. "6,860 km" / "due now" / "overdue").
                'detail' => $this->maintenanceDetail($s, $unit),
            ];
        }

        // Expiring documents (soonest first).
        $expiring = $vehicle->documents
            ->filter(fn (VehicleDocument $d) => $d->isExpiring())
            ->sortBy(fn (VehicleDocument $d) => optional($d->expires_on)->toDateString());

        foreach ($expiring as $document) {
            $items[] = [
                'kind' => 'document',
                'label' => $document->title,
                'type' => $document->type,
                // "Nov 4" — a short expiry, matching the spec's widget example.
                'detail' => $document->expires_on ? $document->expires_on->format('M j') : '',
            ];
        }

        return $items;
    }

    /**
     * A concise, unit-aware "how far off" line for a due schedule in the widget: the nearer trigger's
     * distance (or days) remaining, or a plain "due now" / "overdue" when it's at/past the point.
     */
    private function maintenanceDetail(array $s, string $unit): string
    {
        $km = $s['remainingKm'];
        $days = $s['remainingDays'];
        $useKm = $km !== null && ($days === null || $km <= $days);

        if ($useKm && $km !== null) {
            if ($km > 0) {
                return VehicleUnits::distanceText($km, $unit);
            }

            return $km === 0 ? 'due now' : 'overdue';
        }
        if ($days !== null) {
            if ($days > 0) {
                return "in {$days}d";
            }

            return $days === 0 ? 'due now' : 'overdue';
        }

        return 'due now';
    }

    /**
     * Vehicles Settings: the family's display unit system, notification condition toggles, and customisable
     * default maintenance-template intervals. Readable by every member; the write endpoint is admin-only, so
     * `canManage` gates the controls in the UI (server enforces it too).
     */
    public function settings(Request $request): Response
    {
        $family = Family::findOrFail(CurrentFamily::id());

        return Inertia::render('vehicles/settings', [
            'settings' => VehicleSetting::current()->toPayload(),
            'unitSystems' => VehicleSetting::UNIT_SYSTEMS,
            'canManage' => $request->user()->can('manageMembers', $family),
        ]);
    }

    /**
     * Persist a settings change. Admin-only (hard `authorize`, mirroring the Inventory/Spending settings).
     * Every field is optional so the screen can instant-save one control at a time; template intervals
     * arrive as a {key: {km, months}} map and are stored verbatim (nulls mean "not tracked by that trigger").
     */
    public function updateSettings(Request $request): RedirectResponse
    {
        $family = Family::findOrFail(CurrentFamily::id());
        $this->authorize('manageMembers', $family);

        $data = $request->validate([
            'unit_system' => ['sometimes', Rule::in(VehicleSetting::UNIT_SYSTEMS)],
            'notify_maintenance' => ['sometimes', 'boolean'],
            'notify_documents' => ['sometimes', 'boolean'],
            'notify_mileage_milestone' => ['sometimes', 'boolean'],
            // A single-key patch of custom template intervals, MERGED into the stored map below.
            'template_intervals' => ['sometimes', 'array'],
            'template_intervals.*.km' => ['nullable', 'integer', 'min:0'],
            'template_intervals.*.months' => ['nullable', 'integer', 'min:0'],
            // "Reset this template to the shipped default" — removes its override key.
            'template_reset' => ['sometimes', Rule::in(array_keys(MaintenanceTemplate::TEMPLATES))],
        ]);

        $settings = VehicleSetting::current();

        // Template intervals are a JSON map; merge (not replace) so instant-saving one row never drops the
        // others, and only keys the module ships are kept so a stray key can't bloat the blob.
        $reset = $data['template_reset'] ?? null;
        $patch = array_intersect_key($data['template_intervals'] ?? [], MaintenanceTemplate::TEMPLATES);
        unset($data['template_intervals'], $data['template_reset']);

        if ($patch !== [] || $reset !== null) {
            $map = $settings->template_intervals ?? [];
            $map = array_merge($map, $patch);
            if ($reset !== null) {
                unset($map[$reset]);
            }
            $data['template_intervals'] = $map ?: null;
        }

        $settings->update($data);

        return back();
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

    /**
     * A vehicle-level ad-hoc expense (the Overview "Add expense" quick action). Records a Spend linked to
     * the car via the P3 bridge — no maintenance/repair context — so "how much on this car" stays complete.
     * Gated on Spending being enabled; a no-op there (VehicleExpenses::record returns null).
     */
    public function addExpense(Request $request, Vehicle $vehicle): RedirectResponse
    {
        $this->authorizeVehicle($vehicle);
        abort_unless(app(ModuleRegistry::class)->isEnabled('spending', (int) CurrentFamily::id()), 404);

        $data = $request->validate([
            'amount' => ['required', 'numeric', 'gt:0'],
            'note' => ['nullable', 'string', 'max:255'],
            'spent_at' => ['nullable', 'date'],
        ]);

        VehicleExpenses::record(
            $vehicle,
            $request->user(),
            (float) $data['amount'],
            $data['note'] ?: trim("{$vehicle->make} {$vehicle->model}"),
            $data['spent_at'] ?? now()->toDateString(),
        );

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
