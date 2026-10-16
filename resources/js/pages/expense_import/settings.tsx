import { Head, router, usePage } from '@inertiajs/react';
import { Check, Copy, Download, User, Wallet } from 'lucide-react';
import { useState } from 'react';

import { Picker } from '@/components/picker';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { useOptimistic } from '@/hooks/use-optimistic';
import AppLayout from '@/layouts/app-layout';
import type { ListOption, MemberOption } from '@/lib/expense-import';
import { useT } from '@/lib/i18n';
import type { BreadcrumbItem } from '@/types';

type Settings = {
    statementsEnabled: boolean;
    shortcutEnabled: boolean;
    defaultEventId: number | null;
    defaultPayerKey: string | null;
    autoSave: boolean;
};

type Props = {
    settings: Settings;
    lists: ListOption[];
    members: MemberOption[];
    canManage: boolean; // family admin: the switches and defaults (the server enforces it too)
    payUrl: string | null; // this person's own Apple Pay link
    shortcutUrl: string | null; // iCloud link to the ready-made Shortcut, when configured
};

// Everything about Expense import is switched on and configured here (reached from Settings > Modules).
export default function ExpenseImportSettings({ settings, lists, members, canManage, payUrl, shortcutUrl }: Props) {
    const t = useT();
    const errors = usePage<{ errors: Record<string, string> }>().props.errors;
    const opt = useOptimistic<string | boolean | number | null>();
    const get = <K extends keyof Settings>(k: K) => opt.get(k, settings[k]) as Settings[K];
    const [copied, setCopied] = useState(false);

    // Instant-save: each control sends only its own field; the switch flips at once and snaps back on failure.
    const save = (key: keyof Settings, value: string | boolean | number | null, field: string) => {
        opt.set(key, value);
        router.patch(
            route('expense_import.settings.update'),
            { [field]: value },
            { preserveScroll: true, preserveState: true, onSuccess: () => opt.clear(key), onError: () => opt.clear(key) },
        );
    };

    const copy = async () => {
        if (!payUrl) return;
        await navigator.clipboard?.writeText(payUrl);
        setCopied(true);
        setTimeout(() => setCopied(false), 1500);
    };

    const breadcrumbs: BreadcrumbItem[] = [
        { title: t('expense_import.title'), href: route('expense_import.index') },
        { title: t('expense_import.settings'), href: route('expense_import.settings') },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('expense_import.settings')} />
            <div className="mx-auto flex w-full max-w-2xl flex-col gap-4 p-4">
                <div>
                    <h1 className="font-heading text-primary text-2xl">{t('expense_import.settings')}</h1>
                    <p className="text-muted-foreground text-sm">{t('expense_import.settings_sub')}</p>
                </div>

                <section className="bg-surface-card flex flex-col gap-1 rounded-xl border p-5">
                    <ToggleRow
                        label={t('expense_import.statements_title')}
                        hint={t('expense_import.statements_desc')}
                        on={get('statementsEnabled')}
                        disabled={!canManage}
                        onToggle={() => save('statementsEnabled', !get('statementsEnabled'), 'statements_enabled')}
                    />
                    <ToggleRow
                        label={t('expense_import.shortcut_title')}
                        hint={t('expense_import.shortcut_desc')}
                        on={get('shortcutEnabled')}
                        disabled={!canManage}
                        onToggle={() => save('shortcutEnabled', !get('shortcutEnabled'), 'shortcut_enabled')}
                    />
                    {get('shortcutEnabled') && (
                        <ToggleRow
                            label={t('expense_import.auto_save')}
                            hint={t('expense_import.auto_save_hint')}
                            on={get('autoSave')}
                            disabled={!canManage}
                            onToggle={() => save('autoSave', !get('autoSave'), 'auto_save')}
                        />
                    )}
                </section>

                {get('shortcutEnabled') && (
                    <section className="bg-surface-card flex flex-col gap-3 rounded-xl border p-5">
                        <div>
                            <h2 className="font-heading text-primary text-lg">{t('expense_import.link_title')}</h2>
                            <p className="text-muted-foreground text-sm">{t('expense_import.link_desc')}</p>
                        </div>
                        {payUrl ? (
                            <div className="flex min-w-0 items-center gap-2">
                                <code className="bg-surface text-primary min-w-0 flex-1 truncate rounded-lg border px-3 py-2 text-xs">{payUrl}</code>
                                <Button variant="outline" size="sm" onClick={copy} className="shrink-0">
                                    {copied ? <Check className="size-4" /> : <Copy className="size-4" />}
                                    {copied ? t('expense_import.link_copied') : t('expense_import.link_copy')}
                                </Button>
                            </div>
                        ) : (
                            <p className="text-muted-foreground text-sm">{t('expense_import.link_none')}</p>
                        )}
                        {errors.token && <p className="text-destructive text-sm">{errors.token}</p>}
                        <div className="flex flex-wrap gap-2">
                            <Button
                                variant={payUrl ? 'outline' : 'default'}
                                onClick={() => router.post(route('expense_import.token.create'), {}, { preserveScroll: true })}
                            >
                                {payUrl ? t('expense_import.link_rotate') : t('expense_import.link_create')}
                            </Button>
                            {payUrl && (
                                <Button variant="ghost" onClick={() => router.delete(route('expense_import.token.revoke'), { preserveScroll: true })}>
                                    {t('expense_import.link_revoke')}
                                </Button>
                            )}
                        </div>

                        <div className="mt-2 flex flex-col gap-2 border-t pt-4">
                            <h3 className="text-primary text-sm font-semibold">{t('expense_import.setup_title')}</h3>
                            {shortcutUrl && (
                                <div>
                                    <Button asChild>
                                        <a href={shortcutUrl} target="_blank" rel="noreferrer">
                                            <Download className="size-4" /> {t('expense_import.install_shortcut')}
                                        </a>
                                    </Button>
                                </div>
                            )}
                            <ol className="text-primary list-decimal space-y-1.5 pl-5 text-sm">
                                {[1, 2, 3, 4].map((n) => (
                                    <li key={n}>{t(`expense_import.setup_${n}`)}</li>
                                ))}
                            </ol>
                            <p className="text-muted-foreground text-xs">{t('expense_import.setup_note')}</p>
                        </div>
                    </section>
                )}

                <section className="bg-surface-card flex flex-col gap-4 rounded-xl border p-5">
                    <div>
                        <h2 className="font-heading text-primary text-lg">{t('expense_import.defaults_title')}</h2>
                        <p className="text-muted-foreground text-sm">{t('expense_import.defaults_desc')}</p>
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="default-list">{t('expense_import.default_list')}</Label>
                        <Picker
                            id="default-list"
                            fallbackIcon={Wallet}
                            disabled={!canManage}
                            options={[
                                { value: '', label: t('expense_import.default_list_others') },
                                ...lists.map((l) => ({ value: String(l.id), label: `${l.name} · ${l.currency}` })),
                            ]}
                            selected={[String(get('defaultEventId') ?? '')]}
                            onChange={([v]) => save('defaultEventId', v ? Number(v) : null, 'default_event_id')}
                        />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="default-payer">{t('expense_import.default_payer')}</Label>
                        <Picker
                            id="default-payer"
                            fallbackIcon={User}
                            disabled={!canManage}
                            options={[
                                { value: '', label: t('expense_import.default_payer_self') },
                                ...members.map((m) => ({ value: m.key, label: m.name })),
                            ]}
                            selected={[get('defaultPayerKey') ?? '']}
                            onChange={([v]) => save('defaultPayerKey', v || null, 'default_payer_key')}
                        />
                    </div>
                </section>

                {!canManage && <p className="text-muted-foreground px-1 text-xs">{t('expense_import.admin_only')}</p>}
            </div>
        </AppLayout>
    );
}

// A label (+ hint) with a switch on the right; same control as the other module settings pages.
function ToggleRow({ label, hint, on, disabled, onToggle }: { label: string; hint?: string; on: boolean; disabled?: boolean; onToggle: () => void }) {
    return (
        <div className={`flex items-center justify-between gap-3 py-2.5 ${disabled ? 'opacity-60' : ''}`}>
            <div className="min-w-0">
                <p className="text-primary text-sm font-medium">{label}</p>
                {hint && <p className="text-muted-foreground text-xs">{hint}</p>}
            </div>
            <button
                type="button"
                role="switch"
                aria-checked={on}
                aria-label={label}
                disabled={disabled}
                onClick={onToggle}
                className={`relative h-6 w-10 shrink-0 rounded-full transition-colors disabled:cursor-not-allowed ${on ? 'bg-accent-strong' : 'bg-border'}`}
            >
                <span className={`absolute top-0.5 size-5 rounded-full bg-white shadow-sm transition-all ${on ? 'left-[18px]' : 'left-0.5'}`} />
            </button>
        </div>
    );
}
