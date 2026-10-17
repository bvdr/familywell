import { usePage } from '@inertiajs/react';

// Lightweight i18n. The server shares the full translation map for the current locale (English-filled)
// as the `translations` Inertia prop; useT() reads it and returns a `t('nav.dashboard', {name})`
// function. Keys are namespaced/dot-separated; `:param` placeholders are interpolated. Anything
// missing falls back to the key itself (which, thanks to the English merge server-side, is rare).

type Dict = Record<string, unknown>;

function lookup(dict: Dict, key: string): string | undefined {
    const val = key.split('.').reduce<unknown>((o, k) => (o && typeof o === 'object' ? (o as Dict)[k] : undefined), dict);
    return typeof val === 'string' ? val : undefined;
}

function interpolate(str: string, params?: Record<string, string | number>): string {
    if (!params) return str;
    return str.replace(/:(\w+)/g, (_, k: string) => (k in params ? String(params[k]) : `:${k}`));
}

export type TFn = (key: string, params?: Record<string, string | number>) => string;

// Plurals: a counted string is stored as `key_one` / `key_other` (+ `key_few` etc. where the language has
// them, e.g. Romanian "2 reparații" vs "20 de reparații"). Pass `count` (or `n`) and t('ns.key', {count})
// picks the form with the browser's own CLDR rules (Intl.PluralRules), falling back to `_other`, then the
// bare key. One rule object per locale is plenty.
const pluralRules: Record<string, Intl.PluralRules> = {};
const pluralCategory = (locale: string, n: number) => (pluralRules[locale] ??= new Intl.PluralRules(locale)).select(n);

export function useT(): TFn {
    const props = usePage().props as { translations?: Dict; locale?: string };
    const dict = props.translations ?? {};
    const locale = props.locale ?? 'en';
    return (key, params) => {
        const n = params?.count ?? params?.n;
        const plural = typeof n === 'number' ? (lookup(dict, `${key}_${pluralCategory(locale, n)}`) ?? lookup(dict, `${key}_other`)) : undefined;
        return interpolate(plural ?? lookup(dict, key) ?? key, params);
    };
}

// The user's app language for plain (non-hook) helpers — relativeTime, shortDate, money, mileage — that
// format dates/numbers outside a component. app.tsx sets it from the first page and every visit. Before
// that (or in the MCP widgets, which don't run app.tsx) it stays English, which is the fallback anyway.
let currentLocale = 'en';
export const appLocale = (): string => currentLocale;
export function setAppLocale(locale: string | undefined): void {
    currentLocale = locale ?? 'en';
    // html lang drives CSS hyphenation; keep it right after an in-app language switch too.
    if (typeof document !== 'undefined') document.documentElement.lang = currentLocale;
}

// "today" / "morgen" / "acum": relative words straight from the browser's CLDR data, no lang keys needed.
export function relativeWord(value: number, unit: Intl.RelativeTimeFormatUnit): string {
    const word = new Intl.RelativeTimeFormat(currentLocale, { numeric: 'auto' }).format(value, unit);
    return word.charAt(0).toLocaleUpperCase(currentLocale) + word.slice(1);
}

export function useLocale(): string {
    return (usePage().props as { locale?: string }).locale ?? 'en';
}
