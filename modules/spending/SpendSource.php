<?php

namespace Modules\Spending;

use App\Support\Tenancy\BelongsToFamily;
use Illuminate\Database\Eloquent\Model;

// A reusable payment source for a family (e.g. "Wise" card, "cash Oana"). type = cash|card|bank|other.
class SpendSource extends Model
{
    use BelongsToFamily;

    public const TYPES = ['cash', 'card', 'bank', 'other'];

    protected $table = 'spending_sources';

    protected $fillable = ['name', 'type'];
}
