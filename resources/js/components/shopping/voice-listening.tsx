import { Check, ChevronDown, Keyboard, Loader2, Mic, RotateCcw, X } from 'lucide-react';
import { useEffect, useState } from 'react';

import { useT } from '@/lib/i18n';
import { VOICE_LANGS } from '@/lib/shopping';

// The design's waveform (S3b): a bell of bars, tallest in the middle. Heights are fixed fractions; while listening each
// bar breathes (voice-bar keyframes in app.css) at its own offset, so the whole thing ripples.
// ponytail: an animated stand-in, not the real mic level. The real level needs getUserMedia + an AnalyserNode next to
// SpeechRecognition, and iOS Safari hands the mic to only one of them (recognition stops). Revisit if iOS allows both.
const BARS = Array.from({ length: 46 }, (_, i) => {
    const x = (i - 22.5) / 11; // -2..2 across the row
    return Math.max(0.08, Math.exp(-x * x) * (0.75 + 0.25 * Math.sin(i * 2.3)));
});

const mmss = (s: number) => `${Math.floor(s / 60)}:${String(s % 60).padStart(2, '0')}`;

// S3b "Listening" (design EJ3uk): the whole screen in ink so it's obvious the app is listening. What you said so far in
// big type (the part the browser may still change dimmed), the items heard so far as chips, and Type / Done / Redo.
// Recognition itself lives in the list page; this only draws it and reports taps.
export function VoiceListening({
    listName,
    finalText,
    interim,
    chips,
    listening,
    parsing,
    lang,
    onLang,
    onClose,
    onStart,
    onDone,
    onRedo,
    onType,
}: {
    listName: string;
    finalText: string;
    interim: string;
    chips: string[];
    listening: boolean;
    parsing: boolean;
    lang: string;
    onLang: (code: string) => void;
    onClose: () => void;
    onStart: () => void;
    onDone: () => void;
    onRedo: () => void;
    onType: () => void;
}) {
    const t = useT();

    // Seconds since this listening run started (the pill's 0:07). Restarts with every run (Redo, a language change).
    const [sec, setSec] = useState(0);
    useEffect(() => {
        if (!listening) return;
        const t0 = Date.now();
        setSec(0);
        const id = setInterval(() => setSec(Math.floor((Date.now() - t0) / 1000)), 1000);
        return () => clearInterval(id);
    }, [listening]);

    const said = finalText + interim;

    return (
        <div className="bg-ink fixed inset-0 flex flex-col text-white">
            <div className="flex items-center justify-between gap-3 px-5 pt-[calc(1rem+env(safe-area-inset-top))]">
                <button
                    type="button"
                    onClick={onClose}
                    aria-label={t('shopping.close')}
                    className="flex size-10 shrink-0 items-center justify-center rounded-full bg-white/10"
                >
                    <X className="size-5" />
                </button>
                {/* Listening + timer; when the browser has stopped listening (it does after a long silence), the pill
                    becomes the way to start again. */}
                {listening ? (
                    <span className="flex h-9 items-center gap-2 rounded-full bg-white/10 px-4 text-[15px] font-medium">
                        <span className="size-2.5 animate-pulse rounded-full bg-[#FF5A3C]" />
                        {t('shopping.listening')}
                        <span className="font-mono text-white/50 tabular-nums">{mmss(sec)}</span>
                    </span>
                ) : (
                    <button
                        type="button"
                        onClick={onStart}
                        className="flex h-9 items-center gap-2 rounded-full bg-white/10 px-4 text-[15px] font-medium"
                    >
                        <Mic className="size-4" />
                        {t('shopping.tap_to_talk')}
                    </button>
                )}
                {/* The dictation language (the browser can't detect it). A native select under the EN pill: the
                    phone's own picker, no custom menu. Same per-device setting as Shopping settings. */}
                <label className="relative flex h-10 shrink-0 items-center gap-1 rounded-full bg-white/10 px-3.5 text-[15px] font-semibold uppercase">
                    {lang.slice(0, 2)}
                    <ChevronDown className="size-4 text-white/60" />
                    <select
                        value={lang}
                        onChange={(e) => onLang(e.target.value)}
                        aria-label={t('shopping.voice_language')}
                        className="absolute inset-0 cursor-pointer opacity-0"
                    >
                        {VOICE_LANGS.map((l) => (
                            <option key={l.code} value={l.code}>
                                {l.label}
                            </option>
                        ))}
                    </select>
                </label>
            </div>

            <div className="flex min-h-0 flex-1 flex-col overflow-y-auto px-5 pt-[12vh]">
                <p className="text-sun font-mono text-xs font-semibold tracking-[0.16em] uppercase">{t('shopping.adding_to', { list: listName })}</p>
                <p className="font-display mt-4 text-[34px] leading-[1.1] font-extrabold tracking-tight">
                    {said ? (
                        <>
                            {finalText}
                            <span className="text-white/35">{interim}</span>
                        </>
                    ) : (
                        <span className="text-white/35">{t('shopping.voice_prompt')}</span>
                    )}
                </p>
                {chips.length > 0 && (
                    <div className="mt-6 flex flex-wrap gap-2">
                        {chips.map((c, i) => (
                            <span
                                key={i}
                                className="flex h-10 items-center gap-2 rounded-full border border-white/15 bg-white/5 px-4 text-[15px] font-medium"
                            >
                                <Check className="text-sun size-4" />
                                {c}
                            </span>
                        ))}
                    </div>
                )}
            </div>

            <div aria-hidden className="flex h-36 shrink-0 items-center justify-center gap-[4px] overflow-hidden px-3">
                {BARS.map((h, i) => (
                    <span
                        key={i}
                        className={`w-[4px] shrink-0 rounded-full ${h > 0.25 ? 'bg-sun' : 'bg-sun/50'} ${listening ? 'motion-safe:animate-[voice-bar_1.1s_ease-in-out_infinite]' : ''}`}
                        style={{
                            height: `${Math.round(h * 100)}%`,
                            animationDelay: `${(i * 97) % 1100}ms`,
                            transform: listening ? undefined : 'scaleY(0.15)',
                        }}
                    />
                ))}
            </div>

            <div className="shrink-0 px-5 pb-[calc(1.25rem+env(safe-area-inset-bottom))]">
                <div className="flex items-center justify-around">
                    <button type="button" onClick={onType} className="flex w-20 flex-col items-center gap-2 text-[15px] font-medium text-white/80">
                        <span className="flex size-[52px] items-center justify-center rounded-full bg-white/10">
                            <Keyboard className="size-5" />
                        </span>
                        {t('shopping.type')}
                    </button>
                    {/* Done: a sun disc in a soft halo. Disabled until something was said. */}
                    <button
                        type="button"
                        onClick={onDone}
                        disabled={!said.trim() || parsing}
                        aria-label={t('shopping.done_button')}
                        className="bg-sun/15 flex size-[116px] items-center justify-center rounded-full disabled:opacity-60"
                    >
                        <span className="bg-sun/25 flex size-[100px] items-center justify-center rounded-full">
                            <span className="bg-sun text-ink flex size-[72px] items-center justify-center rounded-full">
                                {parsing ? <Loader2 className="size-7 animate-spin" /> : <Check className="size-8" strokeWidth={2.5} />}
                            </span>
                        </span>
                    </button>
                    <button type="button" onClick={onRedo} className="flex w-20 flex-col items-center gap-2 text-[15px] font-medium text-white/80">
                        <span className="flex size-[52px] items-center justify-center rounded-full bg-white/10">
                            <RotateCcw className="size-5" />
                        </span>
                        {t('shopping.redo')}
                    </button>
                </div>
                <p className="mt-4 text-center text-[15px] text-white/45">{parsing ? t('shopping.sorting') : t('shopping.pause_hint')}</p>
            </div>
        </div>
    );
}
