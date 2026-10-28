<?php

namespace App\Support\Search;

use App\Support\Modules\ModuleRegistry;
use App\Support\Tenancy\CurrentFamily;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;

// Sibling to DashboardAgenda, but for the "H5 search" page: one search across every module's data.
// Modules register a provider that returns ITS hits for a query; collect() runs only the providers
// for modules the active family has enabled, so a disabled module is never searched and its data
// never leaks into results. Keyed by module id so re-registration under Octane is idempotent.
class SearchSources
{
    /** @var array<string, Closure> */
    private static array $providers = [];

    /** @param Closure(string $q, int $userId): array $provider */
    public static function register(string $moduleId, Closure $provider): void
    {
        self::$providers[$moduleId] = $provider;
    }

    /**
     * Every enabled provider's rows for $q, flattened. A failing provider is logged and skipped so
     * one module can't take down the whole search. No family/user in context (shouldn't happen behind
     * the auth+family middleware, but a console/job caller would hit this) returns nothing.
     *
     * @return array<int, array{module: string, title: string, meta: string, href: string, icon: string}>
     */
    public static function collect(string $q): array
    {
        $familyId = CurrentFamily::id();
        $userId = auth()->id();
        if (! $familyId || ! $userId) {
            return [];
        }

        $enabled = app(ModuleRegistry::class)->enabledIds($familyId);
        $results = [];
        foreach (self::$providers as $moduleId => $provider) {
            if (! in_array($moduleId, $enabled, true)) {
                continue;
            }
            try {
                foreach ($provider($q, $userId) as $row) {
                    $results[] = $row;
                }
            } catch (\Throwable $e) {
                Log::warning("search provider [{$moduleId}] failed", ['error' => $e->getMessage()]);
            }
        }

        return $results;
    }

    /**
     * Case-insensitive, cross-engine (sqlite + pgsql) LIKE across $columns, ORed onto $query. The escape
     * clause is explicit because sqlite has no default LIKE escape character (pgsql's default happens to
     * be backslash, but relying on an implicit default would make the two engines behave differently).
     */
    public static function ilike(Builder $query, array $columns, string $term): Builder
    {
        $pattern = '%'.addcslashes(mb_strtolower($term), '%_\\').'%';

        // The escape character is bound, not inlined: PDO's pgsql placeholder parser misreads an inline
        // `escape '\'` as an unterminated string (it treats the backslash as escaping the closing quote,
        // like MySQL would, even though Postgres doesn't), which then swallows every `?` after it and
        // throws "parameter was not defined" the moment there is more than one ORed column.
        return $query->where(function (Builder $w) use ($columns, $pattern) {
            foreach ($columns as $column) {
                $w->orWhereRaw("lower({$column}) like ? escape ?", [$pattern, '\\']);
            }
        });
    }

    /**
     * A short date for a meta line ("Oct 3"), in the viewer's app language. Dates/money follow the app
     * language everywhere (see the `vehicles: show dates in the app language` fix this branch is named
     * after) — there is no Carbon-locale wiring in this app, so IntlDateFormatter is used directly,
     * the same approach SpendingServiceProvider already uses for money (NumberFormatter).
     */
    public static function formatDate(\DateTimeInterface $date): string
    {
        $formatter = new \IntlDateFormatter(app()->getLocale(), \IntlDateFormatter::MEDIUM, \IntlDateFormatter::NONE);
        $formatter->setPattern('MMM d');

        return $formatter->format($date);
    }

    /**
     * A short "...context around the match..." snippet, for a long text field (a note body, an expense
     * note) where showing the whole thing would blow out the row. Falls back to a plain truncation when
     * $q isn't found verbatim (e.g. it matched a different column) so the row never shows nothing.
     */
    public static function snippet(string $text, string $q, int $radius = 28): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
        $pos = mb_stripos($text, $q);
        if ($pos === false) {
            return mb_strlen($text) > 60 ? mb_substr($text, 0, 60).'…' : $text;
        }

        $start = max(0, $pos - $radius);
        $end = min(mb_strlen($text), $pos + mb_strlen($q) + $radius);

        return ($start > 0 ? '…' : '').mb_substr($text, $start, $end - $start).($end < mb_strlen($text) ? '…' : '');
    }
}
