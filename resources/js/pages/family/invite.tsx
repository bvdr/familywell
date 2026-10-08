import { Head, router, useForm } from '@inertiajs/react';

import { Button } from '@/components/ui/button';
import AuthSplitLayout from '@/layouts/auth/auth-split-layout';

type Invite = {
    token: string;
    family: { name: string; emoji: string; color: string };
    inviter: string;
    memberCount: number;
};

export default function Invite({ invite }: { invite: Invite }) {
    const form = useForm({});
    const accept = () => form.post(route('invite.accept', invite.token));

    return (
        <AuthSplitLayout
            headline="You're invited."
            sub={`${invite.inviter} has a spot saved for you. Accept to join the family and start sharing straight away.`}
            image="/images/auth/register.webp"
        >
            <Head title={`Join ${invite.family.name}`} />

            <div className="flex flex-col items-center gap-2 text-center">
                <span className="flex size-16 items-center justify-center rounded-2xl text-3xl" style={{ backgroundColor: invite.family.color }}>
                    {invite.family.emoji}
                </span>
                <p className="text-muted-foreground text-sm">{invite.inviter} has invited you to join</p>
                <h1 className="font-heading text-primary text-3xl">{invite.family.name}</h1>
                <p className="text-muted-foreground text-sm">
                    {invite.memberCount} {invite.memberCount === 1 ? 'member' : 'members'}
                </p>
            </div>

            <div className="flex flex-col gap-3">
                <Button className="w-full" onClick={accept} disabled={form.processing}>
                    Accept invite
                </Button>
                <Button variant="outline" className="w-full" onClick={() => router.visit('/')}>
                    Not now
                </Button>
            </div>
        </AuthSplitLayout>
    );
}
