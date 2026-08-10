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

export function useT(): TFn {
    const dict = (usePage().props as { translations?: Dict }).translations ?? {};
    return (key, params) => interpolate(lookup(dict, key) ?? key, params);
}

export function useLocale(): string {
    return (usePage().props as { locale?: string }).locale ?? 'en';
}
