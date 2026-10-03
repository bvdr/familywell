<?php

namespace Modules\Recipes;

/**
 * The recipe calculator for the server (MCP tools). PHP twin of resources/js/lib/recipe-scale.js, which
 * the web page and the MCP widget use: same units, same rounding, both checked against
 * tests/fixtures/recipe-scale.json. Change one, change both.
 */
class RecipeScale
{
    /** [dimension, size in the base unit (g / ml)]. Other units scale but never convert. */
    private const UNITS = [
        'mg' => ['mass', 0.001], 'g' => ['mass', 1], 'kg' => ['mass', 1000],
        'ml' => ['volume', 1], 'cl' => ['volume', 10], 'dl' => ['volume', 100], 'l' => ['volume', 1000],
        'tsp' => ['volume', 5], 'tbsp' => ['volume', 15],
    ];

    private const ALIASES = [
        'gr' => 'g', 'gram' => 'g', 'grams' => 'g', 'grame' => 'g',
        'kilogram' => 'kg', 'kilograms' => 'kg', 'kilograme' => 'kg', 'kilo' => 'kg',
        'milliliter' => 'ml', 'millilitre' => 'ml', 'milliliters' => 'ml', 'millilitres' => 'ml', 'mililitri' => 'ml',
        'liter' => 'l', 'litre' => 'l', 'liters' => 'l', 'litres' => 'l', 'litru' => 'l', 'litri' => 'l',
        'teaspoon' => 'tsp', 'teaspoons' => 'tsp', 'linguriță' => 'tsp', 'lingurita' => 'tsp', 'lingurițe' => 'tsp', 'lingurite' => 'tsp',
        'tablespoon' => 'tbsp', 'tablespoons' => 'tbsp', 'lingură' => 'tbsp', 'lingura' => 'tbsp', 'linguri' => 'tbsp',
        'cups' => 'cup', 'cană' => 'cup', 'cana' => 'cup', 'căni' => 'cup',
        'piece' => 'pcs', 'pieces' => 'pcs', 'pc' => 'pcs', 'buc' => 'pcs', 'bucăți' => 'pcs', 'bucati' => 'pcs', 'bucată' => 'pcs', 'bucata' => 'pcs',
    ];

    private const QUARTERS = ['tsp', 'tbsp', 'cup'];

    public static function normUnit(?string $unit): string
    {
        $k = preg_replace('/\.$/', '', mb_strtolower(trim((string) $unit)));

        return self::ALIASES[$k] ?? $k;
    }

    public static function convert(float $qty, ?string $from, ?string $to): ?float
    {
        $f = self::normUnit($from);
        $t = self::normUnit($to);
        if ($f === $t) {
            return $qty;
        }
        $a = self::UNITS[$f] ?? null;
        $b = self::UNITS[$t] ?? null;

        return $a && $b && $a[0] === $b[0] ? $qty * $a[1] / $b[1] : null;
    }

    /**
     * Scale factor for a target, or null when it can't be computed.
     *
     * @param  array{servings: ?int, yieldAmount: ?float, yieldUnit: ?string, ingredients: list<array>}  $recipe
     * @param  array{type: string, value?: float, amount?: float, unit?: ?string, index?: int}  $target
     */
    public static function factor(array $recipe, array $target): ?float
    {
        $f = null;
        if ($target['type'] === 'servings') {
            $f = ! empty($recipe['servings']) ? $target['value'] / $recipe['servings'] : null;
        } elseif ($target['type'] === 'yield') {
            $amount = ! empty($recipe['yieldAmount']) ? self::convert((float) $target['amount'], $target['unit'] ?? $recipe['yieldUnit'], $recipe['yieldUnit']) : null;
            $f = $amount === null ? null : $amount / $recipe['yieldAmount'];
        } elseif ($target['type'] === 'ingredient') {
            $ing = $recipe['ingredients'][$target['index']] ?? null;
            $amount = $ing && ! empty($ing['qty']) ? self::convert((float) $target['amount'], $target['unit'] ?? ($ing['unit'] ?? null), $ing['unit'] ?? null) : null;
            $f = $amount === null ? null : $amount / $ing['qty'];
        }

        return $f !== null && is_finite($f) && $f > 0 ? $f : null;
    }

    /** floor(x + .5): the same half-up rounding as the JS (PHP's round() pre-rounds and can disagree). */
    private static function round(float $q, int $decimals): float
    {
        return floor($q * 10 ** $decimals + 0.5) / 10 ** $decimals;
    }

    public static function roundQty(float $q, ?string $unit): float
    {
        $u = self::normUnit($unit);
        if (in_array($u, self::QUARTERS, true)) {
            return max(0.25, self::round($q * 4, 0) / 4);
        }
        if ($u === 'kg' || $u === 'l') {
            return self::round($q, 2);
        }
        if (in_array($u, ['g', 'ml', 'mg', 'cl', 'dl'], true)) {
            return $q >= 10 ? self::round($q, 0) : self::round($q, 1);
        }

        return $q >= 10 ? self::round($q, 0) : max(0.5, self::round($q * 2, 0) / 2);
    }

    /** @return array{qty: int|float|null, unit: ?string} */
    public static function ingredient(array $ing, float $factor): array
    {
        $qty = $ing['qty'] ?? null;
        if ($qty === null || $qty === '' || ($ing['scalable'] ?? true) === false || $factor == 1) {
            return ['qty' => $qty === '' ? null : $qty, 'unit' => $ing['unit'] ?? null];
        }
        $q = (float) $qty * $factor;
        $orig = self::normUnit($ing['unit'] ?? null);
        $u = $orig;
        if ($u === 'g' && $q >= 1000) {
            [$q, $u] = [$q / 1000, 'kg'];
        } elseif ($u === 'kg' && $q < 1) {
            [$q, $u] = [$q * 1000, 'g'];
        } elseif ($u === 'ml' && $q >= 1000) {
            [$q, $u] = [$q / 1000, 'l'];
        } elseif ($u === 'l' && $q < 1) {
            [$q, $u] = [$q * 1000, 'ml'];
        }
        $r = self::roundQty($q, $u);

        // Whole numbers come back as ints so the JSON reads "400", not "400.0".
        return ['qty' => floor($r) == $r ? (int) $r : $r, 'unit' => $u === $orig ? ($ing['unit'] ?? null) : $u];
    }

    /** Human text for a scaled ingredient line: "400 g flour", "salt (to taste)". */
    public static function line(array $ing, array $scaled): string
    {
        $amount = trim(($scaled['qty'] !== null ? rtrim(rtrim(number_format((float) $scaled['qty'], 2, '.', ''), '0'), '.') : '').' '.($scaled['unit'] ?? ''));

        return trim($amount.' '.$ing['name']).($scaled['qty'] === null ? ' (to taste)' : '');
    }
}
