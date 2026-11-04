import { forwardRef, useEffect, useState, type CSSProperties, type DetailedHTMLProps, type HTMLAttributes } from 'react';

import type { HomerAction, HomerReaction } from '@/lib/homer';
import { cn } from '@/lib/utils';

// Homer, the FamilyWell mascot: the live <homer-mascot> renderer (lib/homer.js, synced from familywell-site).
// He shows up at the onboarding's moments (hello, waiting, the win, the tour, the plan) and reacts to what
// people do there through reactHomer(). Reduced motion and pausing offscreen are built into the renderer.

export type HomerFace =
    | 'neutral'
    | 'happy'
    | 'surprised'
    | 'wink'
    | 'sleepy'
    | 'focused'
    | 'skeptical'
    | 'thinking'
    | 'confused'
    | 'dizzy'
    | 'asleep'
    | 'glitch'
    | 'listening';

type HomerElement = HTMLElement & {
    // The renderer's own state (lib/homer.js): the camera eases toward yaw / tilt every frame, gaze moves the eyes.
    h?: { yaw: number; tilt: number; gaze: [number, number] };
    draw?: () => void;
    react: (r: HomerReaction) => void;
    play: (a: HomerAction) => void;
    readInk: () => void;
};

declare module 'react' {
    // eslint-disable-next-line @typescript-eslint/no-namespace
    namespace JSX {
        interface IntrinsicElements {
            'homer-mascot': DetailedHTMLProps<HTMLAttributes<HomerElement>, HomerElement> & {
                face?: string;
                view?: string;
                loop?: string;
                follow?: string;
                greet?: string;
                idle?: string;
                'inert-click'?: string;
            };
        }
    }
}

// Loaded on first use, in the browser only: the module registers the custom element at import time, and the
// page also renders through Inertia SSR where there is no customElements.
let loading: Promise<unknown> | null = null;
const load = () => (loading ??= import('@/lib/homer'));

// Tilt: Homer turns as the phone turns, a little 3D parallax (user asked 2026-11-04). One shared
// deviceorientation listener, on only while some Homer is mounted; it sets each renderer's camera target and the
// renderer's own easing smooths it. Lives here, not in lib/homer.js, because that file is a synced copy.
// The angle you hold the phone at drifts into the new "straight" over a few seconds, so only movement shows.
// Android: no prompt, starts by itself. iOS 13+: needs DeviceOrientationEvent.requestPermission() from a tap, so
// tapping Homer asks once (tiltNeedsTap). Off with reduced motion.
type OrientationCtor = typeof DeviceOrientationEvent & { requestPermission?: () => Promise<'granted' | 'denied'> };
const TILT_YAW = 22; // degrees Homer turns at a full lean (25 degrees of phone)
const TILT_PITCH = 10;
let tiltUsers = 0;
let base: [number, number] | null = null;
// iOS answered this page session: no more tapping Homer to ask.
let asked = false;
let views: Record<string, { yaw: number; tilt: number }> | null = null;

function onOrientation(e: DeviceOrientationEvent) {
    if (e.beta == null || e.gamma == null || !views) return;
    // Screen-relative axes: in landscape the phone's beta / gamma swap roles.
    const angle = screen.orientation?.angle ?? 0;
    const [x, y] = angle === 90 ? [e.beta, -e.gamma] : angle === 270 || angle === -90 ? [-e.beta, e.gamma] : [e.gamma, e.beta];
    if (!base) base = [x, y];
    // ponytail: slow exponential drift of the neutral angle (~3 s); a real calibration only if this feels off.
    base = [base[0] + (x - base[0]) * 0.006, base[1] + (y - base[1]) * 0.006];
    const dx = Math.max(-1, Math.min(1, (x - base[0]) / 25));
    const dy = Math.max(-1, Math.min(1, (y - base[1]) / 25));
    document.querySelectorAll<HomerElement>('homer-mascot').forEach((el) => {
        // Scenes have their own fixed camera; leave them be.
        if (!el.h || el.hasAttribute('scene')) return;
        const v = views![el.getAttribute('view') ?? 'three'] ?? views!.three;
        // Signs checked on an iPhone (2026-11-04): the first guess turned him the wrong way, so all three are flipped.
        el.h.yaw = (el.hasAttribute('yaw') ? +el.getAttribute('yaw')! : v.yaw) - dx * TILT_YAW;
        el.h.tilt = (el.hasAttribute('tilt') ? +el.getAttribute('tilt')! : v.tilt) + dy * TILT_PITCH;
        el.h.gaze = [-dx, dy];
        el.draw?.();
    });
}

const tiltOff = () =>
    typeof window === 'undefined' || !('DeviceOrientationEvent' in window) || matchMedia('(prefers-reduced-motion: reduce)').matches;
const tiltNeedsTap = () => !asked && !tiltOff() && typeof (DeviceOrientationEvent as OrientationCtor).requestPermission === 'function';

function useTilt(): (() => void) | undefined {
    const [needsTap, setNeedsTap] = useState(false);
    useEffect(() => {
        if (tiltOff()) return;
        void load().then((m) => (views = (m as { VIEWS: typeof views }).VIEWS));
        if (tiltUsers++ === 0) addEventListener('deviceorientation', onOrientation);
        // On iOS the listener stays silent until permission is granted (in this page session).
        setNeedsTap(tiltNeedsTap());
        return () => {
            if (--tiltUsers === 0) {
                removeEventListener('deviceorientation', onOrientation);
                base = null;
            }
        };
    }, []);
    if (!needsTap) return undefined;
    return () =>
        void (DeviceOrientationEvent as OrientationCtor).requestPermission!()
            .catch(() => 'denied')
            .finally(() => {
                asked = true;
                setNeedsTap(false);
            });
}

/** Fire a reaction on every Homer on the page (README: hello, success, celebrate, error, yes, no, ...). */
export function reactHomer(name: HomerReaction): void {
    if (typeof document !== 'undefined') document.dispatchEvent(new CustomEvent('homer', { detail: name }));
}

/** Play one action (hop, nod, no, wave, look, ...) on every Homer on the page. */
export function playHomer(action: HomerAction): void {
    if (typeof document === 'undefined') return;
    document.querySelectorAll<HomerElement>('homer-mascot').forEach((el) => el.play?.(action));
}

// The renderer reads its outline colours from these; on the dark rail and coach marks Homer needs light ink.
const ON_DARK = { '--homer-line': '#F1EFE7', '--homer-roof': 'rgba(241, 239, 231, .07)', '--homer-shadow': 'rgba(0, 0, 0, .35)' } as CSSProperties;

type Props = {
    face?: HomerFace;
    view?: string;
    loop?: HomerAction;
    /** Eyes follow the cursor. On by default: a Homer that stares past you while you move reads as broken. */
    follow?: boolean;
    /** Waves the first time he scrolls into view. */
    greet?: boolean;
    /** Seconds without input before he gets sleepy. */
    idle?: number;
    onDark?: boolean;
    className?: string;
};

export const Homer = forwardRef<HomerElement, Props>(function Homer(
    { face = 'neutral', view, loop, follow = true, greet, idle, onDark, className },
    ref,
) {
    useEffect(() => {
        void load();
    }, []);
    const askTilt = useTilt();

    return (
        <homer-mascot
            ref={ref}
            face={face}
            view={view}
            loop={loop}
            follow={follow ? '' : undefined}
            greet={greet ? '' : undefined}
            idle={idle ? String(idle) : undefined}
            // Decoration: a click shouldn't make him spin in the middle of a form.
            inert-click=""
            aria-hidden
            // Tappable only while iOS still has to be asked for motion access (useTilt); otherwise he never takes a tap.
            onClick={askTilt}
            className={cn('block shrink-0', askTilt ? 'cursor-pointer' : 'pointer-events-none', className)}
            style={onDark ? ON_DARK : undefined}
        />
    );
});
