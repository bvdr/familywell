<?php

use App\Models\User;
use App\Support\Modules\ModuleRegistry;
use App\Support\Modules\Preview;
use Illuminate\Support\Facades\DB;

use function Pest\Laravel\actingAs;

// Module previews (App\Support\Modules\Preview): a module's next version, or a whole new module, for chosen people first.

require_once __DIR__.'/../Calendar/helpers.php';

it('opts a person in and out with module:preview', function () {
    $user = User::factory()->create(['email' => 'ana@example.com']);

    $this->artisan('module:preview', ['module' => 'calendar', 'email' => 'Ana@Example.com'])->assertSuccessful();
    expect($user->fresh()->preview_modules)->toBe(['calendar'])
        ->and(Preview::on('calendar', $user->fresh()))->toBeTrue()
        ->and(Preview::on('tasks', $user->fresh()))->toBeFalse();

    $this->artisan('module:preview', ['module' => 'calendar', 'email' => 'ana@example.com', '--off' => true])->assertSuccessful();
    expect($user->fresh()->preview_modules)->toBeNull();

    $this->artisan('module:preview', ['module' => 'nope', 'email' => 'ana@example.com'])->assertFailed();
    $this->artisan('module:preview', ['module' => 'calendar', 'email' => 'nobody@example.com'])->assertFailed();
});

it('shares the previews with the page, and only there', function () {
    [, [$owner, $other]] = calendarFamily(['calendar']);
    $owner->forceFill(['preview_modules' => ['calendar']])->save();

    actingAs($owner)->get('/m/calendar')->assertInertia(fn ($p) => $p->where('preview', ['calendar'])
        ->where('auth.user', fn ($u) => ! isset($u['preview_modules']))); // not inside every serialized user
    actingAs($other)->get('/m/calendar')->assertInertia(fn ($p) => $p->where('preview', []));
});

it('hides a whole preview module from everyone not opted in', function () {
    $dir = sys_get_temp_dir().'/fw-preview-modules-'.uniqid();
    mkdir("{$dir}/beta", recursive: true);
    file_put_contents("{$dir}/beta/module.json", json_encode(['id' => 'beta', 'version' => '0.1.0', 'provider' => 'X', 'preview' => true]));
    mkdir("{$dir}/stable");
    file_put_contents("{$dir}/stable/module.json", json_encode(['id' => 'stable', 'version' => '1.0.0', 'provider' => 'Y']));
    $registry = new ModuleRegistry($dir);

    [$family, [$in, $out]] = calendarFamily(['calendar']);
    DB::table('family_modules')->insert([
        ['family_id' => $family->id, 'module_id' => 'beta', 'enabled' => true],
        ['family_id' => $family->id, 'module_id' => 'stable', 'enabled' => true],
    ]);
    $in->forceFill(['preview_modules' => ['beta']])->save();

    $this->actingAs($out);
    expect($registry->isEnabled('beta', $family->id))->toBeFalse()->and($registry->isEnabled('stable', $family->id))->toBeTrue();
    $this->actingAs($in->fresh());
    expect($registry->isEnabled('beta', $family->id))->toBeTrue();
});
