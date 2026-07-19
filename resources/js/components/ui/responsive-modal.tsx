import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Sheet, SheetContent, SheetDescription, SheetHeader, SheetTitle } from '@/components/ui/sheet';
import { useIsMobile } from '@/hooks/use-mobile';
import { cn } from '@/lib/utils';
import * as React from 'react';

// One overlay, two shapes: a bottom drawer on mobile, a centered dialog on desktop. Same title /
// description / body API either way, so callers don't branch on viewport. Tall content scrolls.
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
            <Sheet open={open} onOpenChange={onOpenChange}>
                <SheetContent
                    side="bottom"
                    className={cn('max-h-[92vh] overflow-y-auto rounded-t-2xl px-5 pt-5 pb-[calc(2rem+env(safe-area-inset-bottom))]', className)}
                >
                    <SheetHeader className="px-0 text-left">
                        <SheetTitle className="font-heading text-primary text-xl">{title}</SheetTitle>
                        {description && <SheetDescription className="text-muted-foreground text-sm">{description}</SheetDescription>}
                    </SheetHeader>
                    <div className="mt-4">{children}</div>
                </SheetContent>
            </Sheet>
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
