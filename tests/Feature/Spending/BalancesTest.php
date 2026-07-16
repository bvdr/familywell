<?php

use Modules\Spending\Balances;

it('splits equally and nets to zero', function () {
    // Ann(1) paid 30.00, split among 3 -> everyone owes 10.00.
    $r = Balances::for([1, 2, 3], [['payer_id' => 1, 'amount' => 3000]], []);

    expect($r['net'])->toBe([1 => 2000, 2 => -1000, 3 => -1000]);
    expect(array_sum($r['net']))->toBe(0);
    expect($r['suggestions'])->toEqual([
        ['from' => 2, 'to' => 1, 'amount' => 1000],
        ['from' => 3, 'to' => 1, 'amount' => 1000],
    ]);
});

it('distributes indivisible remainders exactly', function () {
    // 10.00 / 3 = 3.34 + 3.33 + 3.33; shares must still sum to 1000.
    $r = Balances::for([1, 2, 3], [['payer_id' => 1, 'amount' => 1000]], []);

    expect(array_sum($r['net']))->toBe(0)
        ->and($r['net'][1])->toBe(1000 - 334)
        ->and($r['net'][2])->toBe(-333)
        ->and($r['net'][3])->toBe(-333);
});

it('shifts balances when a settlement is recorded', function () {
    $spends = [['payer_id' => 1, 'amount' => 3000]];
    $settle = [['from_user_id' => 2, 'to_user_id' => 1, 'amount' => 1000]];

    $r = Balances::for([1, 2, 3], $spends, $settle);

    // 2 has paid off their share, so only 3 still owes 1.
    expect($r['net'])->toBe([1 => 1000, 2 => 0, 3 => -1000])
        ->and($r['suggestions'])->toEqual([['from' => 3, 'to' => 1, 'amount' => 1000]]);
});
