import { Head } from '@inertiajs/react';
import { Mic } from 'lucide-react';
import { useState } from 'react';

import AppLayout from '@/layouts/app-layout';
import { getVoiceLang, setVoiceLang, VOICE_LANGS } from '@/lib/shopping';
import { type BreadcrumbItem } from '@/types';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Shopping', href: '/m/shopping' },
    { title: 'Settings', href: '/m/shopping/settings' },
];

export default function ShoppingSettings() {
    const [lang, setLang] = useState(getVoiceLang);

    const choose = (code: string) => {
        setLang(code);
        setVoiceLang(code); // persisted per-device (localStorage)
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Shopping settings" />
            <div className="mx-auto flex w-full max-w-2xl flex-col gap-4 p-4">
                <h1 className="font-heading text-primary text-2xl">Settings</h1>

                <section className="bg-surface-card flex flex-col gap-3 rounded-2xl border p-4">
                    <div className="flex items-center gap-2">
                        <Mic className="text-primary size-5" />
                        <h2 className="text-primary font-medium">Voice dictation</h2>
                    </div>
                    <p className="text-muted-foreground text-sm">
                        The language the mic listens in when you add items by voice. It can't be detected automatically, so pick the one you speak.
                    </p>
                    <div className="flex flex-wrap gap-2">
                        {VOICE_LANGS.map((l) => {
                            const active = lang === l.code;
                            return (
                                <button
                                    key={l.code}
                                    type="button"
                                    onClick={() => choose(l.code)}
                                    aria-pressed={active}
                                    className={`rounded-full border px-4 py-2 text-sm font-medium transition-colors ${
                                        active ? 'border-primary bg-primary text-primary-foreground' : 'border-border text-primary hover:bg-surface'
                                    }`}
                                >
                                    {l.label}
                                </button>
                            );
                        })}
                    </div>
                    <p className="text-text-muted text-xs">Saved on this device.</p>
                </section>
            </div>
        </AppLayout>
    );
}
