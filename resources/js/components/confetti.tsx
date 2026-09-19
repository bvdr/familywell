import { useEffect } from 'react';

// A single full-screen confetti fall, drawn on a fixed canvas that removes itself when the last piece
// has left the viewport (~4s). Hand-rolled (no dependency): rectangles with a per-piece tilt that flips
// as it falls, gentle sideways drift, festive colours that read on every skin. Skipped entirely when the
// viewer prefers reduced motion. `onceKey` (e.g. the date + who's celebrating) makes it fire once per
// device per day, so reopening the home screen doesn't rain every time.
const COLORS = ['#F2B138', '#E4572E', '#3C9D8C', '#7A5AA6', '#F28FB1', '#4F8DE0'];

export function Confetti({ onceKey }: { onceKey: string }) {
    useEffect(() => {
        if (typeof window === 'undefined' || window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;
        const storageKey = `confetti:${onceKey}`;
        try {
            if (localStorage.getItem(storageKey)) return;
            localStorage.setItem(storageKey, '1');
        } catch {
            // private mode etc.: just play it
        }

        const canvas = document.createElement('canvas');
        canvas.setAttribute('aria-hidden', 'true');
        Object.assign(canvas.style, { position: 'fixed', inset: '0', width: '100%', height: '100%', pointerEvents: 'none', zIndex: '60' });
        document.body.appendChild(canvas);
        const ctx = canvas.getContext('2d');
        if (!ctx) return;

        const dpr = Math.min(window.devicePixelRatio || 1, 2);
        const resize = () => {
            canvas.width = window.innerWidth * dpr;
            canvas.height = window.innerHeight * dpr;
            ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
        };
        resize();
        window.addEventListener('resize', resize);

        const W = () => window.innerWidth;
        const H = () => window.innerHeight;
        const pieces = Array.from({ length: Math.min(160, Math.round(W() / 6)) }, () => ({
            x: Math.random() * W(),
            y: -20 - Math.random() * H() * 0.6, // staggered start above the viewport
            w: 6 + Math.random() * 6,
            h: 8 + Math.random() * 8,
            vy: 90 + Math.random() * 110, // px/s
            drift: (Math.random() - 0.5) * 60,
            phase: Math.random() * Math.PI * 2,
            spin: (Math.random() - 0.5) * 6,
            color: COLORS[Math.floor(Math.random() * COLORS.length)],
        }));

        let raf = 0;
        let last = performance.now();
        const tick = (now: number) => {
            const dt = Math.min(0.05, (now - last) / 1000);
            last = now;
            ctx.clearRect(0, 0, W(), H());
            let alive = 0;
            for (const p of pieces) {
                p.y += p.vy * dt;
                p.phase += dt * 2;
                p.x += (p.drift + Math.sin(p.phase) * 40) * dt;
                if (p.y > H() + 20) continue;
                alive++;
                ctx.save();
                ctx.translate(p.x, p.y);
                ctx.rotate(p.phase * p.spin);
                ctx.scale(Math.cos(p.phase * 3), 1); // the "flip" as it tumbles
                ctx.fillStyle = p.color;
                ctx.fillRect(-p.w / 2, -p.h / 2, p.w, p.h);
                ctx.restore();
            }
            if (alive > 0) raf = requestAnimationFrame(tick);
            else canvas.remove();
        };
        raf = requestAnimationFrame(tick);

        return () => {
            cancelAnimationFrame(raf);
            window.removeEventListener('resize', resize);
            canvas.remove();
        };
    }, [onceKey]);

    return null;
}
