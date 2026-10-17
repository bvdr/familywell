import { Fragment } from 'react';

import { useT } from '@/lib/i18n';

// "By continuing, you agree to our Terms and Privacy Policy" under the sign-up and login forms (Google /
// Apple on the login page create an account too). The pages live on the public site, familywell.org. The
// sentence keeps :terms / :privacy placeholders so each language can put the links where its grammar needs.
const LINKS = { terms: 'https://familywell.org/terms/', privacy: 'https://familywell.org/privacy/' } as const;

export default function LegalNote() {
    const t = useT();
    return (
        <p className="text-muted-foreground text-center text-xs">
            {t('auth.legal_agree')
                .split(/(:terms|:privacy)/)
                .map((part, i) => {
                    const key = part === ':terms' ? 'terms' : part === ':privacy' ? 'privacy' : null;
                    return key ? (
                        <a
                            key={i}
                            href={LINKS[key]}
                            target="_blank"
                            rel="noopener"
                            className="text-foreground underline decoration-neutral-300 underline-offset-4 hover:decoration-current dark:decoration-neutral-500"
                        >
                            {t(`auth.legal_${key}`)}
                        </a>
                    ) : (
                        <Fragment key={i}>{part}</Fragment>
                    );
                })}
        </p>
    );
}
