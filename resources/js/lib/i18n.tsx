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

export function useLocale(): string {
    return (usePage().props as { locale?: string }).locale ?? 'en';
}
