<?php

namespace Modules\ExpenseImport;

use App\Support\Tenancy\BelongsToFamily;
use Illuminate\Database\Eloquent\Model;

// A statement format the family mapped by hand, remembered by its header row so the next statement from the
// same bank goes straight to review. `mapping` holds column indexes: date, amount | debit+credit, currency |
// fixed_currency, merchant, reference.
class ImportMapping extends Model
{
    use BelongsToFamily;

    protected $table = 'expense_import_mappings';

    protected $fillable = ['family_id', 'signature', 'label', 'mapping'];

    protected $casts = ['mapping' => 'array'];
}
