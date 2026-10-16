import { Head, Link, router, usePage } from '@inertiajs/react';
import { ChevronRight, FileUp, Loader2, Settings, Smartphone } from 'lucide-react';
import { useRef, useState } from 'react';

import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { batchTitle, shortDate, type ImportBatchCard } from '@/lib/expense-import';
import { useT } from '@/lib/i18n';
import type { BreadcrumbItem } from '@/types';

type Props = {
    statementsEnabled: boolean;
    shortcutEnabled: boolean;
    pending: ImportBatchCard[];
    history: ImportBatchCard[];
};

// Expense import home: upload a statement, the imports waiting for review, and past imports (undo per batch).
export default function ExpenseImportIndex({ statementsEnabled, shortcutEnabled, pending, history }: Props) {
    const t = useT();
    const errors = usePage<{ errors: Record<string, string> }>().props.errors;
    const fileRef = useRef<HTMLInputElement>(null);
    const [uploading, setUploading] = useState(false);
    const breadcrumbs: BreadcrumbItem[] = [{ title: t('expense_import.title'), href: route('expense_import.index') }];

    const upload = (file: File | undefined) => {
        if (!file) return;
        setUploading(true);
        router.post(route('expense_import.upload'), { file }, { forceFormData: true, onFinish: () => setUploading(false) });
        if (fileRef.current) fileRef.current.value = '';
    };

    const off = !statementsEnabled && !shortcutEnabled;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('expense_import.title')} />
            <div className="mx-auto flex w-full max-w-2xl flex-col gap-4 p-4">
                <div className="flex items-start justify-between gap-3">
                    <div className="min-w-0">
                        <h1 className="font-heading text-primary text-2xl">{t('expense_import.title')}</h1>
                        <p className="text-muted-foreground text-sm">{t('expense_import.subtitle')}</p>
                    </div>
                    <Button variant="outline" size="icon" asChild className="shrink-0">
                        <Link href={route('expense_import.settings')} aria-label={t('expense_import.open_settings')}>
                            <Settings className="size-4" />
                        </Link>
                    </Button>
                </div>

                {off && (
                    <section className="bg-surface-card flex flex-col items-start gap-3 rounded-xl border p-5">
                        <div>
                            <h2 className="font-heading text-primary text-lg">{t('expense_import.off_title')}</h2>
                            <p className="text-muted-foreground text-sm">{t('expense_import.off_body')}</p>
                        </div>
                        <Button asChild>
                            <Link href={route('expense_import.settings')}>{t('expense_import.open_settings')}</Link>
                        </Button>
                    </section>
                )}

                {statementsEnabled && (
                    <section className="bg-surface-card flex flex-col gap-3 rounded-xl border p-5">
                        <div>
                            <h2 className="font-heading text-primary text-lg">{t('expense_import.upload_title')}</h2>
                            <p className="text-muted-foreground text-sm">{t('expense_import.upload_body')}</p>
                        </div>
                        <input
                            ref={fileRef}
                            type="file"
                            accept=".csv,.xlsx,text/csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"
                            className="hidden"
                            onChange={(e) => upload(e.target.files?.[0])}
                        />
                        <div>
                            <Button onClick={() => fileRef.current?.click()} disabled={uploading}>
                                {uploading ? <Loader2 className="size-4 animate-spin" /> : <FileUp className="size-4" />}
                                {uploading ? t('expense_import.uploading') : t('expense_import.choose_file')}
                            </Button>
                        </div>
                        {errors.file && <p className="text-destructive text-sm">{errors.file}</p>}
                    </section>
                )}

                {pending.length > 0 && (
                    <section className="flex flex-col gap-2">
                        <h2 className="text-muted-foreground px-1 text-xs font-semibold tracking-widest uppercase">{t('expense_import.waiting')}</h2>
                        <ul className="bg-border grid grid-cols-1 gap-px overflow-hidden rounded-2xl border">
                            {pending.map((b) => (
                                <li key={b.id}>
                                    <Link
                                        href={route(b.status === 'mapping' ? 'expense_import.map' : 'expense_import.batch', b.id)}
                                        className="bg-surface-card flex items-center gap-3 px-4 py-3"
                                    >
                                        <BatchIcon batch={b} />
                                        <span className="min-w-0 flex-1">
                                            <span className="text-primary block truncate text-[15px]">{batchTitle(t, b)}</span>
                                            <span className="text-muted-foreground block truncate text-xs">
                                                {shortDate(b.createdAt)} · {t('expense_import.rows_count', { count: b.rows })}
                                            </span>
                                        </span>
                                        <span className="text-primary flex shrink-0 items-center gap-1 text-sm font-medium">
                                            {t('expense_import.review')}
                                            <ChevronRight className="size-4" />
                                        </span>
                                    </Link>
                                </li>
                            ))}
                        </ul>
                    </section>
                )}

                {!off && (
                    <section className="flex flex-col gap-2">
                        <h2 className="text-muted-foreground px-1 text-xs font-semibold tracking-widest uppercase">{t('expense_import.history')}</h2>
                        {history.length === 0 ? (
                            <p className="text-muted-foreground px-1 text-sm">{t('expense_import.no_history')}</p>
                        ) : (
                            <ul className="bg-border grid grid-cols-1 gap-px overflow-hidden rounded-2xl border">
                                {history.map((b) => (
                                    <li key={b.id} className="bg-surface-card flex items-center gap-3 px-4 py-3">
                                        <BatchIcon batch={b} />
                                        <Link href={route('expense_import.batch', b.id)} className="min-w-0 flex-1">
                                            <span className="text-primary block truncate text-[15px]">{batchTitle(t, b)}</span>
                                            <span className="text-muted-foreground block truncate text-xs">
                                                {shortDate(b.importedAt ?? b.createdAt)} · {t('expense_import.imported_count', { count: b.imported })}
                                            </span>
                                        </Link>
                                        {b.status === 'undone' ? (
                                            <span className="text-muted-foreground shrink-0 text-xs">{t('expense_import.undone')}</span>
                                        ) : (
                                            b.imported > 0 && (
                                                <Button
                                                    variant="outline"
                                                    size="sm"
                                                    className="shrink-0"
                                                    onClick={() =>
                                                        window.confirm(t('expense_import.undo_confirm')) &&
                                                        router.post(route('expense_import.undo', b.id), {}, { preserveScroll: true })
                                                    }
                                                >
                                                    {t('expense_import.undo')}
                                                </Button>
                                            )
                                        )}
                                    </li>
                                ))}
                            </ul>
                        )}
                    </section>
                )}
            </div>
        </AppLayout>
    );
}

function BatchIcon({ batch }: { batch: ImportBatchCard }) {
    const Icon = batch.source === 'shortcut' ? Smartphone : FileUp;
    return (
        <span className="bg-tile text-tile-foreground flex size-9 shrink-0 items-center justify-center rounded-lg">
            <Icon className="size-4.5" />
        </span>
    );
}
