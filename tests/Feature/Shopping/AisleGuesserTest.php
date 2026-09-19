<?php

use App\Models\Family;
use App\Models\User;
use App\Support\Modules\ModuleRegistry;
use App\Support\Tenancy\CurrentFamily;
use Illuminate\Support\Facades\Http;
use Modules\Shopping\AisleGuesser;
use Modules\Shopping\ShoppingItem;
use Modules\Shopping\ShoppingList;

beforeEach(fn () => $this->artisan('modules:migrate')->assertExitCode(0));

function guesserList(): ShoppingList
{
    $family = Family::factory()->create();
    CurrentFamily::set($family->id);

    return ShoppingList::create(['name' => 'Weekly', 'created_by' => User::factory()->create()->id, 'store' => 'Metro']);
}

it('does nothing without a key', function () {
    config(['services.typesafe.key' => null]);
    Http::fake();
    $list = guesserList();
    $item = $list->items()->create(['name' => 'Milk', 'created_by' => 1]);
    AisleGuesser::flush();

    Http::assertNothingSent();
    expect($item->fresh()->department)->toBe('other');
});

it('moves items left in Other to the aisle Jev picks, in one request per store', function () {
    config(['services.typesafe.key' => 'test-key']);
    $list = guesserList();
    $milk = $list->items()->create(['name' => 'Milk', 'created_by' => 1]);
    $mystery = $list->items()->create(['name' => 'Thingamajig', 'created_by' => 1]);
    $chosen = $list->items()->create(['name' => 'Apples', 'department' => 'produce', 'created_by' => 1]);

    Http::fake(['api.typesafe.ai/*' => Http::response(['answers' => [
        "item_{$milk->id}" => ['type' => 'choice', 'choice' => 'dairy', 'probabilities' => [], 'confidence' => 0.9],
        "item_{$mystery->id}" => ['type' => 'choice', 'choice' => 'other', 'probabilities' => [], 'confidence' => 0.3],
    ]])]);

    AisleGuesser::flush();

    Http::assertSentCount(1);
    Http::assertSent(function ($req) use ($milk, $chosen) {
        $q = $req->data()['questions'];

        return $req->hasHeader('Authorization', 'Bearer test-key')
            && $req->data()['model'] === 'jev-latest'
            && isset($q["item_{$milk->id}"]) && ! isset($q["item_{$chosen->id}"]) // only "other" items are asked about
            && array_key_last($q["item_{$milk->id}"]['criteria']) === 'other' // no-match option is always offered
            && isset($q["item_{$milk->id}"]['criteria']['dairy']);
    });
    expect($milk->fresh()->department)->toBe('dairy')
        ->and($mystery->fresh()->department)->toBe('other')
        ->and($chosen->fresh()->department)->toBe('produce');
});

it('keeps a manual re-aisle done before the answer arrived', function () {
    config(['services.typesafe.key' => 'test-key']);
    $list = guesserList();
    $item = $list->items()->create(['name' => 'Milk', 'created_by' => 1]);
    Http::fake(['api.typesafe.ai/*' => Http::response(['answers' => [
        "item_{$item->id}" => ['type' => 'choice', 'choice' => 'dairy', 'probabilities' => [], 'confidence' => 0.9],
    ]])]);

    ShoppingItem::whereKey($item->id)->update(['department' => 'frozen']); // user moved it meanwhile
    AisleGuesser::flush();

    expect($item->fresh()->department)->toBe('frozen');
});

it('leaves items alone when the API fails', function () {
    config(['services.typesafe.key' => 'test-key']);
    $list = guesserList();
    $item = $list->items()->create(['name' => 'Milk', 'created_by' => 1]);
    Http::fake(['api.typesafe.ai/*' => Http::response('nope', 500)]);

    AisleGuesser::flush();

    expect($item->fresh()->department)->toBe('other');
});

it('runs the guess after the add-item response, end to end', function () {
    config(['services.typesafe.key' => 'test-key']);
    $user = User::factory()->create();
    $family = Family::factory()->create(['owner_id' => $user->id]);
    $family->members()->attach($user, ['role' => 'admin']);
    session(['active_family_id' => $family->id]);
    app(ModuleRegistry::class)->enable('shopping', $family->id);
    CurrentFamily::set($family->id);
    $list = ShoppingList::create(['name' => 'Weekly', 'created_by' => $user->id]);
    $list->shares()->create(['user_id' => $user->id, 'role' => 'owner']);

    Http::fake(['api.typesafe.ai/*' => function ($req) {
        $id = array_key_first($req->data()['questions']);

        return Http::response(['answers' => [$id => ['type' => 'choice', 'choice' => 'dairy', 'probabilities' => [], 'confidence' => 0.9]]]);
    }]);

    $this->actingAs($user)->post("/m/shopping/lists/{$list->id}/items", ['name' => 'Milk'])->assertRedirect();

    Http::assertSentCount(1);
    expect(ShoppingItem::where('name', 'Milk')->value('department'))->toBe('dairy');
});
