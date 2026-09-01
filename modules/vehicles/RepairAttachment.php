<?php

namespace Modules\Vehicles;

use App\Support\Tenancy\BelongsToFamily;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One file attached to a repair (invoice or photo). `path` is the R2 object key (family-namespaced),
 * `name` the original filename so a PDF downloads sensibly. Stored/served/deleted through StoredImage —
 * the same mechanism as spend receipts (which also handles the optional card thumbnail for images).
 */
class RepairAttachment extends Model
{
    use BelongsToFamily;

    protected $table = 'vehicles_repair_attachments';

    protected $fillable = ['repair_id', 'path', 'name', 'family_id'];

    public function repair(): BelongsTo
    {
        return $this->belongsTo(Repair::class, 'repair_id');
    }
}
