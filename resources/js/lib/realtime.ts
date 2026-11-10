import type Echo from 'laravel-echo';

// The realtime client (laravel-echo + pusher-js) is not needed to paint the first screen, so it lives in its own
// chunk: app.tsx starts loading it once the app is idle, and a subscriber that mounts sooner just waits here.
// Realtime off on this host (window.__familywellReverb is null) = the chunk is never downloaded at all.
let ready: Promise<Echo<'reverb'> | undefined> | undefined;

export function loadEcho(): Promise<Echo<'reverb'> | undefined> {
    return (ready ??= window.__familywellReverb?.key ? import('../echo').then((m) => m.default) : Promise.resolve(undefined));
}
