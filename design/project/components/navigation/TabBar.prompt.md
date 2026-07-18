**TabBar** — the mobile app's floating bottom nav. Translucent blurred pill with a soft shadow; the active tab gets an accent-subtle highlight well.

```jsx
<TabBar
  activeId="home"
  onSelect={setTab}
  items={[
    { id: "home", label: "Home", icon: <HouseIcon /> },
    { id: "calendar", label: "Calendar", icon: <CalendarIcon /> },
    { id: "tasks", label: "Tasks", icon: <ListIcon /> },
  ]}
/>
```
