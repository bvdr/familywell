<?php

namespace Modules\Vehicles;

use App\Models\User;
use App\Support\Media\StoredImage;
use App\Support\Tenancy\BelongsToFamily;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Modules\Tasks\Task;

/**
 * A vehicle document (P4): registration, insurance, inspection, warranty, an invoice, the manual, road
 * tax… Each has a type, an optional expiry date and a single attached file (PDF/photo) on R2. When the
 * expiry approaches, the document can spawn ONE renewal Task in the existing Tasks module — idempotent via
 * `renewal_task_id` (see DocumentRenewalTasks), so the daily sweep and the manual "Create renewal task"
 * button never produce a duplicate.
 */
class VehicleDocument extends Model
{
    use BelongsToFamily;

    protected $table = 'vehicles_documents';

    protected $fillable = [
        'vehicle_id', 'type', 'title', 'expires_on', 'file_path', 'file_name', 'notes',
        'renewal_task_id', 'created_by', 'family_id',
    ];

    protected $casts = [
        'expires_on' => 'date',
    ];

    // The document types the picker offers. Stored as plain strings (an unknown value still renders via the
    // client's fallback label), so adding a type here is a one-line change, never a migration.
    public const TYPES = [
        'registration', 'insurance', 'inspection', 'purchase_contract', 'warranty',
        'service_invoice', 'repair_invoice', 'manual', 'road_tax', 'other',
    ];

    // How many days before expiry a document is considered "approaching" and worth a renewal task. Shared by
    // the daily sweep and the client's expiry badge, so both agree on what "expiring soon" means.
    public const EXPIRY_LEAD_DAYS = 30;

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class, 'vehicle_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** The open renewal task this document spawned (may be stale — DocumentRenewalTasks self-heals). */
    public function renewalTask(): BelongsTo
    {
        return $this->belongsTo(Task::class, 'renewal_task_id');
    }

    /**
     * True when the document has an expiry that is within EXPIRY_LEAD_DAYS from `today` (or already past).
     * This is the trigger for spawning a renewal task; a document with no expiry never triggers.
     */
    /**
     * Store a file for this document, replacing any previous one (its R2 objects are freed). Shared by the
     * Documents form and the MCP tools. $thumb is the optional client-made preview for images.
     */
    public function attachFile(UploadedFile $file, ?UploadedFile $thumb = null): void
    {
        StoredImage::delete($this->file_path);
        $this->update([
            'file_path' => StoredImage::store($file, $thumb, 'vehicles/'.$this->family_id.'/documents'),
            'file_name' => $file->getClientOriginalName(),
        ]);
    }

    public function isExpiring(?Carbon $today = null): bool
    {
        if (! $this->expires_on) {
            return false;
        }
        $today ??= Carbon::today();

        return $this->expires_on->copy()->startOfDay()->lte($today->copy()->addDays(self::EXPIRY_LEAD_DAYS));
    }

    /**
     * The wire shape every screen renders a document from. camelCase for the client. `fileUrl` is auth-gated
     * (built by the controller), so it's passed in. `expiring` is the derived badge state.
     *
     * @return array<string, mixed>
     */
    public function toPayload(?string $fileUrl = null, ?Carbon $today = null): array
    {
        return [
            'id' => $this->id,
            'vehicleId' => $this->vehicle_id,
            'type' => $this->type,
            'title' => $this->title,
            'expiresOn' => optional($this->expires_on)->toDateString(),
            'expiring' => $this->isExpiring($today),
            'hasFile' => $this->file_path !== null,
            'fileName' => $this->file_name,
            'fileUrl' => $fileUrl,
            'notes' => $this->notes,
            'hasRenewalTask' => $this->renewal_task_id !== null,
            'createdBy' => $this->created_by,
        ];
    }
}
