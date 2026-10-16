<?php

use App\Models\Family;
use App\Models\User;
use App\Support\Modules\ModuleRegistry;
use App\Support\Tenancy\CurrentFamily;
use Illuminate\Http\UploadedFile;
use Modules\ExpenseImport\ImportSetting;
use Modules\Spending\SpendSetting;

// A family with Spending + Expense import on (statements and the Apple Pay link enabled), EUR by default.
// [0] admin, [1] member.
function importFamily(array $settings = []): array
{
    config(['services.typesafe.key' => null]); // no AI unless a test turns it on
    $users = User::factory()->count(2)->create();
    $family = Family::factory()->create(['owner_id' => $users[0]->id]);
    foreach ($users as $i => $u) {
        $family->members()->attach($u, ['role' => $i === 0 ? 'admin' : 'member']);
    }
    session(['active_family_id' => $family->id]);
    test()->artisan('modules:migrate')->assertExitCode(0);
    app(ModuleRegistry::class)->enable('spending', $family->id);
    app(ModuleRegistry::class)->enable('expense_import', $family->id);
    CurrentFamily::set($family->id);
    SpendSetting::current()->update(['default_currency' => 'EUR']);
    ImportSetting::current()->update(['statements_enabled' => true, 'shortcut_enabled' => true, ...$settings]);

    return [$family, $users];
}

function statementFile(string $name, string $csv): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, $csv);
}

// A Wise statement (its documented CSV export layout): two card payments, money in, a currency conversion.
function wiseCsv(): string
{
    return implode("\n", [
        '"TransferWise ID",Date,Amount,Currency,Description,"Payment Reference","Running Balance","Exchange From","Exchange To","Exchange Rate","Payer Name","Payee Name","Payee Account Number",Merchant,"Card Last Four Digits","Card Holder Full Name",Attachment,Note,"Total fees"',
        'CARD-1001,05-10-2026,-45.20,EUR,"Card transaction of 45.20 EUR issued by Lidl Bucuresti",,100,,,,,,,"Lidl Bucuresti",1234,"Oana",,,0',
        'CARD-1002,06-10-2026,-12.00,EUR,"Card transaction of 12.00 EUR issued by Cafe Verde",,88,,,,,,,"Cafe Verde",1234,"Oana",,,0',
        'TRANSFER-77,07-10-2026,500.00,EUR,"Received money from Bogdan",,588,,,,"Bogdan",,,,,,,,0',
        'BALANCE-9,08-10-2026,-100.00,EUR,"Converted 100.00 EUR to 497.00 RON",,488,EUR,RON,4.97,,,,,,,,,0',
    ]);
}
