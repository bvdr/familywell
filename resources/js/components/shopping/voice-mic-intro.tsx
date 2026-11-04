import { AudioLines, Mic, MicOff, Radio, Settings, X } from 'lucide-react';

import { useT } from '@/lib/i18n';

// S3a "Allow mic (first time)" (design GwqH4): shown once, before the browser's own microphone and speech prompts, so
// people know why they are coming; Allow is the tap that starts listening (and with it the system prompts). After a
// refusal (`denied`) the same screen says how to turn the mic back on and offers typing instead.
export function VoiceMicIntro({
    denied,
    onAllow,
    onType,
    onClose,
}: {
    denied: boolean;
    onAllow: () => void;
    onType: () => void;
    onClose: () => void;
}) {
    const t = useT();
    const Icon = denied ? MicOff : Mic;
    return (
        <div className="bg-ink fixed inset-0 flex flex-col text-white">
            <div className="px-5 pt-[calc(1rem+env(safe-area-inset-top))]">
                <button
                    type="button"
                    onClick={onClose}
                    aria-label={t('shopping.close')}
                    className="flex size-10 items-center justify-center rounded-full bg-white/10"
                >
                    <X className="size-5" />
                </button>
            </div>

            <div className="flex min-h-0 flex-1 flex-col items-center justify-center overflow-y-auto px-5 text-center">
                <span className="bg-sun/15 flex size-[132px] items-center justify-center rounded-full">
                    <span className="bg-sun text-ink flex size-20 items-center justify-center rounded-full">
                        <Icon className="size-8" />
                    </span>
                </span>
                <h2 className="font-display mt-7 text-[30px] leading-tight font-extrabold tracking-tight">
                    {t(denied ? 'shopping.mic_denied_title' : 'shopping.mic_intro_title')}
                </h2>
                <p className="mt-3 max-w-sm text-base leading-relaxed text-white/60">
                    {t(denied ? 'shopping.mic_denied_sub' : 'shopping.mic_intro_sub')}
                </p>
                {!denied && (
                    <ul className="mt-7 w-full max-w-sm space-y-3.5 rounded-[20px] border border-white/10 bg-white/5 px-5 py-4 text-left text-[15px]">
                        {(
                            [
                                [Radio, 'shopping.mic_intro_open'],
                                [AudioLines, 'shopping.mic_intro_words'],
                                [Settings, 'shopping.mic_intro_settings'],
                            ] as const
                        ).map(([RowIcon, key]) => (
                            <li key={key} className="flex items-center gap-3">
                                <RowIcon className="text-sun size-5 shrink-0" />
                                {t(key)}
                            </li>
                        ))}
                    </ul>
                )}
            </div>

            <div className="space-y-2 px-5 pt-4 pb-[calc(1.25rem+env(safe-area-inset-bottom))]">
                {!denied && (
                    <button
                        type="button"
                        onClick={onAllow}
                        className="bg-sun text-ink flex h-14 w-full items-center justify-center gap-2.5 rounded-full text-lg font-bold"
                    >
                        <Mic className="size-5" />
                        {t('shopping.allow_mic')}
                    </button>
                )}
                <button
                    type="button"
                    onClick={onType}
                    className={
                        denied
                            ? 'bg-sun text-ink flex h-14 w-full items-center justify-center rounded-full text-lg font-bold'
                            : 'w-full py-2 text-base font-semibold text-white/60'
                    }
                >
                    {t('shopping.type_instead')}
                </button>
            </div>
        </div>
    );
}
