import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Drawer, DrawerContent, DrawerDescription, DrawerTitle } from '@/components/ui/drawer';
import { useIsMobile } from '@/hooks/use-mobile';
import { cn } from '@/lib/utils';
import * as React from 'react';

// One overlay, two shapes: a native-feeling bottom drawer on mobile (vaul — handles the iOS keyboard,
// momentum, drag-to-dismiss), a centered dialog on desktop. Same title / description / body API.
export function ResponsiveModal({
    open,
    onOpenChange,
    title,
    description,
    children,
    className,
}: {
    open: boolean;
    onOpenChange: (v: boolean) => void;
    title: string;
    description?: string;
    children: React.ReactNode;
    className?: string; // extra classes for the content (e.g. a wider desktop max-width)
}) {
    const isMobile = useIsMobile();

    if (isMobile) {
        return (
            <Drawer open={open} onOpenChange={onOpenChange} repositionInputs>
                <DrawerContent className={className}>
                    {/* The scroll lives on this inner region so vaul can tell drag from scroll, and so
                        the content clears the keyboard (vaul repositions the drawer above it). */}
                    <div className="flex-1 overflow-y-auto overscroll-contain px-5 pt-1 pb-[calc(1.5rem+env(safe-area-inset-bottom))]">
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
            <DialogContent className={cn('max-h-[90vh] overflow-y-auto sm:max-w-md', className)}>
                <DialogHeader>
                    <DialogTitle className="font-heading text-primary text-xl">{title}</DialogTitle>
                    {description && <DialogDescription className="text-muted-foreground text-sm">{description}</DialogDescription>}
                </DialogHeader>
                <div className="mt-1">{children}</div>
            </DialogContent>
        </Dialog>
    );
}
