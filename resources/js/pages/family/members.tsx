import AppLayout from '@/layouts/app-layout';
import { Head } from '@inertiajs/react';

type Member = { id: number; name: string; email: string; role: string };
type Props = { family: { id: number; name: string }; members: Member[]; canManage: boolean };

export default function Members({ family, members, canManage }: Props) {
    return (
        <AppLayout>
            <Head title={`${family.name} Members`} />
            <div className="p-6">
                <h1 className="text-xl font-semibold mb-4">{family.name}</h1>
                <ul className="divide-y">
                    {members.map((m) => (
                        <li key={m.id} className="flex justify-between py-2">
                            <span>
                                {m.name} <span className="text-muted-foreground">({m.email})</span>
                            </span>
                            <span className="text-sm uppercase">{m.role}</span>
                        </li>
                    ))}
                </ul>
                {canManage && <p className="mt-4 text-sm text-muted-foreground">You can manage members.</p>}
            </div>
        </AppLayout>
    );
}
