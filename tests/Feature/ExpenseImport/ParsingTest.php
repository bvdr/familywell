<?php

use Modules\ExpenseImport\Statement\Formats;
use Modules\ExpenseImport\Statement\Table;
use Modules\ExpenseImport\Statement\Values;

require_once __DIR__.'/helpers.php';

// The loose values banks and the Apple Shortcut send, and each bank layout we recognise.

it('reads money in both decimal styles, with currency codes and symbols', function (string $raw, ?float $value, ?string $currency) {
    expect(Values::money($raw))->toBe([$value, $currency]);
})->with([
    ['1.234,56', 1234.56, null],
    ['1,234.56', 1234.56, null],
    ['-50.00', -50.0, null],
    ['50,00 RON', 50.0, 'RON'],
    ['RON 50.00', 50.0, 'RON'],
    ['€12.50', 12.5, 'EUR'],
    ['12,5 lei', 12.5, 'RON'],
    ['(12.50)', -12.5, null],
    ['1.000', 1000.0, null],
    ['', null, null],
]);

it('reads dates the way statements write them, day first when ambiguous', function (string $raw, ?string $date) {
    expect(Values::date($raw))->toBe($date);
})->with([
    ['2026-10-05', '2026-10-05'],
    ['2026-10-05 12:34:56', '2026-10-05'],
    ['05.10.2026', '2026-10-05'],
    ['05-10-2026', '2026-10-05'],
    ['05/10/2026', '2026-10-05'],
    ['10/25/2026', '2026-10-25'], // can only be US-style
    ['20261005', '2026-10-05'],
    ['05 octombrie 2026', '2026-10-05'],
    ['5 Oct 2026', '2026-10-05'],
    ['46300', '2026-10-05'], // Excel serial
    ['not a date', null],
]);

it('cleans the shop name out of a card line', function () {
    expect(Values::merchant('Plata la POS non-BT cu card VISA 4567XXXX1234 MEGA IMAGE 0123 BUCURESTI RO TID:12345678'))
        ->toBe('MEGA IMAGE 0123 BUCURESTI RO');
});

it('recognises a Wise statement: card payments out, money in, conversions as transfers', function () {
    $table = Table::csv(wiseCsv());
    $found = Formats::detect($table);
    expect($found['format'])->toBe('wise')->and($found['label'])->toBe('Wise');

    $tx = Formats::read('wise', $table, $found['header']);
    expect($tx)->toHaveCount(4)
        ->and($tx[0])->toMatchArray(['date' => '2026-10-05', 'amount' => -4520, 'currency' => 'EUR', 'merchant' => 'Lidl Bucuresti', 'reference' => 'CARD-1001', 'kind' => 'expense'])
        ->and($tx[2]['kind'])->toBe('income')
        ->and($tx[3]['kind'])->toBe('transfer');
});

it('recognises a Revolut statement: fees count, reverted rows are dropped, refunds and top-ups are flagged', function () {
    $csv = implode("\n", [
        'Type,Product,Started Date,Completed Date,Description,Amount,Fee,Currency,State,Balance',
        'CARD_PAYMENT,Current,2026-10-05 10:00:00,2026-10-06 09:00:00,Mega Image,-30.00,0.50,RON,COMPLETED,100',
        'CARD_PAYMENT,Current,2026-10-05 11:00:00,,Kaufland,-10.00,0.00,RON,REVERTED,100',
        'TOPUP,Current,2026-10-04 08:00:00,2026-10-04 08:00:00,Top-up by *1234,200.00,0.00,RON,COMPLETED,300',
        'CARD_REFUND,Current,2026-10-07 08:00:00,2026-10-07 08:00:00,Emag,25.00,0.00,RON,COMPLETED,325',
    ]);
    $table = Table::csv($csv);
    $found = Formats::detect($table);
    $tx = Formats::read($found['format'], $table, $found['header']);

    expect($found['format'])->toBe('revolut')
        ->and($tx)->toHaveCount(3)
        ->and($tx[0])->toMatchArray(['date' => '2026-10-06', 'amount' => -3050, 'currency' => 'RON', 'merchant' => 'Mega Image', 'kind' => 'expense'])
        ->and($tx[1]['kind'])->toBe('transfer')
        ->and($tx[2]['kind'])->toBe('refund');
});

it('recognises a BT statement below its preamble, in Windows-1250, with the currency from the header lines', function () {
    $csv = iconv('UTF-8', 'CP1250', implode("\n", [
        'Extras de cont;;;;;;',
        'Cont: RO49BTRL0000000000000000; Valuta: RON;;;;;',
        'Data tranzactie;Data valuta;Descriere;Referinta tranzactiei;Debit;Credit;Sold contabil',
        '05.10.2026;05.10.2026;Plata la POS non-BT cu card VISA 4567XXXX1234 MEGA IMAGE 0123 BUCURESTI RO TID:1;REF1;-56,30;;1.000,00',
        '06.10.2026;06.10.2026;Încasare salariu;REF2;;4.200,00;5.143,70',
    ]));
    $table = Table::csv($csv);
    $found = Formats::detect($table);
    $tx = Formats::read($found['format'], $table, $found['header']);

    expect($found['format'])->toBe('bt')
        ->and($tx[0])->toMatchArray(['date' => '2026-10-05', 'amount' => -5630, 'currency' => 'RON', 'merchant' => 'MEGA IMAGE 0123 BUCURESTI RO', 'reference' => 'REF1', 'kind' => 'expense'])
        ->and($tx[1])->toMatchArray(['amount' => 420000, 'kind' => 'income']);
});

it('recognises an ING statement and folds its detail lines into the transaction', function () {
    $csv = implode("\n", [
        'Data,,Detalii tranzactie,,Debit,Credit',
        '05 octombrie 2026,,Cumparare POS,,"89,90",',
        ',,Nr. card: ****1234,,,',
        ',,Terminal: CARREFOUR ROMANIA BUCURESTI,,,',
        '06 octombrie 2026,,Transfer Home\'Bank,,"300,00",',
        ',,Beneficiar: Economii,,,',
    ]);
    $table = Table::csv($csv);
    $found = Formats::detect($table);
    $tx = Formats::read($found['format'], $table, $found['header']);

    expect($found['format'])->toBe('ing')
        ->and($tx)->toHaveCount(2)
        ->and($tx[0])->toMatchArray(['date' => '2026-10-05', 'amount' => -8990, 'merchant' => 'CARREFOUR ROMANIA BUCURESTI', 'kind' => 'expense'])
        ->and($tx[1]['kind'])->toBe('transfer');
});

it('reads an XLSX statement (first sheet, shared strings, numbers and Excel dates)', function () {
    $path = tempnam(sys_get_temp_dir(), 'xlsx');
    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::OVERWRITE);
    $zip->addFromString('xl/sharedStrings.xml', '<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><si><t>Date</t></si><si><t>Shop</t></si><si><t>Sum</t></si><si><t>Lidl</t></si></sst>');
    $zip->addFromString('xl/worksheets/sheet1.xml', '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'
        .'<row r="1"><c r="A1" t="s"><v>0</v></c><c r="B1" t="s"><v>1</v></c><c r="C1" t="s"><v>2</v></c></row>'
        .'<row r="2"><c r="A2"><v>46300</v></c><c r="B2" t="s"><v>3</v></c><c r="D2"><v>-12.5</v></c></row>'
        .'</sheetData></worksheet>');
    $zip->close();

    expect(Table::read($path, 'statement.xlsx'))->toBe([['Date', 'Shop', 'Sum'], ['46300', 'Lidl', '', '-12.5']]);
    unlink($path);
});
