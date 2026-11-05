import { Check, ChevronDown, Keyboard, Loader2, Mic, RotateCcw, X } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

import { useT } from '@/lib/i18n';
import { VOICE_LANGS } from '@/lib/shopping';

// The design's waveform (S3b): a bell of bars, tallest in the middle. These are each bar's full height; useVoiceLevel
// scales them by the real mic level. Where the level can't be read (mic stream refused, the audio context won't run),
// each bar breathes on the voice-bar keyframes (app.css) instead, so the screen still looks alive.
const BARS = Array.from({ length: 46 }, (_, i) => {
    const x = (i - 22.5) / 11; // -2..2 across the row
    return Math.max(0.08, Math.exp(-x * x) * (0.75 + 0.25 * Math.sin(i * 2.3)));
});

// Live mic level for the waveform: a second, read-only mic stream into an AnalyserNode, next to SpeechRecognition (which
// doesn't expose its own audio). Each frame writes the bars' scaleY straight to the DOM (refs, no React state: 60fps of
// re-renders would be wasted work). Bars mirror from the centre: the middle ones follow the lowest voice frequencies.
// Returns whether the real level is on (false = use the CSS breathing).
// Not on iPhone/iPad: there the mic goes to one consumer, and with this stream open speech recognition got no audio at
// all (seen on a real iPhone, 2026-11-04: the bars moved, no words ever came). iPhone now records instead (list.tsx)
// and passes its loudness in as `level`.
// iPadOS reports itself as a Mac, so a Mac with touch counts too.
export const IOS =
    typeof navigator !== 'undefined' &&
    (/iP(hone|ad|od)/.test(navigator.userAgent) || (/Macintosh/.test(navigator.userAgent) && navigator.maxTouchPoints > 1));
// `level`: the loudness from the page's own recorder (iPhone, see list.tsx), so no second mic stream is opened here.
function useVoiceLevel(listening: boolean, bars: React.RefObject<(HTMLSpanElement | null)[]>, level?: React.RefObject<number>) {
    const [live, setLive] = useState(false);
    useEffect(() => {
        if (!listening || !level || matchMedia('(prefers-reduced-motion: reduce)').matches) return;
        let raf = 0;
        setLive(true);
        const frame = () => {
            // One loudness for all bars, shaped by each bar's own height (BARS): the bell grows and shrinks with the voice.
            const v = Math.max(0.08, Math.min(1, level.current * 1.6));
            bars.current.forEach((el) => {
                if (el) el.style.transform = `scaleY(${v})`;
            });
            raf = requestAnimationFrame(frame);
        };
        frame();
        return () => {
            cancelAnimationFrame(raf);
            setLive(false);
        };
    }, [listening, bars, level]);
    useEffect(() => {
        if (!listening || level || IOS || !navigator.mediaDevices?.getUserMedia || matchMedia('(prefers-reduced-motion: reduce)').matches) return;
        let stream: MediaStream | undefined;
        let ctx: AudioContext | undefined;
        let raf = 0;
        let gone = false;
        navigator.mediaDevices
            .getUserMedia({ audio: { echoCancellation: true, noiseSuppression: true } })
            .then(async (s) => {
                stream = s;
                if (gone) return s.getTracks().forEach((t) => t.stop());
                ctx = new AudioContext();
                await ctx.resume().catch(() => {});
                if (gone || ctx.state !== 'running') return; // iOS without a gesture: keep the CSS breathing
                const analyser = ctx.createAnalyser();
                analyser.fftSize = 256; // 128 bins of ~187Hz: speech sits in the first ~20
                analyser.smoothingTimeConstant = 0.6;
                ctx.createMediaStreamSource(s).connect(analyser);
                const bins = new Uint8Array(analyser.frequencyBinCount);
                const mid = (BARS.length - 1) / 2;
                setLive(true);
                const frame = () => {
                    analyser.getByteFrequencyData(bins);
                    bars.current.forEach((el, i) => {
                        if (!el) return;
                        const v = bins[1 + Math.round(Math.abs(i - mid))] / 255; // bin 0 is DC/rumble
                        el.style.transform = `scaleY(${Math.max(0.08, Math.min(1, v * 1.4))})`;
                    });
                    raf = requestAnimationFrame(frame);
                };
                frame();
            })
            .catch(() => {}); // refused / no mic: the CSS breathing stays
        return () => {
            gone = true;
            cancelAnimationFrame(raf);
            stream?.getTracks().forEach((t) => t.stop());
            ctx?.close().catch(() => {});
            setLive(false);
        };
    }, [listening, bars, level]);
    return live;
}

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
    error,
    level,
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
    // The recognizer's error code, if it stopped with one: said in plain words, the code kept for bug reports.
    error: string | null;
    level?: React.RefObject<number>; // the recorder's loudness, when the page records instead of using Web Speech
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
    const barRefs = useRef<(HTMLSpanElement | null)[]>([]);
    const live = useVoiceLevel(listening, barRefs, level);
    // Without the live level: words arriving = speaking, so the bars breathe for a moment after each recognised word
    // and lie flat in silence (a still screen while you talk read as frozen).
    const [speaking, setSpeaking] = useState(false);
    useEffect(() => {
        if (!said) return;
        setSpeaking(true);
        const id = setTimeout(() => setSpeaking(false), 700);
        return () => clearTimeout(id);
    }, [said]);
    const breathe = listening && !live && speaking;

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
                        <span className="text-white/35">
                            {error
                                ? `${t(error === 'no-speech' ? 'shopping.voice_no_speech' : 'shopping.voice_error')} (${error})`
                                : t('shopping.voice_prompt')}
                        </span>
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
                        ref={(el) => {
                            barRefs.current[i] = el;
                        }}
                        // Live level: useVoiceLevel owns `transform`; otherwise the CSS breathing while words arrive (`breathe`).
                        className={`w-[4px] shrink-0 rounded-full ${h > 0.25 ? 'bg-sun' : 'bg-sun/50'} ${breathe ? 'motion-safe:animate-[voice-bar_1.1s_ease-in-out_infinite]' : ''}`}
                        style={{
                            height: `${Math.round(h * 100)}%`,
                            animationDelay: `${(i * 97) % 1100}ms`,
                            transform: live || breathe ? undefined : 'scaleY(0.15)',
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
                    {/* Done: a sun disc in a soft halo. With nothing said it switches to typing (the list page decides). */}
                    <button
                        type="button"
                        onClick={onDone}
                        disabled={parsing}
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
