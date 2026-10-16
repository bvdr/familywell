import { usePage } from '@inertiajs/react';

import InputError from '@/components/input-error';
import { useT } from '@/lib/i18n';

export function GoogleLogo({ className }: { className?: string }) {
    return (
        <svg viewBox="0 0 48 48" aria-hidden="true" className={className}>
            <path
                fill="#EA4335"
                d="M24 9.5c3.54 0 6.71 1.22 9.21 3.6l6.85-6.85C35.9 2.38 30.47 0 24 0 14.62 0 6.51 5.38 2.56 13.22l7.98 6.19C12.43 13.72 17.74 9.5 24 9.5z"
            />
            <path
                fill="#4285F4"
                d="M46.98 24.55c0-1.57-.15-3.09-.38-4.55H24v9.02h12.94c-.58 2.96-2.26 5.48-4.78 7.18l7.73 6c4.51-4.18 7.09-10.36 7.09-17.65z"
            />
            <path
                fill="#FBBC05"
                d="M10.53 28.59c-.48-1.45-.76-2.99-.76-4.59s.27-3.14.76-4.59l-7.98-6.19C.92 16.46 0 20.12 0 24c0 3.88.92 7.54 2.56 10.78l7.97-6.19z"
            />
            <path
                fill="#34A853"
                d="M24 48c6.48 0 11.93-2.13 15.89-5.81l-7.73-6c-2.15 1.45-4.92 2.3-8.16 2.3-6.26 0-11.57-4.22-13.47-9.91l-7.98 6.19C6.51 42.62 14.62 48 24 48z"
            />
        </svg>
    );
}

export function AppleLogo({ className }: { className?: string }) {
    return (
        <svg viewBox="0 0 24 24" aria-hidden="true" fill="currentColor" className={className}>
            <path d="M16.365 1.43c0 1.14-.493 2.27-1.177 3.08-.744.9-1.99 1.57-2.987 1.57-.12 0-.23-.02-.3-.03-.01-.06-.04-.22-.04-.39 0-1.15.572-2.27 1.206-2.98.804-.94 2.142-1.64 3.248-1.68.03.13.05.28.05.43zm4.565 15.71c-.03.07-.463 1.58-1.518 3.12-.945 1.34-1.94 2.71-3.43 2.71-1.517 0-1.9-.88-3.63-.88-1.698 0-2.302.91-3.67.91-1.377 0-2.332-1.26-3.428-2.8-1.287-1.82-2.323-4.63-2.323-7.28 0-4.28 2.797-6.55 5.552-6.55 1.448 0 2.675.95 3.6.95.865 0 2.222-1.01 3.902-1.01.613 0 2.886.06 4.374 2.19-.13.09-2.383 1.37-2.383 4.19 0 3.26 2.854 4.42 2.955 4.45z" />
        </svg>
    );
}

// "Continue with Google / Apple" for the login and register pages. Only the providers the server has keys
// for are shared (socialProviders), so a self-hosted install without them renders nothing at all. Plain
// <a> links, not Inertia visits: the server answers with a redirect to Google/Apple, a full page load.
// Also shows the error a failed sign-in comes back with (errors.social).
// `signup` (the register page): the click is the signup_started funnel step, sent by Umami's own
// data-umami-event click tracking, which holds the navigation until the event is out.
export default function SocialButtons({ signup = false }: { signup?: boolean }) {
    const t = useT();
    const { socialProviders = [], errors } = usePage<{ socialProviders?: string[]; errors: Record<string, string> }>().props;

    if (socialProviders.length === 0) return null;

    // Fixed brand colours on purpose (not theme tokens): Google's and Apple's sign-in guidelines dictate them.
    // Apple's HIG: black button, white Apple logo, "Continue with Apple"; the light outline keeps it visible
    // on a dark background.
    const tracked = (method: string) => (signup ? { 'data-umami-event': 'signup_started', 'data-umami-event-method': method } : {});
    const base = 'flex h-11 w-full items-center justify-center gap-2 rounded-md px-4 text-[15px] font-medium transition-colors';

    return (
        <div className="grid gap-3">
            {socialProviders.includes('google') && (
                <a
                    href="/auth/google/redirect"
                    {...tracked('google')}
                    className={`${base} border border-[#747775] bg-white text-[#1f1f1f] hover:bg-[#f2f2f2]`}
                >
                    <GoogleLogo className="size-[18px]" />
                    {t('auth.continue_with_google')}
                </a>
            )}
            {socialProviders.includes('apple') && (
                <a
                    href="/auth/apple/redirect"
                    {...tracked('apple')}
                    className={`${base} bg-black text-white hover:bg-black/85 dark:border dark:border-white/40`}
                >
                    <AppleLogo className="size-[18px]" />
                    {t('auth.continue_with_apple')}
                </a>
            )}
            <InputError message={errors?.social} className="text-center" />
            <div className="text-muted-foreground flex items-center gap-3 text-xs uppercase">
                <span className="bg-border h-px flex-1" />
                {t('auth.or_with_email')}
                <span className="bg-border h-px flex-1" />
            </div>
        </div>
    );
}
