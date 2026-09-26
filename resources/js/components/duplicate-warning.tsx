import { TriangleAlert } from 'lucide-react';
import { type ReactNode } from 'react';

// Soft "looks like a duplicate" notice shown inside an add drawer's footer. Never blocks: the caller
// passes the actions (Add anyway / bump / cancel) as children, and the server re-checks on the next post.
export function DuplicateWarning({ message, children }: { message: string; children: ReactNode }) {
    return (
        <div
            role="alert"
            className="flex flex-col gap-3 rounded-xl border border-amber-300 bg-amber-50 p-3 text-amber-900 dark:border-amber-700 dark:bg-amber-950/40 dark:text-amber-100"
        >
            <p className="flex items-start gap-2 text-sm">
                <TriangleAlert className="mt-0.5 size-4 shrink-0" /> {message}
            </p>
            <div className="flex flex-wrap gap-2">{children}</div>
        </div>
    );
}
