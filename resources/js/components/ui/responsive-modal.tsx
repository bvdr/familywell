import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Drawer, DrawerContent, DrawerDescription, DrawerTitle } from '@/components/ui/drawer';
import { useIsMobile } from '@/hooks/use-mobile';
import { cn } from '@/lib/utils';
import * as React from 'react';

// One overlay, two shapes: a native-feeling bottom drawer on mobile (vaul — drag-to-dismiss, momentum),
// a centered dialog on desktop. Same title / description / body API.
//
// Keyboard handling is delegated to the platform: interactive-widget=resizes-content (see the viewport
// meta) shrinks the layout viewport when the keyboard opens, so the bottom-anchored drawer and its
// max-h-[94dvh] cap already sit flush above it. That means repositionInputs must stay off — otherwise
// vaul would lift the drawer a second time and leave a gap.
export function ResponsiveModal({
    open,
    onOpenChange,
    title,
    description,
    children,
    className,
    autoFocusRef,
}: {
    open: boolean;
    onOpenChange: (v: boolean) => void;
    title: string;
    description?: string;
    children: React.ReactNode;
    className?: string; // extra classes for the content (e.g. a wider desktop max-width)
    autoFocusRef?: React.RefObject<HTMLElement | null>; // field to focus on open (else the container gets focus)
}) {
    const isMobile = useIsMobile();

    // Radix/vaul focus the content container by default, so no field is focused and iOS shows no keyboard.
    // Steering focus to a specific field during the open keeps it inside the user gesture, so the keyboard opens.
    const onOpenAutoFocus = autoFocusRef
        ? (e: Event) => {
              e.preventDefault();
              autoFocusRef.current?.focus();
          }
        : undefined;

    if (isMobile) {
        return (
            <Drawer open={open} onOpenChange={onOpenChange} repositionInputs={false}>
                <DrawerContent className={className} onOpenAutoFocus={onOpenAutoFocus}>
                    {/* min-h-0 lets this region actually scroll inside the capped drawer (and lets vaul
                        tell a scroll from a dismiss drag). overflow-x-hidden keeps scrolling vertical-only. */}
                    <div className="flex min-h-0 flex-1 flex-col overflow-x-hidden overflow-y-auto overscroll-contain px-5 pt-1 pb-[calc(1.5rem+env(safe-area-inset-bottom))]">
                        <div className="mb-4 flex flex-col gap-1 text-left">
                            <DrawerTitle>{title}</DrawerTitle>
                            {description && <DrawerDescription>{description}</DrawerDescription>}
                        </div>
                        {children}
                    </div>
                </DrawerContent>
            </Drawer>
        );
    }

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className={cn('max-h-[90vh] overflow-y-auto sm:max-w-md', className)} onOpenAutoFocus={onOpenAutoFocus}>
                <DialogHeader>
                    <DialogTitle className="font-heading text-primary text-xl">{title}</DialogTitle>
                    {description && <DialogDescription className="text-muted-foreground text-sm">{description}</DialogDescription>}
                </DialogHeader>
                <div className="mt-1">{children}</div>
            </DialogContent>
        </Dialog>
    );
}
