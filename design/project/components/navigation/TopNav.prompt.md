**TopNav** — the web app's top navigation (the source `TopNav`). Wordmark in Playfair, text nav links, a family switcher pill and a user avatar trigger on the right.

```jsx
<TopNav
  activeLink="core"
  links={[{id:"core",label:"Core"},{id:"todos",label:"Todos"},{id:"shopping",label:"Shopping"}]}
  familyName="The Rivera Family"
  user={{ initials: "MR" }}
  onNavigate={goTo}
/>
```

Pass `mark` for a brand icon node, and `right` for header actions (e.g. an Invite/Quick-add Button) placed before the switcher.
