import { useCallback, useEffect, useRef, useState } from 'react';

// Voice for "Just say it" (design XMkKm H2c, "talk it out"): record the microphone, and every few seconds send what was
// said so far to /add/transcribe (the reader worker's Whisper), so the words, and through them the cards, fill in while
// the person is still talking. The whole recording is re-sent each time (a recording's later pieces can't be decoded
// on their own), one request at a time; stop() sends it once more and resolves with the final words.
// Chrome records WebM/Opus, Safari (iPhone) MP4/AAC: Whisper reads both (tested 2026-10-28).

const EVERY_MS = 4000; // Whisper takes 1-3.5s for a few seconds of speech: re-reading faster than this just queues
const MAX_MS = 120_000; // two minutes is a long ramble; stop there
const BARS = 32;

export type VoiceError = 'denied' | 'unavailable' | 'out';

const xsrf = () => decodeURIComponent(document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]+)/)?.[1] ?? '');

function pickMime(): string {
    for (const m of ['audio/webm;codecs=opus', 'audio/webm', 'audio/mp4', 'audio/aac']) {
        if (typeof MediaRecorder !== 'undefined' && MediaRecorder.isTypeSupported(m)) return m;
    }
    return '';
}

export function voiceSupported(): boolean {
    return typeof window !== 'undefined' && !!navigator.mediaDevices?.getUserMedia && typeof MediaRecorder !== 'undefined';
}

export function useVoice(onText: (text: string) => void) {
    const [listening, setListening] = useState(false);
    const [seconds, setSeconds] = useState(0);
    const [levels, setLevels] = useState<number[]>(() => Array(BARS).fill(0));
    const [error, setError] = useState<VoiceError | null>(null);
    const [transcribing, setTranscribing] = useState(false);

    const rec = useRef<{ recorder: MediaRecorder; stream: MediaStream; chunks: Blob[]; ctx: AudioContext; timers: number[]; started: number } | null>(
        null,
    );
    const inFlight = useRef(false);
    const onTextRef = useRef(onText);
    onTextRef.current = onText;
    const stopRef = useRef<() => Promise<void>>(async () => {});

    const send = useCallback(async (blob: Blob): Promise<void> => {
        const form = new FormData();
        form.append('audio', blob, blob.type.includes('mp4') ? 'voice.m4a' : 'voice.webm');
        form.append('path', window.location.pathname);
        const res = await fetch('/add/transcribe', {
            method: 'POST',
            body: form,
            headers: { Accept: 'application/json', 'X-XSRF-TOKEN': xsrf() },
        }).catch(() => null);
        if (res?.status === 409) return setError('out');
        if (!res?.ok) return setError('unavailable');
        const data = await res.json();
        if (data.text) onTextRef.current(data.text);
    }, []);

    const teardown = useCallback(() => {
        const r = rec.current;
        if (!r) return;
        r.timers.forEach((id) => window.clearInterval(id));
        r.stream.getTracks().forEach((track) => track.stop());
        void r.ctx.close();
        rec.current = null;
        setListening(false);
        setLevels(Array(BARS).fill(0));
    }, []);

    const start = useCallback(async () => {
        if (rec.current) return;
        setError(null);
        let stream: MediaStream;
        try {
            stream = await navigator.mediaDevices.getUserMedia({ audio: true });
        } catch {
            return setError('denied');
        }
        const mime = pickMime();
        const recorder = new MediaRecorder(stream, mime ? { mimeType: mime } : undefined);
        const chunks: Blob[] = [];
        recorder.ondataavailable = (e) => e.data.size && chunks.push(e.data);
        recorder.start(1000); // a piece every second, so the growing recording can be sent at any time

        // Loudness for the waveform: an analyser on the same stream, sampled ten times a second.
        const ctx = new AudioContext();
        const analyser = ctx.createAnalyser();
        analyser.fftSize = 256;
        ctx.createMediaStreamSource(stream).connect(analyser);
        const buf = new Uint8Array(analyser.frequencyBinCount);
        const started = Date.now();

        const timers = [
            window.setInterval(() => {
                // How loud right now (RMS of the waveform, which follows a voice more smoothly than the peak), as 0-1:
                // the bars are the person's own voice, newest on the right.
                analyser.getByteTimeDomainData(buf);
                const rms = Math.sqrt(buf.reduce((sum, v) => sum + ((v - 128) / 128) ** 2, 0) / buf.length);
                setLevels((cur) => [...cur.slice(1), Math.min(1, rms * 6)]);
                setSeconds(Math.floor((Date.now() - started) / 1000));
            }, 50),
            // Two minutes: a proper stop (final words in, microphone released). Timer ids share one pool, so the
            // clearInterval in teardown() clears this timeout too.
            window.setTimeout(() => void stopRef.current(), MAX_MS),
            window.setInterval(async () => {
                if (inFlight.current || !chunks.length) return;
                inFlight.current = true;
                setTranscribing(true);
                await send(new Blob(chunks, { type: recorder.mimeType }));
                inFlight.current = false;
                setTranscribing(false);
            }, EVERY_MS),
        ];
        rec.current = { recorder, stream, chunks, ctx, timers, started };
        setSeconds(0);
        setListening(true);
    }, [send]);

    /** Stop and read the whole recording once more; resolves when the final words are in. */
    const stop = useCallback(async () => {
        const r = rec.current;
        if (!r) return;
        await new Promise<void>((resolve) => {
            r.recorder.onstop = () => resolve();
            if (r.recorder.state !== 'inactive') r.recorder.stop();
            else resolve();
        });
        const blob = new Blob(r.chunks, { type: r.recorder.mimeType });
        teardown();
        if (!blob.size) return;
        setTranscribing(true);
        await send(blob);
        setTranscribing(false);
    }, [send, teardown]);

    stopRef.current = stop;

    /** Stop and throw the recording away (the trash button). */
    const cancel = useCallback(() => {
        const r = rec.current;
        if (r && r.recorder.state !== 'inactive') r.recorder.stop();
        teardown();
    }, [teardown]);

    useEffect(() => () => cancel(), [cancel]); // closing the sheet mid-sentence releases the microphone

    return { listening, seconds, levels, error, transcribing, start, stop, cancel };
}
