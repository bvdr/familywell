import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Drawer, DrawerClose, DrawerContent, DrawerDescription, DrawerTitle } from '@/components/ui/drawer';
import { useIsMobile } from '@/hooks/use-mobile';
import { cn } from '@/lib/utils';
import { ChevronLeft, X } from 'lucide-react';
import * as React from 'react';

// Drag stops like the Google Maps sheet: a peek (drag down to glance at what's behind) and full. Opens full.
const SNAP_POINTS = [0.62, 1];

// Fields that bring up the iOS keyboard when focused.
const EDITABLE = 'input, textarea, select, [contenteditable="true"]';

// Height of the keyboard, i.e. how far a bottom-anchored fixed element must lift to clear it.
// innerHeight is the layout viewport (unchanged by an overlaying keyboard); visualViewport.height is
// the part still visible. Their difference is the keyboard. Shipping iOS WebKit ignores
// interactive-widget=resizes-content, so this lift is what keeps the sheet above the keyboard there;
// where the layout does resize (Android Chrome) the two heights match and the offset is 0.
//
// Only a FOCUSED FIELD counts as a keyboard. After the installed iOS app is reopened, WebKit can keep a
// keyboard-sized visualViewport with no keyboard on screen (2026-10-20 recording: a constant 345px gap on
// a Pro Max). Reading that gap as a keyboard lifted the More / + sheets to mid-screen. focusin/focusout
// re-check it because in that stale state the visualViewport never fires an event to correct us.
function useKeyboardOffset(active: boolean) {
    const [offset, setOffset] = React.useState(0);
    React.useEffect(() => {
        const vv = window.visualViewport;
        if (!active || !vv) {
            setOffset(0);
            return;
        }
        // Keyboard top in layout coordinates = visualViewport top (offsetTop) + its height; the drawer's
        // bottom must clear exactly that. Omitting offsetTop lifts it too high and leaves a gap under it.
        const update = () =>
            setOffset(document.activeElement?.matches(EDITABLE) ? Math.max(0, Math.round(window.innerHeight - vv.height - vv.offsetTop)) : 0);
        vv.addEventListener('resize', update);
        vv.addEventListener('scroll', update);
        document.addEventListener('focusin', update);
        document.addEventListener('focusout', update);
        update();
        return () => {
            vv.removeEventListener('resize', update);
            vv.removeEventListener('scroll', update);
            document.removeEventListener('focusin', update);
            document.removeEventListener('focusout', update);
        };
    }, [active]);
    return offset;
}

// One overlay, two shapes: a native-feeling bottom drawer on mobile (vaul — drag-to-dismiss, momentum),
// a centered dialog on desktop. Same title / description / body / footer API.
//
// Keyboard-safe sheet layout: the title and the footer (e.g. a Save button) are pinned, and only the
// body between them scrolls. So no matter how the keyboard resizes or overlays the viewport, the header
// and the primary action stay put and the fields scroll under them; useKeyboardOffset lifts the whole
// sheet so the pinned footer lands right above the keyboard.
export function ResponsiveModal({
    open,
    onOpenChange,
    title,
    description,
    children,
    footer,
    className,
    autoFocusRef,
    onBack,
}: {
    open: boolean;
    onOpenChange: (v: boolean) => void;
    title: string;
    description?: string;
    children: React.ReactNode;
    footer?: React.ReactNode; // pinned to the bottom (stays above the keyboard); e.g. a Save button
    className?: string; // extra classes for the content (e.g. a wider desktop max-width)
    autoFocusRef?: React.RefObject<HTMLElement | null>; // field to focus on open (else the container gets focus)
    // When set, the header-left shows a "< Back" button instead of the title (title stays for a11y only);
    // the caller renders the visible title in the body. For sub-view sheets where Back sits above the title.
    onBack?: () => void;
}) {
    const isMobile = useIsMobile();
    const keyboardOffset = useKeyboardOffset(isMobile && open);

    // Snap to full whenever it opens, so the form is immediately usable; dragging down peeks at the page behind.
    const [snap, setSnap] = React.useState<number | string | null>(1);
    React.useEffect(() => {
        if (open) setSnap(1);
    }, [open]);

    // Radix/vaul focus the content container by default, so no field is focused and iOS shows no keyboard.
    // Steering focus to a specific field during the open keeps it inside the user gesture, so the keyboard
    // opens. preventScroll stops the browser from yanking that field to the top (which scrolls the header off).
    const onOpenAutoFocus = autoFocusRef
        ? (e: Event) => {
              e.preventDefault();
              autoFocusRef.current?.focus({ preventScroll: true });
          }
        : undefined;

    // Keep the focused field clear of the keyboard. iOS standalone PWA ignores
    // interactive-widget=resizes-content, so the browser won't scroll a focused input out from behind
    // the keyboard, and the lift is short by the input accessory bar (~44px, not in visualViewport).
    // We track the focused field and centre it in the scroll body — the large bottom padding below gives
    // the room to centre even the LAST field (otherwise it can't scroll past the bottom). We re-centre
    // once the keyboard's lift settles (keyboardOffset changes).
    const focusedRef = React.useRef<HTMLElement | null>(null);
    const centreFocused = React.useCallback(() => {
        const el = focusedRef.current;
        if (el) requestAnimationFrame(() => el.scrollIntoView({ block: 'center' }));
    }, []);
    React.useEffect(() => {
        if (keyboardOffset) centreFocused();
    }, [keyboardOffset, centreFocused]);
    const onBodyFocus = (e: React.FocusEvent) => {
        const t = e.target as HTMLElement;
        if (t === autoFocusRef?.current || !t.matches(EDITABLE)) return;
        focusedRef.current = t;
        setTimeout(centreFocused, 300); // after the keyboard animates in
    };

    if (isMobile) {
        return (
            <Drawer
                open={open}
                onOpenChange={onOpenChange}
                repositionInputs={false}
                snapPoints={SNAP_POINTS}
                activeSnapPoint={snap}
                setActiveSnapPoint={setSnap}
            >
                <DrawerContent
                    className={className}
                    onOpenAutoFocus={onOpenAutoFocus}
                    // Lift the whole sheet above the keyboard and cap it to the visible height, so the pinned
                    // footer and fields stay in view. Both are no-ops (0 / 94dvh) when no keyboard is open.
                    style={keyboardOffset ? { bottom: keyboardOffset, maxHeight: `calc(100dvh - ${keyboardOffset}px - 0.5rem)` } : undefined}
                >
                    <div className="mb-3 flex shrink-0 items-start justify-between gap-3 px-5 pt-1">
                        <div className="flex min-w-0 flex-col gap-1 text-left">
                            {onBack ? (
                                <>
                                    <button
                                        type="button"
                                        onClick={onBack}
                                        className="text-primary -ml-1 flex items-center gap-0.5 text-sm font-medium"
                                    >
                                        <ChevronLeft className="size-4" /> Back
                                    </button>
                                    <DrawerTitle className="sr-only">{title}</DrawerTitle>
                                </>
                            ) : (
                                <>
                                    <DrawerTitle>{title}</DrawerTitle>
                                    {description && <DrawerDescription>{description}</DrawerDescription>}
                                </>
                            )}
                        </div>
                        <DrawerClose
                            aria-label="Close"
                            className="text-muted-foreground hover:bg-surface hover:text-foreground -mr-1.5 -mt-0.5 flex size-8 shrink-0 items-center justify-center rounded-full transition-colors"
                        >
                            <X className="size-5" />
                        </DrawerClose>
                    </div>
                    {/* Only this region scrolls. min-h-0 lets it shrink inside the flex column so it actually
                        scrolls (and lets vaul tell a scroll from a dismiss drag); overflow-x-hidden keeps it vertical. */}
                    <div
                        className="min-h-0 flex-1 overflow-x-hidden overflow-y-auto overscroll-contain px-5"
                        // Big bottom padding while the keyboard is open so ANY field (even the last one) has
                        // room to scroll up to centre, clear of the keyboard + its accessory bar. It's empty
                        // scroll space behind the keyboard, so it's invisible; collapses to a hair when closed.
                        style={{ paddingBottom: keyboardOffset ? '55vh' : '0.5rem' }}
                        onFocus={onBodyFocus}
                    >
                        {children}
                    </div>
                    {footer && (
                        <div
                            className={cn(
                                'border-border/60 shrink-0 border-t px-5 pt-2.5',
                                // The safe-area inset clears the home indicator, but the keyboard already covers
                                // that strip. Reserving it while the keyboard is open just leaves dead space under Save.
                                keyboardOffset ? 'pb-3' : 'pb-[calc(0.75rem+env(safe-area-inset-bottom))]',
                            )}
                        >
                            {footer}
                        </div>
                    )}
                </DrawerContent>
            </Drawer>
        );
    }

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className={cn('flex max-h-[90vh] flex-col sm:max-w-md', className)} onOpenAutoFocus={onOpenAutoFocus}>
                <DialogHeader>
                    {onBack ? (
                        <>
                            <button
                                type="button"
                                onClick={onBack}
                                className="text-primary -ml-1 flex items-center gap-0.5 self-start text-sm font-medium"
                            >
                                <ChevronLeft className="size-4" /> Back
                            </button>
                            <DialogTitle className="sr-only">{title}</DialogTitle>
                        </>
                    ) : (
                        <>
                            <DialogTitle className="font-heading text-primary text-xl">{title}</DialogTitle>
                            {description && <DialogDescription className="text-muted-foreground text-sm">{description}</DialogDescription>}
                        </>
                    )}
                </DialogHeader>
                <div className="min-h-0 flex-1 overflow-y-auto">{children}</div>
                {footer && <div className="mt-2 shrink-0">{footer}</div>}
            </DialogContent>
        </Dialog>
    );
}
