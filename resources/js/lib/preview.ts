import { usePage } from '@inertiajs/react';

// Is this person opted in to a module's preview (next version)? The server side is App\Support\Modules\Preview,
// which says how previews work and how they're released; the list comes from the shared `preview` prop.
export const usePreview = (moduleId: string): boolean => ((usePage().props as { preview?: string[] }).preview ?? []).includes(moduleId);
