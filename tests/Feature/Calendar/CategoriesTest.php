<?php

use App\Models\Family;
use Modules\Calendar\CalendarCategory;
use Modules\Calendar\CalendarEvent;

use function Pest\Laravel\actingAs;

require_once __DIR__.'/helpers.php';

const NEW_CATEGORY = ['name' => 'Sports', 'icon' => 'trophy', 'color' => 'teal'];

it('lets any member add a category; the whole family gets it next to the built-ins', function () {
    [, $users] = calendarFamily();

    // users[1] is a plain member: C14 is reached from the event sheet, so adding needs no admin.
    actingAs($users[1])->post('/m/calendar/categories', NEW_CATEGORY)->assertSessionHasNoErrors();

    actingAs($users[0])->get('/m/calendar')->assertOk()->assertInertia(fn ($p) => $p
        ->where('categories.0.slug', 'trips')->where('categories.0.builtin', true)->where('categories.0.icon', 'plane')
        ->where('categories.5', fn ($c) => $c['slug'] === 'sports' && $c['name'] === 'Sports' && $c['icon'] === 'trophy'
            && $c['color'] === 'teal' && $c['builtin'] === false));
});

it('lets an event use a family category, and rejects one the family does not have', function () {
    [, $users] = calendarFamily();
    actingAs($users[0])->post('/m/calendar/categories', NEW_CATEGORY);
    $event = ['title' => 'Swim', 'start_date' => '2026-07-17', 'end_date' => '2026-07-17'];

    actingAs($users[0])->post('/m/calendar/events', $event + ['category' => 'sports'])->assertSessionHasNoErrors();
    expect(CalendarEvent::firstOrFail()->category)->toBe('sports');

    actingAs($users[0])->post('/m/calendar/events', $event + ['category' => 'nope'])->assertSessionHasErrors('category');
});

it("never offers or accepts another family's category", function () {
    [, $users] = calendarFamily();
    $other = Family::factory()->create();
    CalendarCategory::withoutGlobalScopes()->forceCreate(['family_id' => $other->id, 'slug' => 'secret', 'name' => 'Secret', 'icon' => 'tag', 'color' => 'pink', 'position' => 5]);

    actingAs($users[0])->get('/m/calendar')->assertInertia(fn ($p) => $p->has('categories', 5));
    actingAs($users[0])->post('/m/calendar/events', ['title' => 'X', 'start_date' => '2026-07-17', 'end_date' => '2026-07-17', 'category' => 'secret'])
        ->assertSessionHasErrors('category');
    $row = CalendarCategory::withoutGlobalScopes()->where('slug', 'secret')->firstOrFail();
    actingAs($users[0])->delete("/m/calendar/categories/{$row->id}")->assertNotFound();
    expect(CalendarCategory::withoutGlobalScopes()->where('slug', 'secret')->exists())->toBeTrue();
});

it('validates name, icon and colour, and keeps names and slugs unique', function () {
    [, $users] = calendarFamily();
    $post = fn (array $over) => actingAs($users[0])->post('/m/calendar/categories', $over + NEW_CATEGORY);

    $post(['name' => ''])->assertSessionHasErrors('name');
    $post(['name' => str_repeat('a', 41)])->assertSessionHasErrors('name');
    $post(['icon' => 'Not An Icon'])->assertSessionHasErrors('icon');
    $post(['color' => 'chartreuse'])->assertSessionHasErrors('color');
    $post(['name' => 'trips'])->assertSessionHasErrors('name'); // a built-in's name, any case
    $post([])->assertSessionHasNoErrors();
    $post(['name' => 'SPORTS'])->assertSessionHasErrors('name');

    // Different names that slugify the same, and one with no letters: distinct slugs, all within calendar_events.category's 20.
    $post(['name' => 'Sport!'])->assertSessionHasNoErrors();
    $post(['name' => 'Sport?'])->assertSessionHasNoErrors();
    $post(['name' => '🎉'])->assertSessionHasNoErrors();
    $post(['name' => 'A very long category name indeed'])->assertSessionHasNoErrors();
    $slugs = CalendarCategory::forFamily();
    $slugs = array_column(array_slice($slugs, 5), 'slug');
    expect($slugs)->toBe(['sports', 'sport', 'sport-2', 'category', 'a-very-long-cate'])
        ->and(max(array_map('strlen', $slugs)))->toBeLessThanOrEqual(20);
});

it('lets only an admin rename or delete, and deleting takes it off the events, trashed ones too', function () {
    [, $users] = calendarFamily();
    actingAs($users[0])->post('/m/calendar/categories', NEW_CATEGORY);
    $cat = CalendarCategory::firstOrFail();
    $day = ['start_date' => '2026-07-17', 'end_date' => '2026-07-17', 'category' => 'sports'];
    $live = CalendarEvent::createFor($users[0], CalendarEvent::validated($day + ['title' => 'Swim']));
    $trashed = CalendarEvent::createFor($users[0], CalendarEvent::validated($day + ['title' => 'Old']));
    $trashed->delete();

    actingAs($users[1])->patch("/m/calendar/categories/{$cat->id}", ['name' => 'Hijack'] + NEW_CATEGORY)->assertForbidden();
    actingAs($users[1])->delete("/m/calendar/categories/{$cat->id}")->assertForbidden();

    // Renaming keeps the slug, so the events stay attached; keeping its own name is not a clash.
    actingAs($users[0])->patch("/m/calendar/categories/{$cat->id}", ['name' => 'Sport club', 'icon' => 'dumbbell', 'color' => 'pink'])->assertSessionHasNoErrors();
    expect($cat->fresh()->only('slug', 'name', 'icon', 'color'))->toBe(['slug' => 'sports', 'name' => 'Sport club', 'icon' => 'dumbbell', 'color' => 'pink'])
        ->and($live->fresh()->category)->toBe('sports');
    actingAs($users[0])->patch("/m/calendar/categories/{$cat->id}", ['name' => 'Sport club', 'icon' => 'trophy', 'color' => 'teal'])->assertSessionHasNoErrors();

    actingAs($users[0])->delete("/m/calendar/categories/{$cat->id}")->assertRedirect();
    expect(CalendarCategory::count())->toBe(0)
        ->and($live->fresh()->category)->toBeNull()
        ->and(CalendarEvent::withTrashed()->find($trashed->id)->category)->toBeNull()
        ->and(CalendarEvent::find($live->id)->title)->toBe('Swim'); // only the category went
});

it('sends the categories to the event page too, for its edit sheet', function () {
    [, $users] = calendarFamily();
    actingAs($users[0])->post('/m/calendar/categories', NEW_CATEGORY);
    $e = CalendarEvent::createFor($users[0], CalendarEvent::validated(['title' => 'Swim', 'start_date' => '2026-07-17', 'end_date' => '2026-07-17']));

    actingAs($users[0])->get("/m/calendar/events/{$e->id}")->assertInertia(fn ($p) => $p->has('categories', 6));
});
