// True when a module index was opened via the mobile "+" quick-add (`…?new=1`), so it should open
// its create sheet on arrival. Used as a lazy useState initializer on each index page.
export function openNewOnArrival(): boolean {
    if (typeof window === 'undefined') return false;
    return new URLSearchParams(window.location.search).get('new') === '1';
}
