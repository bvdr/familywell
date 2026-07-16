import { Link, usePage } from '@inertiajs/react';
import { Leaf } from 'lucide-react';

interface AuthLayoutProps {
    children: React.ReactNode;
    title?: string;
    description?: string;
}

// Brand-panel content per auth screen (design: "Web · Auth Flow"). Keyed by route name so the pages
// stay focused on their forms; anything not listed (reset-password, verify-email, confirm-password)
// falls back to the calm login panel, keeping the whole auth surface consistent.
// The images are self-authored branded SVG placeholders (public/images/auth/*.svg). Drop in final
// PNGs at the same path and update these three lines to swap them.
type PanelContent = { headline: string; sub: string; image: string };

const PANELS: Record<string, PanelContent> = {
    login: {
        headline: 'Where your family finds its rhythm.',
        sub: 'Calm, shared organization for the people who matter most.',
        image: '/images/auth/login.svg',
    },
    register: {
        headline: 'Start something calm together.',
        sub: 'Create a shared home for schedules, lists, and the little moments in between.',
        image: '/images/auth/register.svg',
    },
    'password.request': {
        headline: "We'll help you back in.",
        sub: 'Resetting your password only takes a moment.',
        image: '/images/auth/reset.svg',
    },
};

export default function AuthSplitLayout({ children, title, description }: AuthLayoutProps) {
    const { name } = usePage().props as { name?: string };
    // ziggy's current() gives the active route name; fall back to the login panel for undesigned screens.
    const routeName = typeof route().current === 'function' ? route().current() : undefined;
    const panel = (routeName && PANELS[routeName]) || PANELS.login;

    return (
        <div className="grid min-h-dvh lg:grid-cols-2">
            {/* Brand panel — hidden below lg, matching the design's mobile auth frames (form only). */}
            <div className="bg-surface-dark relative hidden flex-col justify-between p-14 lg:flex">
                <Link href={route('home')} className="text-text-inverse flex items-center gap-2.5">
                    <Leaf className="text-accent-light size-6" />
                    <span className="font-heading text-2xl">{name ?? 'FamilyWell'}</span>
                </Link>

                {/* Grows to fill the space between wordmark and tagline (min 340px), so the illustration
                    reads large on tall viewports. Solid forest sits behind it; on a missing file we hide
                    the broken <img> so the panel stays clean rather than showing a broken-image glyph. */}
                <div className="my-8 min-h-[340px] w-full flex-1 overflow-hidden rounded-lg bg-black/10">
                    <img
                        src={panel.image}
                        alt=""
                        className="h-full w-full object-cover"
                        loading="eager"
                        onError={(e) => {
                            e.currentTarget.style.display = 'none';
                        }}
                    />
                </div>

                <div className="flex max-w-md flex-col gap-3">
                    <h2 className="text-text-inverse font-heading text-[34px] leading-tight">{panel.headline}</h2>
                    <p className="text-accent-light text-[15px] leading-relaxed">{panel.sub}</p>
                </div>
            </div>

            {/* Form side */}
            <div className="bg-background flex flex-col items-center justify-center p-6 sm:p-12">
                <div className="flex w-full max-w-[420px] flex-col gap-6">
                    {/* Wordmark shows on mobile where the brand panel is hidden. */}
                    <Link href={route('home')} className="flex items-center gap-2.5 lg:hidden">
                        <Leaf className="text-accent size-6" />
                        <span className="font-heading text-primary text-2xl">{name ?? 'FamilyWell'}</span>
                    </Link>

                    <div className="flex flex-col gap-2">
                        <h1 className="font-heading text-primary text-[32px] leading-tight">{title}</h1>
                        {description && <p className="text-muted-foreground text-[15px]">{description}</p>}
                    </div>

                    {children}
                </div>
            </div>
        </div>
    );
}
