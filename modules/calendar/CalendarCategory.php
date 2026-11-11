<?php

namespace Modules\Calendar;

use App\Support\Tenancy\BelongsToFamily;
use App\Support\Tenancy\CurrentFamily;
use App\Support\Translations;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The categories an event can be in (design C12-C14): the five built-ins, which live in code (translated names, no
 * seeding, nothing to migrate), plus the ones a family adds (this table). Events store the slug in
 * calendar_events.category; a custom category is never a FK target, see the migration.
 *
 * Everything reads through forFamily(), which builds the list once per request, so the calendar page, the event page,
 * validation and itemsFor() together cost one query.
 */
class CalendarCategory extends Model
{
    use BelongsToFamily;

    protected $table = 'calendar_categories';

    protected $fillable = ['slug', 'name', 'icon', 'color', 'position'];

    /**
     * The design's chips (04b C9/C10/D2), in its order. `color` is the palette key a new event in it gets;
     * `icon` is what an event in it shows when its title gives no better guess.
     */
    public const BUILT_IN = [
        'trips' => ['icon' => 'plane', 'color' => 'green'],
        'faith' => ['icon' => 'church', 'color' => 'amber'],
        'family' => ['icon' => 'heart', 'color' => 'red'],
        'school' => ['icon' => 'graduation-cap', 'color' => 'blue'],
        'work' => ['icon' => 'briefcase', 'color' => 'sand'],
    ];

    /** C14's swatches: violet, teal, pink, blue, green, coral, amber, grey, as palette keys (lib/tasks LIST_COLORS). */
    public const COLORS = ['purple', 'teal', 'pink', 'blue', 'green', 'red', 'amber', 'sand'];

    /**
     * Every category of the current family, built-ins first, then custom ones in the order they were added.
     * Memoized on the request attribute bag (like ModuleRegistry), so it dies with the request under Octane.
     *
     * @return list<array{slug: string, name: string, icon: string, color: string, builtin: bool, id: int|null}>
     */
    public static function forFamily(): array
    {
        $bag = app('request')->attributes;
        $key = 'calendar.categories.'.CurrentFamily::id();
        if (! $bag->has($key)) {
            $list = [];
            foreach (self::BUILT_IN as $slug => $d) {
                $list[] = ['slug' => $slug, 'name' => Translations::get("calendar.category_{$slug}"), 'builtin' => true, 'id' => null] + $d;
            }
            foreach (static::query()->orderBy('position')->orderBy('id')->get() as $c) {
                $list[] = ['slug' => $c->slug, 'name' => $c->name, 'icon' => $c->icon, 'color' => $c->color, 'builtin' => false, 'id' => $c->id];
            }
            $bag->set($key, $list);
        }

        return $bag->get($key);
    }

    /** The valid `category` values for an event of this family. */
    public static function slugs(): array
    {
        return array_column(self::forFamily(), 'slug');
    }

    /** One category by slug, or null (a stale slug on an event just has no category icon/colour). */
    public static function lookup(?string $slug): ?array
    {
        return $slug ? (collect(self::forFamily())->firstWhere('slug', $slug)) : null;
    }

    /** Forget the memo after a write, so the rest of this request (and a test) sees it. */
    private static function forget(): void
    {
        app('request')->attributes->remove('calendar.categories.'.CurrentFamily::id());
    }

    /**
     * Validate a create/update body. The name must not be taken (a built-in's translated name counts) and must make
     * a usable slug; the colour is one of C14's swatches. $ignoreId = the row being renamed.
     */
    public static function validated(array $input, ?int $ignoreId = null): array
    {
        $data = validator($input, [
            'name' => ['required', 'string', 'max:40'],
            'icon' => ['required', 'string', 'regex:/^[a-z0-9]+(-[a-z0-9]+)*$/', 'max:40'], // a lucide key
            'color' => ['required', 'in:'.implode(',', self::COLORS)],
        ])->validate();

        $name = trim($data['name']);
        $taken = collect(self::forFamily())->contains(
            fn ($c) => ($ignoreId === null || $c['id'] !== $ignoreId) && mb_strtolower($c['name']) === mb_strtolower($name),
        );
        if ($taken) {
            throw ValidationException::withMessages(['name' => Translations::get('calendar.category_exists')]);
        }

        return ['name' => $name] + $data;
    }

    /** Add a family category (any member who can add events may: C14 is reached from the event sheet). */
    public static function add(array $data): self
    {
        $existing = self::forFamily();
        $category = static::create($data + [
            'slug' => self::makeSlug($data['name'], array_column($existing, 'slug')),
            'position' => count($existing), // after every current one
        ]);
        self::forget();

        return $category;
    }

    public function change(array $data): void
    {
        $this->update($data); // the slug never changes, so events stay attached
        self::forget();
    }

    /** Remove it and take it off its events (they keep everything else), so no event points at a missing category. */
    public function remove(): void
    {
        CalendarEvent::withTrashed()->where('category', $this->slug)->update(['category' => null]);
        $this->delete();
        self::forget();
    }

    /**
     * A slug from the name, unique among $taken (built-ins included). At most 20 characters, the width of
     * calendar_events.category; a name with no letters at all (emoji) falls back to 'category'.
     */
    private static function makeSlug(string $name, array $taken): string
    {
        $base = rtrim(Str::limit(Str::slug($name), 16, ''), '-') ?: 'category'; // leaves room for a "-NN" suffix inside 20
        $slug = $base;
        for ($n = 2; in_array($slug, $taken, true); $n++) {
            $slug = "{$base}-{$n}";
        }

        return $slug;
    }
}
