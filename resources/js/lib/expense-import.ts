import type { TFn } from '@/lib/i18n';

// One statement upload or one person's Apple Pay review queue, as the Expense import pages receive it.
export type ImportBatchCard = {
    id: number;
    source: 'statement' | 'shortcut';
    status: 'mapping' | 'review' | 'imported' | 'undone';
    bank: string | null;
    filename: string | null;
    rows: number;
    imported: number;
    createdAt: string | null;
    importedAt: string | null;
};

export type ListOption = { id: number; name: string; currency: string };
export type MemberOption = { key: string; name: string };

// "Wise · statement-oct.csv", or "Apple Pay payments" for the Shortcut queue.
export const batchTitle = (t: TFn, b: ImportBatchCard): string =>
    b.source === 'shortcut' ? t('expense_import.apple_pay_queue') : [b.bank, b.filename].filter(Boolean).join(' · ') || t('expense_import.statement');

export const shortDate = (iso: string | null): string =>
    iso ? new Date(iso).toLocaleDateString(undefined, { day: 'numeric', month: 'short', year: 'numeric' }) : '';

// Same-origin JSON POST with the session's CSRF cookie (the review screen's AI-category loop).
export async function postJson<T>(url: string): Promise<T> {
    const xsrf = decodeURIComponent(document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]+)/)?.[1] ?? '');
    const res = await fetch(url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-XSRF-TOKEN': xsrf, Accept: 'application/json' },
        credentials: 'same-origin',
        body: '{}',
    });
    if (!res.ok) throw new Error(String(res.status));
    return res.json();
}
