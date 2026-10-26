import { Head, router } from '@inertiajs/react';
import { ArrowRight, Check, ChevronDown, Link2, LoaderCircle, Mail, Plus, UsersRound, X } from 'lucide-react';
import { FormEventHandler, useState } from 'react';

import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { DropdownMenu, DropdownMenuContent, DropdownMenuRadioGroup, DropdownMenuRadioItem, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import OnboardingLayout from '@/layouts/onboarding-layout';
import { useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';

type Role = 'adult' | 'teen';
type Invite = { email: string; role: Role };

// Avatar colours for the invite rows, cycling through the onboarding palette minus sun (the CTA) and ink.
const AVATAR = ['#7FA8FF', '#5BD38A', '#C8A6FF', '#FF5A3C'];
const isEmail = (v: string) => /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v);

// Step 2 (design 02): skipping is guilt-free, and the one-price line answers "will this cost more per person?"
export default function Invite({ link, invited }: { link: string; invited: Invite[] }) {
    const t = useT();
    const [invites, setInvites] = useState<Invite[]>(invited);
    const [email, setEmail] = useState('');
    const [copied, setCopied] = useState(false);
    const [processing, setProcessing] = useState(false);
    const [errors, setErrors] = useState<Record<string, string>>({});

    const add = () => {
        const e = email.trim().toLowerCase();
        if (!isEmail(e)) return setErrors({ email: t('onboarding.invite_bad_email') });
        if (!invites.some((i) => i.email === e)) setInvites([...invites, { email: e, role: 'adult' }]);
        setEmail('');
        setErrors({});
    };
    const onAdd: FormEventHandler = (e) => {
        e.preventDefault();
        add();
    };

    // An address still in the box counts too: typing one and pressing Send shouldn't silently drop it.
    const pending = isEmail(email.trim()) && !invites.some((i) => i.email === email.trim().toLowerCase());
    const all = pending ? [...invites, { email: email.trim().toLowerCase(), role: 'adult' as Role }] : invites;

    const send = (list: Invite[]) =>
        router.post(
            route('onboarding.invite.store'),
            { invites: list },
            { onStart: () => setProcessing(true), onFinish: () => setProcessing(false), onError: setErrors },
        );

    const copy = async () => {
        await navigator.clipboard?.writeText(link);
        setCopied(true);
        setTimeout(() => setCopied(false), 2000);
    };

    return (
        <>
            <Head title={t('onboarding.invite_title')} />
            <OnboardingLayout
                step={2}
                back={route('onboarding.create')}
                title={t('onboarding.invite_title')}
                sub={t('onboarding.invite_sub')}
                cta={
                    // Phone: the one-price pill, Send, then "later" under it. Desktop: one row, Send on the right.
                    <div className="flex w-full flex-col items-center gap-3 lg:flex-row lg:gap-[18px]">
                        <OnePrice className="lg:hidden" />
                        <Button
                            variant="sun"
                            size="cta"
                            className="w-full lg:order-3 md:w-auto md:min-w-[260px]"
                            disabled={processing || all.length === 0}
                            onClick={() => send(all)}
                        >
                            {all.length ? t('onboarding.invite_send', { count: all.length }) : t('onboarding.invite_send_none')}
                            {processing ? <LoaderCircle className="animate-spin" /> : <ArrowRight />}
                        </Button>
                        <button type="button" disabled={processing} onClick={() => send([])} className="text-ink-2 shrink-0 text-sm font-semibold">
                            {t('onboarding.invite_later')}
                        </button>
                    </div>
                }
            >
                <div className="flex flex-col gap-3 px-5 lg:px-0">
                    <form onSubmit={onAdd} className="flex gap-2">
                        <div className="relative flex-1">
                            <Mail className="text-stone pointer-events-none absolute top-1/2 left-4 size-[18px] -translate-y-1/2" />
                            <Input
                                type="email"
                                inputMode="email"
                                autoComplete="off"
                                value={email}
                                onChange={(e) => setEmail(e.target.value)}
                                placeholder={t('onboarding.invite_placeholder')}
                                aria-label={t('onboarding.invite_placeholder')}
                                className="border-ink focus-visible:border-ink h-[52px] rounded-[14px] border-[1.5px] bg-white pl-11 text-[15px] focus-visible:ring-0 focus-visible:ring-offset-0"
                            />
                        </div>
                        <Button
                            type="submit"
                            variant="ink"
                            aria-label={t('onboarding.invite_add')}
                            className="size-[52px] rounded-[14px] p-0 [&_svg]:size-[22px]"
                        >
                            <Plus />
                        </Button>
                    </form>
                    <InputError message={errors.email ?? Object.values(errors)[0]} />

                    {invites.length > 0 && (
                        <ul className="border-line divide-line divide-y rounded-2xl border bg-white">
                            {invites.map((inv, i) => (
                                <li key={inv.email} className="flex items-center gap-3 px-3.5 py-3">
                                    <span
                                        className="text-ink flex size-9 shrink-0 items-center justify-center rounded-full text-sm font-bold"
                                        style={{ backgroundColor: AVATAR[i % AVATAR.length] }}
                                    >
                                        {inv.email[0].toUpperCase()}
                                    </span>
                                    <span className="min-w-0 flex-1 truncate text-sm font-medium">{inv.email}</span>
                                    <DropdownMenu>
                                        <DropdownMenuTrigger className="bg-paper-2 inline-flex items-center gap-1 rounded-full py-[5px] pr-2 pl-2.5 text-xs font-semibold">
                                            {t(`onboarding.role_${inv.role}`)}
                                            <ChevronDown className="text-ink-2 size-[13px]" />
                                        </DropdownMenuTrigger>
                                        <DropdownMenuContent align="end">
                                            <DropdownMenuRadioGroup
                                                value={inv.role}
                                                onValueChange={(r) =>
                                                    setInvites(invites.map((x) => (x.email === inv.email ? { ...x, role: r as Role } : x)))
                                                }
                                            >
                                                {(['adult', 'teen'] as const).map((r) => (
                                                    <DropdownMenuRadioItem key={r} value={r} className="flex flex-col items-start">
                                                        <span className="font-medium">{t(`onboarding.role_${r}`)}</span>
                                                        <span className="text-muted-foreground text-xs">{t(`onboarding.role_${r}_desc`)}</span>
                                                    </DropdownMenuRadioItem>
                                                ))}
                                            </DropdownMenuRadioGroup>
                                        </DropdownMenuContent>
                                    </DropdownMenu>
                                    <button
                                        type="button"
                                        aria-label={t('onboarding.invite_remove')}
                                        onClick={() => setInvites(invites.filter((x) => x.email !== inv.email))}
                                    >
                                        <X className="text-stone size-4" />
                                    </button>
                                </li>
                            ))}
                        </ul>
                    )}

                    <div className="border-line flex items-center gap-3 rounded-2xl border px-3.5 py-3">
                        <span className="bg-paper-2 flex size-9 shrink-0 items-center justify-center rounded-[10px]">
                            <Link2 className="size-[17px]" />
                        </span>
                        <div className="min-w-0 flex-1">
                            <p className="text-sm font-semibold">{t('onboarding.invite_link')}</p>
                            <p className="text-stone truncate font-mono text-xs">{link.replace(/^https?:\/\//, '')}</p>
                        </div>
                        <Button type="button" variant="ink" size="sm" onClick={copy} className="h-[29px] rounded-full px-3 text-xs font-semibold">
                            {copied ? <Check /> : t('onboarding.copy')}
                        </Button>
                    </div>
                    <OnePrice className="mt-2 hidden self-start lg:inline-flex" />
                </div>
            </OnboardingLayout>
        </>
    );
}

// "Everyone's included": answers "will this cost more per person?" before it's asked. Above the CTA on phones,
// under the list on desktop (the bottom bar has no room for it there).
function OnePrice({ className }: { className?: string }) {
    const t = useT();
    return (
        <span className={cn('bg-sun-soft text-ink inline-flex items-center gap-2 rounded-2xl px-3 py-2 text-xs font-medium', className)}>
            <UsersRound className="size-[15px] shrink-0" />
            {t('onboarding.invite_one_price')}
        </span>
    );
}
