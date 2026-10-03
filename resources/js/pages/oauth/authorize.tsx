import { Head } from '@inertiajs/react';
import { useState } from 'react';

import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import AuthLayout from '@/layouts/auth-layout';
import { useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';

type Access = 'none' | 'read' | 'write';
type Module = { id: string; name: string };
type Family = { id: number; name: string; modules: Module[] };

const LEVELS: Access[] = ['none', 'read', 'write'];

// OAuth consent for an MCP client (Claude, ChatGPT, Cursor...). The person picks ONE family and, per
// module, No access / Read / Read & write. Default is No access everywhere: nothing is shared unless
// chosen. Submits as a NATIVE form, not Inertia: approving redirects to the client's callback URL (another
// site or a claude:// link), which an Inertia XHR cannot follow. Server side: App\Http\Controllers\OAuth.
export default function Authorize({
    client,
    families,
    activeFamilyId,
    authToken,
    csrfToken,
}: {
    client: { id: string; name: string };
    families: Family[];
    activeFamilyId: number | null;
    authToken: string;
    csrfToken: string;
}) {
    const t = useT();
    const [familyId, setFamilyId] = useState<number | null>(families.find((f) => f.id === activeFamilyId)?.id ?? families[0]?.id ?? null);
    const [access, setAccess] = useState<Record<string, Access>>({});
    const family = families.find((f) => f.id === familyId);
    // Module rows follow the selected family (each family enables its own modules); reset on switch.
    const pickFamily = (id: string) => {
        setFamilyId(Number(id));
        setAccess({});
    };

    return (
        <AuthLayout title={t('mcp.consent_title', { app: client.name })} description={t('mcp.consent_desc')}>
            <Head title={t('mcp.consent_head')} />

            {families.length === 0 ? (
                <p className="text-muted-foreground text-center text-sm">{t('mcp.no_family')}</p>
            ) : (
                <form method="post" action="/oauth/authorize" className="flex flex-col gap-6">
                    <input type="hidden" name="_token" value={csrfToken} />
                    <input type="hidden" name="auth_token" value={authToken} />
                    <input type="hidden" name="family_id" value={familyId ?? ''} />
                    {family?.modules.map((m) => <input key={m.id} type="hidden" name={`access[${m.id}]`} value={access[m.id] ?? 'none'} />)}

                    {families.length > 1 && (
                        <div className="grid gap-2">
                            <Label>{t('mcp.family')}</Label>
                            <Select value={String(familyId)} onValueChange={pickFamily}>
                                <SelectTrigger>
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    {families.map((f) => (
                                        <SelectItem key={f.id} value={String(f.id)}>
                                            {f.name}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>
                    )}

                    <div className="grid gap-3">
                        <Label>{t('mcp.what_it_can_do', { family: family?.name ?? '' })}</Label>
                        {family?.modules.length ? (
                            family.modules.map((m) => (
                                <div key={m.id} className="flex items-center justify-between gap-3">
                                    <span className="text-sm font-medium">{t(`nav.${m.id}`)}</span>
                                    <div role="radiogroup" aria-label={t(`nav.${m.id}`)} className="border-border flex rounded-lg border p-0.5">
                                        {LEVELS.map((level) => {
                                            const on = (access[m.id] ?? 'none') === level;
                                            return (
                                                <button
                                                    key={level}
                                                    type="button"
                                                    role="radio"
                                                    aria-checked={on}
                                                    onClick={() => setAccess((a) => ({ ...a, [m.id]: level }))}
                                                    className={cn(
                                                        'rounded-md px-2.5 py-1.5 text-xs font-medium whitespace-nowrap transition-colors',
                                                        on ? 'bg-primary text-primary-foreground' : 'text-muted-foreground hover:text-foreground',
                                                    )}
                                                >
                                                    {t(`mcp.level_${level}`)}
                                                </button>
                                            );
                                        })}
                                    </div>
                                </div>
                            ))
                        ) : (
                            <p className="text-muted-foreground text-sm">{t('mcp.no_modules')}</p>
                        )}
                        <p className="text-muted-foreground text-xs">{t('mcp.consent_footnote')}</p>
                    </div>

                    <div className="flex flex-col gap-2">
                        <Button type="submit" className="w-full" disabled={!familyId}>
                            {t('mcp.allow')}
                        </Button>
                        {/* Cancel = Passport's deny (DELETE /oauth/authorize): redirects back with error=access_denied. */}
                        <Button type="submit" variant="ghost" className="w-full" form="mcp-deny">
                            {t('common.cancel')}
                        </Button>
                    </div>
                </form>
            )}
            <form id="mcp-deny" method="post" action="/oauth/authorize" className="hidden">
                <input type="hidden" name="_token" value={csrfToken} />
                <input type="hidden" name="_method" value="DELETE" />
                <input type="hidden" name="auth_token" value={authToken} />
            </form>
        </AuthLayout>
    );
}
