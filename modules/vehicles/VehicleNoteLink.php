<?php

namespace Modules\Vehicles;

use App\Support\Tenancy\BelongsToFamily;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Notes\Note;

/**
 * Attaches an existing-module Note to a vehicle (P4). Owned by the Vehicles module, so the Notes module
 * stays unaware of vehicles — this is the sole bridge. Reusing the real Notes model means vehicle notes get
 * markdown-lite, sharing and the notes surface for free, rather than a bespoke notes engine.
 */
class VehicleNoteLink extends Model
{
    use BelongsToFamily;

    protected $table = 'vehicles_note_links';

    protected $fillable = ['vehicle_id', 'note_id', 'family_id'];

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class, 'vehicle_id');
    }

    public function note(): BelongsTo
    {
        return $this->belongsTo(Note::class, 'note_id');
    }
}
