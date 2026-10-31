import { forwardRef, useEffect, type CSSProperties, type DetailedHTMLProps, type HTMLAttributes } from 'react';

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

type HomerElement = HTMLElement & { react: (r: HomerReaction) => void; play: (a: HomerAction) => void; readInk: () => void };

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
            className={cn('pointer-events-none block shrink-0', className)}
            style={onDark ? ON_DARK : undefined}
        />
    );
});
