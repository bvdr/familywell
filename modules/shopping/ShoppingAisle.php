<?php

namespace Modules\Shopping;

use App\Support\Tenancy\BelongsToFamily;
use Illuminate\Database\Eloquent\Model;

class ShoppingAisle extends Model
{
    use BelongsToFamily;

    protected $table = 'shopping_aisles';

    protected $fillable = ['store', 'slug', 'name', 'icon', 'position'];
}
