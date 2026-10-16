<?php

namespace Modules\ExpenseImport;

use App\Support\Tenancy\BelongsToFamily;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ImportRow extends Model
{
    use BelongsToFamily;

    protected $table = 'expense_import_rows';

    protected $fillable = ['family_id', 'batch_id', 'spent_at', 'amount', 'currency', 'merchant', 'reference', 'fingerprint', 'kind', 'flag', 'selected', 'category', 'ai_tried', 'spend_id'];

    protected $casts = ['spent_at' => 'date', 'amount' => 'integer', 'selected' => 'boolean', 'ai_tried' => 'boolean'];

    public function batch(): BelongsTo
    {
        return $this->belongsTo(ImportBatch::class, 'batch_id');
    }
}
