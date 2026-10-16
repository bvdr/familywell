<?php

namespace Modules\ExpenseImport;

use App\Support\Tenancy\BelongsToFamily;
use Illuminate\Database\Eloquent\Model;

// A person's private add-expense link (the iPhone Shortcut posts each Apple Pay payment to it). One per person
// per family; generating again rotates it, so an old link stops working. Mirrors Modules\Calendar\CalendarFeed.
class ImportToken extends Model
{
    use BelongsToFamily;

    protected $table = 'expense_import_tokens';

    protected $fillable = ['family_id', 'user_id', 'token', 'last_used_at'];

    protected $casts = ['last_used_at' => 'datetime'];

    public static function urlFor(int $userId): ?string
    {
        $token = static::where('user_id', $userId)->value('token');

        return $token ? route('expense_import.pay', $token) : null;
    }

    public static function rotate(int $userId): self
    {
        $row = static::firstOrNew(['user_id' => $userId]);
        $row->token = bin2hex(random_bytes(24)); // 48 hex chars, unguessable
        $row->save();

        return $row;
    }
}
