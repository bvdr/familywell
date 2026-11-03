<?php

namespace Modules\Shopping;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Weekly sweep for shop logos (user, 2026-11-03: "store them, and do a weekly sweep for new store icons"). Shop names
 * no brand matched are looked up once, for everyone, and the logo stored on R2: the app never fetches a logo while
 * someone waits, and a name is looked up once however many families use it.
 *
 * Trust rules (from the 2026-11-03 tests): only a Wikidata item whose label is the name and whose description says
 * it's a shop (guessing a website accepted parked "for sale" domains; loose Wikidata search turned "dm" into
 * Dominica). Logo: the item's logo file (P154) rendered by Wikimedia Commons, else the Google favicon of its official
 * website (P856) when it isn't Google's blank globe. Nothing trustworthy = 'none', retried after 90 days.
 */
class FindShopLogos extends Command
{
    protected $signature = 'shops:find-logos {--limit=50}';

    protected $description = 'Find and store logos for shop names no known brand matched (weekly)';

    private const SHOPLIKE = '/(shop|store|supermarket|hypermarket|retail|chain|pharmac|drugstore|grocer|discount|bakery|bookstore|furniture|electronics|hardware|diy|home improvement|department|cosmetic|clothing|fashion|toy|pet|optic|market)/i';

    private const UA = 'FamilyWell/1.0 (+https://familywell.co; shop logos)';

    public function handle(): int
    {
        // First what the built-in list (which grows with releases) already knows: no lookup needed for those.
        $this->rematch();
        $known = DB::table('shopping_brand_finds')
            ->where(fn ($q) => $q->where('status', 'found')->orWhere('checked_at', '>', now()->subDays(90)))
            ->pluck('key')->flip();
        // Unmatched names, most-used first (one row per name however many families have it).
        $names = DB::table('shopping_shops')->whereNull('brand')->pluck('name')
            ->groupBy(fn ($n) => ShopBrands::key($n))
            ->reject(fn ($group, $key) => $key === '' || isset($known[$key]))
            ->sortByDesc(fn ($group) => $group->count())
            ->take((int) $this->option('limit'));

        foreach ($names as $key => $group) {
            $name = $group->first();
            $find = $this->lookup($name);
            DB::table('shopping_brand_finds')->updateOrInsert(['key' => $key], [
                'name' => $find['name'] ?? $name,
                'status' => $find ? 'found' : 'none',
                'slug' => $find ? 'x-'.Str::slug($find['name']) : null,
                'logo_path' => $find['path'] ?? null,
                'source' => $find['source'] ?? null,
                'checked_at' => now(),
                'updated_at' => now(),
                'created_at' => now(),
            ]);
            $this->line(($find ? '✓ ' : '· ').$name.($find ? "  ← {$find['source']}" : ''));
        }

        ShopBrands::forgetFinds();
        $this->rematch();

        return self::SUCCESS;
    }

    /** Shops with no brand whose name now matches (a find, or a brand added to the built-in list) get it. */
    private function rematch(): void
    {
        foreach (DB::table('shopping_shops')->whereNull('brand')->get(['id', 'name']) as $shop) {
            if ($brand = ShopBrands::match($shop->name)) {
                DB::table('shopping_shops')->where('id', $shop->id)->update(['brand' => $brand]);
            }
        }
    }

    /** @return array{name: string, path: string, source: string}|null */
    private function lookup(string $name): ?array
    {
        $http = Http::withUserAgent(self::UA)->timeout(10);
        $search = $http->get('https://www.wikidata.org/w/api.php', [
            'action' => 'wbsearchentities', 'search' => $name, 'language' => 'en', 'uselang' => 'en', 'type' => 'item', 'limit' => 7, 'format' => 'json',
        ])->json('search') ?? [];
        // Exact label (or alias) match only, described as some kind of shop.
        $hits = array_filter($search, fn ($s) => ShopBrands::key($s['match']['text'] ?? $s['label'] ?? '') === ShopBrands::key($name)
            && preg_match(self::SHOPLIKE, $s['description'] ?? ''));
        if (! $hits) {
            return null;
        }
        $ids = implode('|', array_column($hits, 'id'));
        $entities = $http->get('https://www.wikidata.org/w/api.php', ['action' => 'wbgetentities', 'ids' => $ids, 'props' => 'claims|labels', 'languages' => 'en', 'format' => 'json'])->json('entities') ?? [];
        foreach ($hits as $hit) {
            $claims = $entities[$hit['id']]['claims'] ?? [];
            $label = $entities[$hit['id']]['labels']['en']['value'] ?? $hit['label'];
            $png = null;
            $source = null;
            if ($file = $claims['P154'][0]['mainsnak']['datavalue']['value'] ?? null) {
                $r = $http->timeout(20)->get('https://commons.wikimedia.org/wiki/Special:FilePath/'.rawurlencode($file), ['width' => 256]);
                [$png, $source] = $r->ok() ? [$r->body(), "wikimedia: {$file}"] : [null, null];
            }
            if (! $png && ($site = $claims['P856'][0]['mainsnak']['datavalue']['value'] ?? null)) {
                $host = parse_url($site, PHP_URL_HOST);
                $r = Http::timeout(10)->get('https://www.google.com/s2/favicons', ['domain' => $host, 'sz' => 128]);
                // Google answers every domain; ~330 bytes is its blank globe, not a logo.
                [$png, $source] = $r->ok() && strlen($r->body()) > 600 ? [$r->body(), "favicon: {$host}"] : [null, null];
            }
            if ($png) {
                $path = 'brands/x-'.Str::slug($label).'.png';
                Storage::disk('r2')->put($path, $png);

                return ['name' => $label, 'path' => $path, 'source' => $source];
            }
        }

        return null;
    }
}
