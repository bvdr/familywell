<?php

namespace Modules\Vehicles;

use App\Http\Controllers\Controller;
use App\Support\Modules\ModuleRegistry;
use App\Support\Tenancy\CurrentFamily;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Notes\Note;

/**
 * Phase 4: Notes — attach notes to a vehicle by REUSING the existing Notes module (spec 10: "reuse the
 * existing Notes module rather than a bespoke notes engine"). A vehicle note is a real notes_notes row
 * (markdown-lite, shareable, family-visible by default), tied to the car through VehicleNoteLink. So notes
 * created here also live in the Notes surface, and the Notes module stays unaware of vehicles.
 *
 * Notes are attached at the vehicle level (the spec's minimum); attaching to a specific maintenance event /
 * repair / document is a natural follow-up on the same link table (add a nullable target column) and is
 * left for later so P4 stays contained.
 *
 * Same explicit-family-guard tenancy as the other vehicle controllers.
 */
class VehicleNotesController extends Controller
{
    /** Notes tab: the notes attached to this car, newest first. */
    public function index(Request $request, Vehicle $vehicle): Response
    {
        $this->authorizeVehicle($vehicle);

        $notes = $vehicle->noteLinks()->with('note')->latest('id')->get()
            ->filter(fn (VehicleNoteLink $l) => $l->note !== null)   // guard a note removed out-of-band
            ->map(fn (VehicleNoteLink $l) => $this->notePayload($l->note))->values();

        return Inertia::render('vehicles/notes/index', [
            'vehicle' => $this->vehiclePayload($vehicle),
            'notes' => $notes,
            // Creating/editing notes requires the Notes module; without it we still list any existing ones.
            'canManage' => app(ModuleRegistry::class)->isEnabled('notes', (int) CurrentFamily::id()),
        ]);
    }

    /** Create a note and attach it to the vehicle. */
    public function store(Request $request, Vehicle $vehicle): RedirectResponse
    {
        $this->authorizeVehicle($vehicle);
        abort_unless(app(ModuleRegistry::class)->isEnabled('notes', (int) CurrentFamily::id()), 404);

        $data = $request->validate([
            'title' => ['nullable', 'string', 'max:120'],
            'body' => ['required', 'string', 'max:20000'],
        ]);

        // A real Notes-module note (family_id filled by BelongsToFamily; family-visible by default), then the
        // link that makes it this car's note.
        $note = Note::create([
            'title' => $data['title'] ?? '',
            'body' => $data['body'],
            'created_by' => $request->user()->id,
        ]);
        $vehicle->noteLinks()->create(['note_id' => $note->id]);

        return back();
    }

    /** Edit a vehicle note's title/body (the underlying Notes-module note). */
    public function update(Request $request, Vehicle $vehicle, Note $note): RedirectResponse
    {
        $this->authorizeNote($vehicle, $note);

        $data = $request->validate([
            'title' => ['nullable', 'string', 'max:120'],
            'body' => ['required', 'string', 'max:20000'],
        ]);

        $note->update(['title' => $data['title'] ?? '', 'body' => $data['body']]);

        return back();
    }

    /** Detach and delete a vehicle note. Deleting the note cascades its link, so nothing is left dangling. */
    public function destroy(Request $request, Vehicle $vehicle, Note $note): RedirectResponse
    {
        $this->authorizeNote($vehicle, $note);
        $note->delete();   // notes_note_links.note_id is cascadeOnDelete

        return back();
    }

    // ---- helpers ---------------------------------------------------------------------------------------

    /** The wire shape a vehicle note renders from. */
    private function notePayload(Note $note): array
    {
        return [
            'id' => $note->id,
            'title' => $note->title,
            'body' => $note->body,
            'createdBy' => $note->created_by,
            'updatedAt' => optional($note->updated_at)->toIso8601String(),
        ];
    }

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

    /**
     * Guard the vehicle's family AND that the note is actually attached to this vehicle (via the link). This
     * ties an otherwise-unscoped {note} binding to the family's car, so another family's note can't be
     * reached even though the binding resolves before the tenant is set.
     */
    private function authorizeNote(Vehicle $vehicle, Note $note): void
    {
        $this->authorizeVehicle($vehicle);
        abort_unless($vehicle->noteLinks()->where('note_id', $note->id)->exists(), 404);
    }
}
