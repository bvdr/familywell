<?php

use App\Models\Family;
use App\Models\User;
use App\Support\Modules\ModuleRegistry;
use App\Support\Tenancy\CurrentFamily;
use Inertia\Testing\AssertableInertia;
use Modules\Contacts\Person;
use Modules\Projects\Project;
use Modules\Spending\Spend;
use Modules\Spending\SpendEvent;

use function Pest\Laravel\actingAs;

// Family with projects + spending + contacts on, a project owned by users[0], and the tenant set so
// models can be seeded directly.
function linksFamily(): array
{
    [$family, $users] = mcpFamily(['projects', 'spending', 'contacts'], 2);
    CurrentFamily::set($family->id);
    $project = Project::createFor($users[0], ['name' => 'Kitchen']);

    return [$family, $users, $project];
}

function spendList(User $user, string $name, int $cents = 0): SpendEvent
{
    $event = SpendEvent::createFor($user, ['name' => $name, 'currency' => 'EUR']);
    if ($cents) {
        Spend::create(['spend_event_id' => $event->id, 'payer_id' => $user->id, 'amount' => $cents, 'currency' => 'EUR',
            'base_amount' => $cents, 'rate' => 1, 'category' => 'other', 'spent_at' => '2026-10-01', 'created_by' => $user->id]);
    }

    return $event;
}

it('links a spending list and a contact, shows them with total + phone, and unlinks without deleting', function () {
    [, $users, $project] = linksFamily();
    $budget = spendList($users[0], 'Kitchen budget', 12500);
    $tiler = Person::create(['name' => 'Tiler Tom', 'created_by' => $users[0]->id]);
    $tiler->syncContactMethods([['type' => 'email', 'value' => 'tom@example.com'], ['type' => 'phone', 'value' => '+40 712 345 678']]);

    actingAs($users[0]);
    $this->post("/m/projects/{$project->id}/links/spend_event", ['ids' => [$budget->id]])->assertRedirect();
    $this->post("/m/projects/{$project->id}/links/contact", ['ids' => [$tiler->id]])->assertRedirect();

    $this->get("/m/projects/{$project->id}")->assertInertia(fn (AssertableInertia $page) => $page
        ->where('spendLists.0.name', 'Kitchen budget')
        ->where('spendLists.0.total', 12500)
        ->where('spendLists.0.currency', 'EUR')
        ->where('contacts.0.name', 'Tiler Tom')
        ->where('contacts.0.phone', '+40 712 345 678')); // the phone, not the first method (email)

    // Linked items drop out of the picker.
    $this->getJson("/m/projects/{$project->id}/links/spend_event/linkable")->assertJsonCount(0, 'items');

    $this->delete("/m/projects/{$project->id}/links/spend_event/{$budget->id}")->assertRedirect();
    $this->delete("/m/projects/{$project->id}/links/contact/{$tiler->id}")->assertRedirect();
    expect($project->links()->count())->toBe(0)
        ->and(SpendEvent::find($budget->id))->not->toBeNull()
        ->and(Person::find($tiler->id))->not->toBeNull();
});

it('only offers and links spending lists the viewer can see', function () {
    [, $users, $project] = linksFamily();
    $shared = spendList($users[1], 'Shared');
    $private = spendList($users[1], 'Their private');
    $private->setVisibility('private');

    actingAs($users[0]);
    $names = collect($this->getJson("/m/projects/{$project->id}/links/spend_event/linkable")->json('items'))->pluck('name')->all();
    expect($names)->toBe(['Shared']);

    // A hand-crafted request for the private list is silently skipped.
    $this->post("/m/projects/{$project->id}/links/spend_event", ['ids' => [$private->id, $shared->id]])->assertRedirect();
    expect($project->links()->where('linkable_type', 'spend_event')->pluck('linkable_id')->all())->toBe([$shared->id]);

    // A list linked by someone who can see it stays hidden from someone who can't.
    $project->links()->create(['linkable_type' => 'spend_event', 'linkable_id' => $private->id]);
    $this->get("/m/projects/{$project->id}")->assertInertia(fn (AssertableInertia $page) => $page
        ->has('spendLists', 1)->where('spendLists.0.name', 'Shared'));
});

it('never links a contact from another family', function () {
    [$family, $users, $project] = linksFamily();
    $other = User::factory()->create();
    $otherFamily = Family::factory()->create(['owner_id' => $other->id]);
    CurrentFamily::set($otherFamily->id);
    $stranger = Person::create(['name' => 'Stranger', 'created_by' => $other->id]);
    CurrentFamily::set($family->id); // back to ours: route binding resolves before SetActiveFamily runs

    actingAs($users[0]);
    $this->post("/m/projects/{$project->id}/links/contact", ['ids' => [$stranger->id]])->assertRedirect();
    expect($project->links()->count())->toBe(0);
});

it('hides the sections and 404s the picker when the module is off', function () {
    [$family, $users, $project] = linksFamily();
    app(ModuleRegistry::class)->disable('spending', $family->id);

    actingAs($users[0]);
    $this->getJson("/m/projects/{$project->id}/links/spend_event/linkable")->assertNotFound();
    $this->get("/m/projects/{$project->id}")->assertInertia(fn (AssertableInertia $page) => $page
        ->where('spendLists', null)->where('contacts', []));
});

it('refuses to link for someone with no access to the project', function () {
    [$family, $users, $project] = linksFamily();
    $project->setVisibility('private');
    $budget = spendList($users[1], 'Mine');

    actingAs($users[1])->post("/m/projects/{$project->id}/links/spend_event", ['ids' => [$budget->id]])->assertForbidden();
});
