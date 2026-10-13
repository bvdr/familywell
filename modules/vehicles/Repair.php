<?php

namespace Modules\Vehicles;

use App\Models\User;
use App\Support\Media\StoredImage;
use App\Support\Tenancy\BelongsToFamily;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Http\UploadedFile;

/**
 * An unplanned repair (P4) — SEPARATE from the recurring maintenance schedules of P2/P3. A repair is a
 * one-off event that already happened: what was fixed, when, at what odometer, what it cost and where. It
 * forms the car's permanent history and never recurs, so unlike a maintenance schedule it has no interval
 * and no "next due" — it's a record, not a rule. A repair with a cost mirrors into Spending exactly like a
 * maintenance completion (via VehicleExpenses), and can carry attachments (invoice / photo) on R2.
 */
class Repair extends Model
{
    use BelongsToFamily;

    // 30-day trash (VehiclesTrash); attachments + their files are kept until trash:purge.
    use SoftDeletes;

    protected $table = 'vehicles_repairs';

    protected $fillable = [
        'vehicle_id', 'title', 'occurred_on', 'mileage', 'cost', 'provider', 'service_provider_id',
        'notes', 'created_by', 'family_id',
    ];

    protected $casts = [
        'occurred_on' => 'date',
        'mileage' => 'integer',
        'cost' => 'float',
    ];

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class, 'vehicle_id');
    }

    /** Add one attachment (invoice/photo). Shared by the Repairs form and the MCP tools. */
    public function attachFile(UploadedFile $file, ?UploadedFile $thumb = null): RepairAttachment
    {
        return $this->attachments()->create([
            'path' => StoredImage::store($file, $thumb, 'vehicles/'.$this->family_id.'/repairs'),
            'name' => $file->getClientOriginalName(),
        ]);
    }

    /** Move the repair (with its attachments) to the 30-day trash. Web + MCP. */
    public function deleteWithFiles(): void
    {
        $this->delete();
    }

    protected static function booted(): void
    {
        // Gone for good: the attachment rows cascade in the DB, their storage objects don't.
        static::forceDeleting(fn (self $r) => $r->attachments->each(fn ($a) => StoredImage::delete($a->path)));
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(RepairAttachment::class)->latest('id');
    }

    /** The linked service provider (V1.1), when this repair points at one instead of free text. */
    public function serviceProvider(): BelongsTo
    {
        return $this->belongsTo(ServiceProvider::class, 'service_provider_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * The garage/provider name to display: the linked provider's name when set, else the free-text
     * fallback (quick entry / pre-V1.1 rows). Reads the `serviceProvider` relation — eager-load it where
     * many repairs render.
     */
    public function providerName(): ?string
    {
        return $this->serviceProvider?->name ?? $this->provider;
    }

    /**
     * The wire shape every screen renders a repair from. camelCase for the client. Attachment URLs are
     * auth-gated (built by the controller), so they're passed in rather than derived here.
     *
     * @param  array<int, array{id:int, name:?string, url:string}>  $attachments
     * @return array<string, mixed>
     */
    public function toPayload(array $attachments = []): array
    {
        return [
            'id' => $this->id,
            'vehicleId' => $this->vehicle_id,
            'title' => $this->title,
            'occurredOn' => optional($this->occurred_on)->toDateString(),
            'mileage' => $this->mileage,
            'cost' => $this->cost,
            // Resolved display name (linked provider wins over free text).
            'provider' => $this->providerName(),
            'serviceProviderId' => $this->service_provider_id,
            'notes' => $this->notes,
            'attachments' => $attachments,
            'createdBy' => $this->created_by,
        ];
    }
}
