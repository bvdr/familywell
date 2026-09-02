<?php

namespace Modules\Vehicles;

use App\Http\Controllers\Controller;
use App\Support\Media\StoredImage;
use App\Support\Modules\ModuleRegistry;
use App\Support\Tenancy\CurrentFamily;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Phase 4: Documents — a vehicle's paperwork (registration, insurance, inspection, invoices, manual, road
 * tax…). Each has a type, an optional expiry, and a single attached PDF/photo on R2 (via StoredImage). A
 * document whose expiry is approaching can spawn ONE renewal Task in the existing Tasks module, idempotent
 * via `renewal_task_id` (DocumentRenewalTasks) — the manual button here and the daily sweep never duplicate.
 *
 * Same explicit-family-guard tenancy as the other vehicle controllers.
 */
class VehicleDocumentsController extends Controller
{
    /** Documents tab: the car's documents, expiring ones first, each with its file + expiry badge. */
    public function index(Request $request, Vehicle $vehicle): Response
    {
        $this->authorizeVehicle($vehicle);

        $documents = $vehicle->documents()->get()
            ->map(fn (VehicleDocument $d) => $d->toPayload($this->fileUrl($d)))->values();

        return Inertia::render('vehicles/documents/index', [
            'vehicle' => $this->vehiclePayload($vehicle),
            'documents' => $documents,
            'types' => VehicleDocument::TYPES,
            // Renewal tasks only make sense (and are only offered) when Tasks is enabled for the family.
            'canTask' => app(ModuleRegistry::class)->isEnabled('tasks', (int) CurrentFamily::id()),
        ]);
    }

    /** Add a document (+ its file). */
    public function store(Request $request, Vehicle $vehicle): RedirectResponse
    {
        $this->authorizeVehicle($vehicle);
        $data = $this->validateDocument($request);

        $document = $vehicle->documents()->create([
            'type' => $data['type'],
            'title' => $data['title'],
            'expires_on' => $data['expires_on'] ?? null,
            'notes' => $data['notes'] ?? null,
            'created_by' => $request->user()->id,
        ]);

        $this->storeFile($request, $document);

        return back();
    }

    /** Edit a document's details and, if a new file is uploaded, replace the stored one. */
    public function update(Request $request, Vehicle $vehicle, VehicleDocument $document): RedirectResponse
    {
        $this->authorizeDocument($vehicle, $document);
        $data = $this->validateDocument($request);

        $document->update([
            'type' => $data['type'],
            'title' => $data['title'],
            'expires_on' => $data['expires_on'] ?? null,
            'notes' => $data['notes'] ?? null,
        ]);

        $this->storeFile($request, $document);

        return back();
    }

    /** Delete a document. Its R2 file is freed first. */
    public function destroy(Request $request, Vehicle $vehicle, VehicleDocument $document): RedirectResponse
    {
        $this->authorizeDocument($vehicle, $document);
        StoredImage::delete($document->file_path);
        $document->delete();

        return back();
    }

    /** Stream a document's file (PDF/photo) from private R2, guarded to the family + document + vehicle. */
    public function fileShow(Request $request, Vehicle $vehicle, VehicleDocument $document): StreamedResponse
    {
        $this->authorizeDocument($vehicle, $document);
        abort_unless($document->file_path, 404);

        return StoredImage::response($document->file_path, $request->query('v'));
    }

    /**
     * Manually create the renewal task for this document (the "Create renewal task" action). Idempotent —
     * if one is already open it's a no-op, so the button and the daily sweep never collide. Allowed on any
     * document (a user may want the task before the 30-day window); the sweep only auto-fires within it.
     */
    public function createRenewalTask(Request $request, Vehicle $vehicle, VehicleDocument $document): RedirectResponse
    {
        $this->authorizeDocument($vehicle, $document);
        abort_unless(app(ModuleRegistry::class)->isEnabled('tasks', (int) CurrentFamily::id()), 404);

        DocumentRenewalTasks::ensureForExpiring($document, $vehicle, $request->user());

        return back();
    }

    // ---- helpers ---------------------------------------------------------------------------------------

    /**
     * Shared validation for add/edit. type validates against the code list; the file is a single PDF/photo.
     *
     * @return array<string, mixed>
     */
    private function validateDocument(Request $request): array
    {
        return $request->validate([
            'type' => ['required', Rule::in(VehicleDocument::TYPES)],
            'title' => ['required', 'string', 'max:120'],
            'expires_on' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'file' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png,webp,heic', 'max:12288'],  // up to 12 MB
            'file_thumb' => ['nullable', 'image', 'max:4096'],   // optional client thumb for image previews
        ]);
    }

    /** Store the uploaded file (replacing any existing one) and record its original name. */
    private function storeFile(Request $request, VehicleDocument $document): void
    {
        if (! $request->hasFile('file')) {
            return;
        }
        StoredImage::delete($document->file_path);   // replace the old file, if any
        $file = $request->file('file');
        $document->update([
            'file_path' => StoredImage::store($file, $request->file('file_thumb'), 'vehicles/'.CurrentFamily::id().'/documents'),
            'file_name' => $file->getClientOriginalName(),
        ]);
    }

    /** The auth-gated streaming URL for a document's file, or null when it has none. */
    private function fileUrl(VehicleDocument $document): ?string
    {
        return $document->file_path
            ? StoredImage::url('vehicles.documents.file', [$document->vehicle_id, $document->id], $document->file_path)
            : null;
    }

    /** The compact vehicle header shape every per-car screen needs. */
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

    /** Guard the vehicle's family AND that the document belongs to this vehicle. */
    private function authorizeDocument(Vehicle $vehicle, VehicleDocument $document): void
    {
        $this->authorizeVehicle($vehicle);
        abort_unless((int) $document->vehicle_id === (int) $vehicle->id, 404);
    }
}
