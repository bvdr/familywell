<?php

namespace Modules\ExpenseImport;

use App\Support\Tenancy\BelongsToFamily;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

// One statement upload, or one person's Apple Pay review queue. Private to the person who imported it (bank
// statements are personal); what it creates are ordinary Spending expenses everyone with access to the list sees.
class ImportBatch extends Model
{
    use BelongsToFamily;

    public const MAPPING = 'mapping';

    public const REVIEW = 'review';

    public const IMPORTED = 'imported';

    public const UNDONE = 'undone';

    protected $table = 'expense_import_batches';

    protected $fillable = ['family_id', 'user_id', 'source', 'status', 'bank', 'filename', 'event_id', 'payer_key', 'raw', 'imported_at', 'undone_at'];

    protected $casts = ['raw' => 'array', 'imported_at' => 'datetime', 'undone_at' => 'datetime'];

    public function rows(): HasMany
    {
        return $this->hasMany(ImportRow::class, 'batch_id')->orderBy('spent_at')->orderBy('id');
    }
}
