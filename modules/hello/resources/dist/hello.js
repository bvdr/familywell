// Pre-built module frontend: uses the shell runtime, no bundler, no second React.
const { React, registerSlot } = window.Familywell;

function HelloWidget() {
    return React.createElement(
        'div',
        { className: 'rounded-lg border p-4', 'data-testid': 'hello-widget' },
        React.createElement('h3', { className: 'font-semibold' }, 'Hello module'),
        React.createElement(
            'p',
            { className: 'text-sm text-muted-foreground' },
            'This widget was loaded at runtime from the hello module.',
        ),
    );
}

registerSlot('dashboard.widgets', HelloWidget);
