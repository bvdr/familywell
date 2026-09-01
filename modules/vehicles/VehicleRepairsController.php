<?php

namespace Modules\Vehicles;

use App\Http\Controllers\Controller;
use App\Support\Media\StoredImage;
use App\Support\Modules\ModuleRegistry;
use App\Support\Tenancy\CurrentFamily;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Phase 4: Repairs — the vehicle's unplanned, one-off fixes, forming its permanent history (SEPARATE from
 * the recurring maintenance of P2/P3). A repair records what/when/mileage/cost/garage, can carry invoice or
 * photo attachments (R2, via StoredImage — same mechanism as spend receipts), and — when it has a cost and
 * the user opts in — mirrors that cost into Spending linked to the car, reusing the P3 VehicleExpenses
 * bridge (no new Spending coupling).
 *
 * Same tenancy shape as the other vehicle controllers: route-model binding resolves {vehicle}/{repair}
 * before the `family` middleware sets the active tenant, so every action guards the family explicitly
 * instead of leaning on the global BelongsToFamily scope.
 */
class VehicleRepairsController extends Controller
{
    /** Repairs tab: the car's repair history, newest first, each with its attachments. */
    public function index(Request $request, Vehicle $vehicle): Response
    {
        $this->authorizeVehicle($vehicle);

        $repairs = $vehicle->repairs()->with('attachments')->get()
            ->map(fn (Repair $r) => $r->toPayload($this->attachmentPayloads($r)))->values();

        return Inertia::render('vehicles/repairs/index', [
            'vehicle' => $this->vehiclePayload($vehicle),
            'repairs' => $repairs,
            // Only offer "also add to Spending" when the family actually has Spending on.
            'canExpense' => app(ModuleRegistry::class)->isEnabled('spending', (int) CurrentFamily::id()),
        ]);
    }

    /** Record a repair (+ any attachments), optionally mirroring its cost into Spending. */
    public function store(Request $request, Vehicle $vehicle): RedirectResponse
    {
        $this->authorizeVehicle($vehicle);
        $data = $this->validateRepair($request);

        $repair = $vehicle->repairs()->create([
            'title' => $data['title'],
            'occurred_on' => $data['occurred_on'],
            'mileage' => $data['mileage'] ?? null,
            'cost' => $data['cost'] ?? null,
            'provider' => $data['provider'] ?? null,
            'notes' => $data['notes'] ?? null,
            'created_by' => $request->user()->id,
        ]);

        $this->storeAttachments($request, $repair);

        // A repair with a cost can become a Spending record linked to the car — but only when the user asks
        // (unlike a maintenance completion, where the cost is always the service's cost). Kept to store()
        // only, so editing a repair never risks creating a duplicate expense.
        if (($data['create_expense'] ?? false) && ! empty($data['cost'])) {
            VehicleExpenses::record(
                $vehicle,
                $request->user(),
                (float) $data['cost'],
                trim($data['title']),
                $data['occurred_on'],
            );
        }

        return back();
    }

    /** Edit a repair's details, add new attachments, and remove any the client listed. No expense side-effect. */
    public function update(Request $request, Vehicle $vehicle, Repair $repair): RedirectResponse
    {
        $this->authorizeRepair($vehicle, $repair);
        $data = $this->validateRepair($request);

        $repair->update([
            'title' => $data['title'],
            'occurred_on' => $data['occurred_on'],
            'mileage' => $data['mileage'] ?? null,
            'cost' => $data['cost'] ?? null,
            'provider' => $data['provider'] ?? null,
            'notes' => $data['notes'] ?? null,
        ]);

        $this->removeAttachments($request, $repair);
        $this->storeAttachments($request, $repair);

        return back();
    }

    /** Delete a repair. Its attachment R2 objects are freed first; the rows cascade with the repair. */
    public function destroy(Request $request, Vehicle $vehicle, Repair $repair): RedirectResponse
    {
        $this->authorizeRepair($vehicle, $repair);

        foreach ($repair->attachments as $a) {
            StoredImage::delete($a->path);
        }
        $repair->delete();

        return back();
    }

    /** Stream one repair attachment (image or PDF) from private R2, guarded to the family + repair + vehicle. */
    public function attachmentFile(Request $request, Vehicle $vehicle, Repair $repair, RepairAttachment $attachment): StreamedResponse
    {
        $this->authorizeRepair($vehicle, $repair);
        abort_unless((int) $attachment->repair_id === (int) $repair->id, 404);

        return StoredImage::response($attachment->path, $request->query('v'));
    }

    // ---- helpers ---------------------------------------------------------------------------------------

    /**
     * Shared validation for add/edit. Attachments accept images or PDFs (invoice/photo); an optional
     * client-generated thumbnail per image rides in attachments_thumb[] (aligned by index) for card previews.
     *
     * @return array<string, mixed>
     */
    private function validateRepair(Request $request): array
    {
        return $request->validate([
            'title' => ['required', 'string', 'max:120'],
            'occurred_on' => ['required', 'date'],
            'mileage' => ['nullable', 'integer', 'min:0'],
            'cost' => ['nullable', 'numeric', 'min:0'],
            'provider' => ['nullable', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'create_expense' => ['sometimes', 'boolean'],
            'attachments' => ['nullable', 'array', 'max:10'],
            'attachments.*' => ['file', 'mimes:pdf,jpg,jpeg,png,webp,heic', 'max:12288'], // up to 12 MB each
            'attachments_thumb' => ['nullable', 'array', 'max:10'],
            'attachments_thumb.*' => ['image', 'max:4096'],
            'remove_attachment_ids' => ['nullable', 'array'],
            'remove_attachment_ids.*' => ['integer'],
        ]);
    }

    /** Store any newly uploaded attachments (attachments[]) on R2, keeping the original filename. */
    private function storeAttachments(Request $request, Repair $repair): void
    {
        $thumbs = (array) $request->file('attachments_thumb', []);
        foreach ((array) $request->file('attachments', []) as $i => $file) {
            $repair->attachments()->create([
                'path' => StoredImage::store($file, $thumbs[$i] ?? null, 'vehicles/'.CurrentFamily::id().'/repairs'),
                'name' => $file->getClientOriginalName(),
            ]);
        }
    }

    /** Remove the attachments whose ids the client listed (freeing their R2 objects), scoped to this repair. */
    private function removeAttachments(Request $request, Repair $repair): void
    {
        $ids = array_filter((array) $request->input('remove_attachment_ids', []));
        if ($ids === []) {
            return;
        }
        foreach ($repair->attachments()->whereIn('id', $ids)->get() as $a) {
            StoredImage::delete($a->path);
            $a->delete();
        }
    }

    /**
     * The auth-gated attachment payloads for a repair: id, original name, and the streaming URL (thumb for
     * cards, full for the viewer). Built here because it needs the route + a per-request context.
     *
     * @return array<int, array{id:int, name:?string, url:string, thumbUrl:string}>
     */
    private function attachmentPayloads(Repair $repair): array
    {
        return $repair->attachments->map(fn (RepairAttachment $a) => [
            'id' => $a->id,
            'name' => $a->name,
            'url' => route('vehicles.repairs.attachment', [$repair->vehicle_id, $repair->id, $a->id]),
            'thumbUrl' => route('vehicles.repairs.attachment', [$repair->vehicle_id, $repair->id, $a->id]).'?v=thumb',
        ])->values()->all();
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

    /** Guard the vehicle's family AND that the repair belongs to this vehicle. */
    private function authorizeRepair(Vehicle $vehicle, Repair $repair): void
    {
        $this->authorizeVehicle($vehicle);
        abort_unless((int) $repair->vehicle_id === (int) $vehicle->id, 404);
    }
}
