<?php

namespace Modules\ExpenseImport;

use App\Http\Controllers\Controller;
use App\Models\Family;
use App\Models\User;
use App\Support\Billing\Billing;
use App\Support\Modules\ModuleRegistry;
use App\Support\Tenancy\CurrentFamily;
use App\Support\Tenancy\FamilyClock;
use App\Support\Translations;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Modules\ExpenseImport\Statement\Values;
use Modules\Spending\SpendingController;

// The personal add-expense link the iPhone Shortcut posts each Apple Pay payment to (iOS Shortcuts personal
// automation "Transaction": it hands the shortcut the amount and the merchant). No session: the unguessable
// token IS the authorization, like the calendar ICS feed. Every call re-checks what the web app would: the
// module and the link are switched on, the person is still in the family, the subscription is active.
//
// Body (JSON or form): amount (required; "50,00 RON", "RON 50.00", "€12.50" or a number), currency
// (optional, else read from amount, else the family default), merchant (optional), date (optional, today).
// The reply is one plain-text line, which the Shortcut shows as its notification.
class PaymentController extends Controller
{
    public function store(Request $request, string $token): Response
    {
        $row = ImportToken::withoutGlobalScope('family')->where('token', $token)->first();
        $user = $row ? User::find($row->user_id) : null;
        abort_unless(
            $row && $user
            && app(ModuleRegistry::class)->isEnabled('expense_import', $row->family_id)
            && app(ModuleRegistry::class)->isEnabled('spending', $row->family_id)
            && $user->families()->whereKey($row->family_id)->exists(), // leaving the family kills the link
            404,
        );
        CurrentFamily::set($row->family_id);
        abort_unless(ImportSetting::current()->shortcut_enabled, 404);
        if (Billing::enabled() && ! Billing::hasAccess(Family::findOrFail($row->family_id))) {
            return $this->reply(402, 'expense_import.pay_no_subscription');
        }
        // Outside the web group: no SetLocale, so answer in the person's language here (as McpFamilyContext does).
        if ($user->locale && Translations::supported($user->locale)) {
            app()->setLocale($user->locale);
        }

        [$value, $codeInAmount] = Values::money($request->input('amount'));
        $currency = strtoupper((string) $request->input('currency')) ?: $codeInAmount ?: Importer::fallbackCurrency();
        if (! $value || ! in_array($currency, SpendingController::CURRENCIES, true)) {
            return $this->reply(422, 'expense_import.pay_bad_amount');
        }
        $merchant = Values::merchant((string) ($request->input('merchant') ?: $request->input('card') ?: 'Apple Pay'));
        $date = Values::date((string) $request->input('date')) ?? FamilyClock::today(); // CurrentFamily is the token's family (above)

        $row->forceFill(['last_used_at' => now()])->save();
        [$status] = Importer::payment($user, (int) round(abs($value) * 100), $currency, $merchant, $date);

        $amount = number_format(abs($value), 2).' '.$currency;
        $text = strtr(Translations::get('expense_import.pay_'.$status), [':amount' => $amount, ':merchant' => $merchant]);

        return response($text, $status === 'failed' ? 422 : 200, ['Content-Type' => 'text/plain; charset=utf-8']);
    }

    private function reply(int $status, string $key): Response
    {
        return response(Translations::get($key), $status, ['Content-Type' => 'text/plain; charset=utf-8']);
    }
}
