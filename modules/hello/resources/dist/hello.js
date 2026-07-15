// Pre-built module frontend: uses the shell runtime, no bundler, no second React.
const { React, registerSlot } = window.Familywell;

function HelloWidget() {
    const [pings, setPings] = React.useState(0);

    React.useEffect(() => {
        const fid = window.__familywellFamilyId;
        if (window.Echo && fid) {
            window.Echo.private('family.' + fid).listen('.FamilyPinged', () => setPings((p) => p + 1));
            return () => window.Echo.leave('family.' + fid);
        }
    }, []);

    return React.createElement(
        'div',
        { className: 'rounded-lg border p-4', 'data-testid': 'hello-widget' },
        React.createElement('h3', { className: 'font-semibold' }, 'Hello module'),
        React.createElement(
            'p',
            { className: 'text-sm text-muted-foreground', 'data-testid': 'ping-count' },
            'pings: ' + pings,
        ),
    );
}

registerSlot('dashboard.widgets', HelloWidget);
