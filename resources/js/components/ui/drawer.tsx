import { cn } from '@/lib/utils';
import * as React from 'react';
import { Drawer as DrawerPrimitive } from 'vaul';

// Native-feeling bottom drawer (vaul). Handles iOS keyboard repositioning via the Visual Viewport
// API, momentum, and drag-to-dismiss — the things a hand-rolled fixed sheet gets wrong.
function Drawer(props: React.ComponentProps<typeof DrawerPrimitive.Root>) {
    return <DrawerPrimitive.Root {...props} />;
}

const DrawerTrigger = DrawerPrimitive.Trigger;
const DrawerClose = DrawerPrimitive.Close;

function DrawerOverlay({ className, ...props }: React.ComponentProps<typeof DrawerPrimitive.Overlay>) {
    return <DrawerPrimitive.Overlay className={cn('fixed inset-0 z-50 bg-black/40', className)} {...props} />;
}

function DrawerContent({ className, children, ...props }: React.ComponentProps<typeof DrawerPrimitive.Content>) {
    return (
        <DrawerPrimitive.Portal>
            <DrawerOverlay />
            <DrawerPrimitive.Content
                className={cn(
                    'bg-background border-border fixed inset-x-0 bottom-0 z-50 flex max-h-[94dvh] flex-col rounded-t-2xl border',
                    className,
                )}
                {...props}
            >
                <div className="bg-border mx-auto mt-3 mb-1 h-1.5 w-10 shrink-0 rounded-full" aria-hidden />
                {children}
            </DrawerPrimitive.Content>
        </DrawerPrimitive.Portal>
    );
}

function DrawerTitle({ className, ...props }: React.ComponentProps<typeof DrawerPrimitive.Title>) {
    return <DrawerPrimitive.Title className={cn('font-heading text-primary text-xl', className)} {...props} />;
}

function DrawerDescription({ className, ...props }: React.ComponentProps<typeof DrawerPrimitive.Description>) {
    return <DrawerPrimitive.Description className={cn('text-muted-foreground text-sm', className)} {...props} />;
}

export { Drawer, DrawerClose, DrawerContent, DrawerDescription, DrawerOverlay, DrawerTitle, DrawerTrigger };
