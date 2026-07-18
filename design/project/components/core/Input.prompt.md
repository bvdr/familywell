**Input** — labelled text field (the source `InputField`). Focus shows an accent ring; `error` turns the border and helper red.

```jsx
<Input label="Email" placeholder="you@example.com" helper="We'll never share it." />
<Input label="Email" error helper="Please enter a valid email address." defaultValue="nope" />
```

Props: `label`, `helper`, `error`, `leadingIcon`, plus all native `<input>` attributes (`type`, `value`, `onChange`, `disabled`…).
