import { Head, Link } from '@inertiajs/react';
import { ArrowRight, Home, Ticket } from 'lucide-react';

import AuthSplitLayout from '@/layouts/auth/auth-split-layout';

// First screen after registration (design: "Web · Onboarding & Create Family" / Welcome).
export default function Welcome({ firstName }: { firstName: string }) {
    return (
        <AuthSplitLayout
            headline={`Welcome home, ${firstName}.`}
            sub="One shared space for your family's plans, lists, and everyday moments. Let's get it set up."
            image="/images/auth/register.svg"
        >
            <Head title="Get started" />

            {/* Header rendered here (not via layout title) so the eyebrow sits above the title. */}
            <div className="flex flex-col gap-2">
                <p className="text-accent text-xs font-semibold tracking-widest uppercase">Your account is ready</p>
                <h1 className="font-heading text-primary text-[32px] leading-tight">How would you like to begin?</h1>
                <p className="text-muted-foreground text-[15px]">Create a new family to organize, or join one you've been invited to.</p>
            </div>

            <div className="flex flex-col gap-3">
                {/* Create — the working path in this phase. */}
                <Link
                    href={route('onboarding.create')}
                    className="bg-accent-subtle group flex items-center gap-4 rounded-lg p-4 transition-colors hover:brightness-[0.98]"
                >
                    <span className="bg-accent text-text-inverse flex size-11 shrink-0 items-center justify-center rounded-md">
                        <Home className="size-5" />
                    </span>
                    <span className="flex-1">
                        <span className="text-primary block font-semibold">Create a family</span>
                        <span className="text-muted-foreground block text-sm">Start fresh and invite the people you love.</span>
                    </span>
                    <ArrowRight className="text-accent size-5" />
                </Link>

                {/* Join — needs the invite/code system (Phase 2). Shown but not yet active. */}
                <div className="bg-surface-card flex items-center gap-4 rounded-lg border border-transparent p-4 opacity-60">
                    <span className="bg-surface text-accent flex size-11 shrink-0 items-center justify-center rounded-md">
                        <Ticket className="size-5" />
                    </span>
                    <span className="flex-1">
                        <span className="text-primary block font-semibold">Join with an invite code</span>
                        <span className="text-muted-foreground block text-sm">Received an invite? Enter your code to hop in.</span>
                    </span>
                    <span className="text-muted-foreground text-xs">Soon</span>
                </div>
            </div>
        </AuthSplitLayout>
    );
}
