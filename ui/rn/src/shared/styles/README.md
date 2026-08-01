# Styling pattern: `styled()`

`styled()` is `ui/rn`'s pattern for components whose appearance varies by
**variant** (e.g. `color`, `size`) and **interactive state** (`pressed`,
`disabled`). It co-locates a component with its style map, gives you compile-time
variant exhaustiveness, and replaces the ad-hoc `styles[variant]` +
hand-merged-style-array pattern.

NativeWind/Tailwind have been removed — `styled()` plus `StyleSheet.create` is
the styling standard for this app.

## API

```ts
import { styled } from '@/shared/styles/styled';

const Button = styled(Pressable, {
  base: { borderRadius: 8, paddingHorizontal: 16 },     // always applied
  variants: {
    color: { default: { backgroundColor: 'blue' }, ghost: { backgroundColor: 'transparent' } },
    size:  { sm: { padding: 4 }, lg: { padding: 16 } },
  },
  states: { pressed: { opacity: 0.8 }, disabled: { opacity: 0.5 } },
});
```

The returned component accepts the host's props plus:

- one optional prop per variant axis (`color`, `size`), typed to that axis's
  configured values — passing a value that isn't in the config is a **type
  error**, so every variant a caller can name has a matching style
- `disabled` (forwarded to the host when it accepts it, and applied as a state)
- `style` override, merged last (wins on conflict)

Variant axis names are style selectors: they are **stripped** before the props
are forwarded to the host component (Pressable never sees `color`).

## Pressed state

`states.pressed` is wired through `Pressable`'s function-style prop, so it only
applies when the host is `Pressable` (or a `Pressable`-based component). For
`View`/`Text`, `pressed` has no effect — use `disabled` or a variant instead.

## Tokens, not literals

Styles read theme tokens from `@/shared/theme` (`colors`, `spacing`, `radii`,
`fontSizes`, …). Don't hardcode hex colors or pixel sizes in a style config —
import the token. `StyleSheet.create` still backs the individual leaf styles;
`styled()` composes them.

## Worked example

`src/shared/components/ui/button.tsx` is the reference implementation: a
`ButtonRoot = styled(Pressable, …)` carrying the `variant`/`size` axes and the
`pressed`/`disabled` states, and a `ButtonLabel = styled(Text, …)` carrying the
per-variant text color. `badge.tsx` (variant only) and `input.tsx` (a `state`
variant for error) follow the same shape.

## When to use `styled()` vs. plain `StyleSheet.create`

Use `styled()` when the component has **static variant axes** or relies on the
**pressed/disabled** interactive states — i.e. when you currently reach for
`styles[variant]` or `` styles[`${variant}Text`] `` lookups. The factory turns
those fragile string keys into typed props.

Keep plain `StyleSheet.create` when:

- the component is a **composite** with several independently-styled children
  (e.g. `Switch`'s track + thumb) — style each child directly rather than
  forcing one factory over the whole composite;
- the varying style is **per-item dynamic** (e.g. `Tabs`' active tab, a
  `ContextMenu` item's `destructive` flag) — these are list-item conditionals,
  not static variant axes;
- the "state" is a **controlled application value**, not an interaction (e.g.
  `Toggle`'s `pressed` prop is on/off state, not a Pressable gesture) — apply it
  as a conditional style, since `states.pressed` means something different.

The test of a good fit: could the varying styles be a fixed, named set known at
definition time? If yes, use `styled()`. If the set is open or computed per item,
plain `StyleSheet.create` is clearer.
