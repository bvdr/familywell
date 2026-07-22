<?php

use Modules\Spending\Balances;

// Participants are a key => headcount map. Keys are opaque strings ('u1'/'g2' in the app); here we use
// simple keys. A spend is ['payer' => key, 'amount' => cents, 'shares' => override|null].

it('splits equally and nets to zero', function () {
    // a paid 30.00, split among 3 -> everyone owes 10.00.
    $r = Balances::for(['a' => 1, 'b' => 1, 'c' => 1], [['payer' => 'a', 'amount' => 3000]], []);

    expect($r['net'])->toBe(['a' => 2000, 'b' => -1000, 'c' => -1000]);
    expect(array_sum($r['net']))->toBe(0);
    expect($r['suggestions'])->toEqual([
        ['from' => 'b', 'to' => 'a', 'amount' => 1000],
        ['from' => 'c', 'to' => 'a', 'amount' => 1000],
    ]);
});

it('distributes indivisible remainders exactly', function () {
    // 10.00 / 3 = 3.34 + 3.33 + 3.33; shares must still sum to 1000.
    $r = Balances::for(['a' => 1, 'b' => 1, 'c' => 1], [['payer' => 'a', 'amount' => 1000]], []);

    expect(array_sum($r['net']))->toBe(0)
        ->and($r['net']['a'])->toBe(1000 - 334)
        ->and($r['net']['b'])->toBe(-333)
        ->and($r['net']['c'])->toBe(-333);
});

it('shifts balances when a settlement is recorded', function () {
    $spends = [['payer' => 'a', 'amount' => 3000]];
    $settle = [['from' => 'b', 'to' => 'a', 'amount' => 1000]];

    $r = Balances::for(['a' => 1, 'b' => 1, 'c' => 1], $spends, $settle);

    // b has paid off their share, so only c still owes 1.
    expect($r['net'])->toBe(['a' => 1000, 'b' => 0, 'c' => -1000])
        ->and($r['suggestions'])->toEqual([['from' => 'c', 'to' => 'a', 'amount' => 1000]]);
});

it('splits proportionally to headcount', function () {
    // a pays 700; shares weighted 3:2:1:1 over 7 -> 300 / 200 / 100 / 100 owed.
    $r = Balances::for(
        ['a' => 1, 'fam3' => 3, 'fam2' => 2, 'oana' => 1],
        [['payer' => 'a', 'amount' => 70000]],
        [],
    );

    expect(array_sum($r['net']))->toBe(0)
        ->and($r['net']['fam3'])->toBe(-30000)
        ->and($r['net']['fam2'])->toBe(-20000)
        ->and($r['net']['oana'])->toBe(-10000)
        ->and($r['net']['a'])->toBe(70000 - 10000); // paid 700, owes own 100 -> +600
});

it('bills a covered participant to their coverer', function () {
    // a pays 700; oana is covered by a. Weighted 3:2:1:1 -> oana owes 100 but it lands on a.
    $r = Balances::for(
        ['a' => 1, 'fam3' => 3, 'fam2' => 2, 'oana' => 1],
        [['payer' => 'a', 'amount' => 70000]],
        [],
        ['oana' => 'a'], // covered_by
    );

    expect(array_sum($r['net']))->toBe(0)
        ->and($r['net']['oana'])->toBe(0)            // covered -> owes nothing
        ->and($r['net']['a'])->toBe(70000 - 20000)   // own 100 + oana's 100 = 200 owed -> +500
        ->and($r['net']['fam3'])->toBe(-30000)
        ->and($r['net']['fam2'])->toBe(-20000);
});

it('a covered participant still gets credit for what they paid', function () {
    // oana (covered by a) fronts 50; nobody else pays. oana owes 0 (covered) but paid 50 -> +50.
    $r = Balances::for(
        ['a' => 1, 'oana' => 1],
        [['payer' => 'oana', 'amount' => 5000]],
        [],
        ['oana' => 'a'],
    );

    expect(array_sum($r['net']))->toBe(0)
        ->and($r['net']['oana'])->toBe(5000)  // paid 50, owes 0 (covered)
        ->and($r['net']['a'])->toBe(-5000);   // owes both shares (100% of 50)
});

it('splits a spend among only some participants', function () {
    // a pays 30 but only a and b share it (c is out). Each of a,b owes 15.
    $r = Balances::for(
        ['a' => 1, 'b' => 1, 'c' => 1],
        [['payer' => 'a', 'amount' => 3000, 'shares' => ['a' => null, 'b' => null]]],
        [],
    );

    expect(array_sum($r['net']))->toBe(0)
        ->and($r['net']['a'])->toBe(1500)   // paid 30, owes 15
        ->and($r['net']['b'])->toBe(-1500)
        ->and($r['net']['c'])->toBe(0);     // not part of this spend
});

it('honours exact per-person amounts', function () {
    // a pays 30; exact split a=20, b=10, c=0.
    $r = Balances::for(
        ['a' => 1, 'b' => 1, 'c' => 1],
        [['payer' => 'a', 'amount' => 3000, 'shares' => ['a' => 2000, 'b' => 1000, 'c' => 0]]],
        [],
    );

    expect(array_sum($r['net']))->toBe(0)
        ->and($r['net']['a'])->toBe(1000)   // paid 30, owes 20
        ->and($r['net']['b'])->toBe(-1000)
        ->and($r['net']['c'])->toBe(0);
});
