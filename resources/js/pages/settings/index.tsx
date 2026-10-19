import { Head } from '@inertiajs/react';

import Heading from '@/components/heading';
import AppLayout from '@/layouts/app-layout';
import { SettingsMenu } from '@/layouts/settings/layout';
import { useT } from '@/lib/i18n';

// Settings home: one tappable row per section (Slack-style drill-down). Each section page links back here.
export default function SettingsIndex() {
    const t = useT();
    return (
        <AppLayout breadcrumbs={[{ title: t('settings.title'), href: '/settings' }]}>
            <Head title={t('settings.title')} />
            <div className="max-w-xl px-4 py-6">
                <Heading title={t('settings.title')} description={t('settings.manage')} />
                <SettingsMenu />
            </div>
        </AppLayout>
    );
}
