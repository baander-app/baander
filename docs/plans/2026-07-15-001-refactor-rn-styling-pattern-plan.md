---
title: RN Styling Pattern - Plan
type: refactor
date: 2026-07-15
topic: rn-styling-pattern
execution: code
artifact_contract: ce-unified-plan/v1
artifact_readiness: implementation-ready
product_contract_source: ce-brainstorm
---

# RN Styling Pattern - Plan

## Goal Capsule

- **Objective:** Establish a typed variants + interactive-states styling factory for `ui/rn`'s shared UI primitives, and remove the unused NativeWind/Tailwind toolchain. TV focus and TV platform tokens stay on their existing separate path this round.
- **Product authority:** Martin — RN app owner. The Key Decisions were revised from the brainstorm after a `ce-doc-review` pass: the originally-proposed single unified factory was split into a primitives factory plus a separate TV path, confirmed 2026-07-15.
- **Execution profile:** code, Standard depth, ~4 units, phased (remove NativeWind → build factory → migrate primitives → document).
- **Stop conditions:** factory and migrated primitives pass `yarn test`, `yarn typecheck`, and `yarn lint`; Metro bundles cleanly with NativeWind gone; convention doc written; no hardcoded theme values remain in migrated primitives.
- **Tail ownership:** the implementer owns per-unit ergonomics (exact factory API shape, primitive-by-primitive conversion details the plan leaves open).

---

## Product Contract

*Product Contract preservation:* changed from the brainstorm — Key Decisions and R1/R3/R9/AE2 were revised to split the originally-proposed unified factory into (a) a variants+states factory for shared UI primitives and (b) a separate, untouched TV path. Reason: a `ce-doc-review` pass found the unified factory solved for a component that does not exist in the codebase (shared primitives are mobile-only; TV is a separate component tree; TV focus is a behavioral wrapper). Change confirmed by the product authority 2026-07-15. *Working-tree note:* NativeWind/Tailwind removal was already in progress as uncommitted changes when this plan was finalized (the repo was also upgraded to React Native 0.86 / TypeScript 7 during the session); U1 reflects that — verify and land the removal, not rebuild it.

### Summary

Introduce a typed `styled()` factory in `ui/rn` that co-locates a shared UI component with its base styles, typed variant props, and interactive states (pressed, disabled), reading theme tokens — and retire the unused NativeWind/Tailwind toolchain from `ui/rn`. It replaces the ad-hoc `StyleSheet.create` + `styles[variant]` + manual array-merging pattern for shared primitives, and is documented as the app's styling standard.

### Problem Frame

`ui/rn` ships NativeWind v4 and Tailwind fully wired into its build — babel, metro, tailwind config, env declarations — yet uses them nowhere. `className`, `tw()`, and `styled()` appear zero times in source; the only `nativewind` reference is an ambient type declaration. The toolchain is pure carrying cost.

What the app actually does is hand-rolled `StyleSheet.create` across 61 files, with theme tokens imported from the shared theme module. The pattern works but was never codified, and the cracks are widening. A quarter of those files (25 of 61) bypass the theme entirely and hardcode values. Variant styles rely on string-keyed lookups (`styles[variant]`, `` styles[`${variant}Text`] ``) that silently render an unstyled variant when a value is added. Interactive states (pressed, disabled) are merged by hand into style arrays. The cost is inconsistency and fragile wiring that grows with every new component — not a blocking bug, but steady friction.

### Key Decisions

- **Split: factory for shared primitives, TV separate.** The brainstorm proposed one factory modeling variants + states + per-platform tokens. A review pass showed shared UI primitives are mobile-only, TV is a separate component tree (`Platform.isTV` → `TVNavigator`), and TV focus is a behavioral wrapper (`TVFocusable`) rather than styling. The plan therefore scopes the factory to what shared primitives actually need (variants + pressed/disabled states) and leaves TV on its existing path.
- **cva-style declarative factory.** A `styled(Component, config)` factory where config holds base, typed variant maps, and state styles, adapted to React Native (Pressable's function-style prop for `pressed`). No external dependency; full type control over variant exhaustiveness.
- **Factory reads the hand-maintained shared theme.** The factory consumes the existing `src/shared/theme` tokens. Aligning with the generated `ui/design-tokens` pipeline is deferred (see Outstanding Questions).
- **Migration targets variant/state-bearing primitives.** The factory is adopted on primitives that have variants or interactive states; purely static primitives gain little and are optional.
- **NativeWind removal is config + deps only.** No source migration is needed because NativeWind is unused; removal is mechanical.

### Requirements

**Factory capability**

- R1. The factory lets a shared UI component declare base styles, variant props (e.g. color, size), and interactive states (pressed, disabled) in one co-located style map.
- R2. Variant maps are type-checked so adding a variant without a matching style fails at compile time, replacing today's string-keyed lookups.
- R3. The factory reads theme tokens from the shared theme module so resolved styles stay token-backed rather than hardcoded.
- R4. The factory preserves escape-hatch styling: caller-supplied `style` overrides merge over the resolved styles.

**NativeWind removal**

- R5. NativeWind and Tailwind are fully removed from `ui/rn`: dependencies dropped and babel/tailwind/env config reverted to a standard React Native setup.
- R6. No runtime or build reference to NativeWind or Tailwind remains anywhere in `ui/rn` after removal.

**Adoption and standard**

- R7. The shared UI primitives that carry variants or interactive states are migrated to the factory as the reference implementations of the pattern.
- R8. Hardcoded color, size, and radius values in migrated primitives are replaced with theme tokens.
- R9. TV focus and TV platform tokens are handled by their existing mechanisms (the TV focus wrapper and TV token sets), not by the shared factory, so the factory's scope stays bounded to mobile/desktop primitives.
- R10. The pattern is documented as `ui/rn`'s styling standard so new components have a reference to follow.

### Acceptance Examples

- AE1. **Variant exhaustiveness.** When a component gains a new variant value, the factory's type-check flags the missing style entry at compile time, so no variant renders unstyled at runtime. **Covers R2.**
- AE2. **State resolution.** When a factory-built button is pressed, the resolved style combines its base, variant, size, and pressed-state styles from a single declaration, plus any caller override — replacing the hand-merged style array. **Covers R1, R2, R4.**

### Success Criteria

- `nativewind`, `tailwindcss`, `className`, `tw(`, and NativeWind-style `styled(` return zero hits across `ui/rn`, and build config is standard React Native.
- The factory is the styling path for every migrated shared primitive.
- Zero hardcoded color, size, or radius literals remain in migrated shared UI primitives after migration; the broader count of StyleSheet files bypassing the shared theme (currently 25) trends down as migration proceeds.
- A written convention documents the factory as `ui/rn`'s styling standard.

### Scope Boundaries

**Outside this work's scope**

- `ui/android` — the second RN app, which also carries unused NativeWind — is not touched.
- Runtime/user-toggleable theming (dark/light) — tokens stay static and dark-only.
- Animated styles — the factory produces static style maps; existing `Animated.Value` and reanimated usages are unchanged.
- Adopting an external styling library (unistyles, CSS-in-JS) — rejected in favor of a local factory.
- TV focus and TV platform-token unification — TV stays on its existing `TVFocusable` + `tvColors`/`tvSizes` path; not folded into the factory.
- Hover-state styling and accessibility (a11y) wiring — out this round.

**Deferred for later**

- Hoisting the factory and theme into `@baander/shared` for both RN apps (built extraction-ready now; lift when `ui/android` adopts).
- Aligning `src/shared/theme` with the `ui/design-tokens` build pipeline and repairing `DESIGN.md` drift (see Outstanding Questions).

### Dependencies / Assumptions

- The factory reads the existing hand-maintained `src/shared/theme` tokens as its theme source. The separate `ui/design-tokens` pipeline — which already generates a build theme, currently unused by `ui/rn` and divergent from the hand-maintained tokens — is not adopted this round.
- The factory is built without `ui/rn`-specific coupling so it can be lifted to `@baander/shared` later.

### Outstanding Questions

**Deferred to implementation**

- Token source for the factory: read the hand-maintained `src/shared/theme` as-is (assumed), or wire to the generated `ui/design-tokens` build? This round assumes the hand-maintained tokens; the factory's token-reading layer should be thin enough that a later switch does not force a rewrite of every migrated variant map.
- Test runner is currently broken: existing tests import `@testing-library/react-native` and `@testing-library/react`, neither of which is installed (likely dropped during the Yarn 4 migration). Before U2/U3 verification can pass, decide whether to add those as devDependencies or migrate the affected tests to `react-test-renderer`. This is pre-existing repo debt, not introduced by this plan, but it blocks the `yarn test` gate.

### Sources / Research

- `ui/rn/src/shared/theme/` — `colors.ts`, `tokens.ts`, `typography.ts`, `index.ts`: hand-maintained token source the factory reads.
- `ui/rn/src/shared/components/ui/button.tsx` — canonical example of the variant + state pattern the factory replaces (`variant`/`size` unions, `style={({pressed}) => [...]}`, `styles[variant]`, `` styles[`${variant}Text`] ``).
- `ui/rn/src/shared/components/ui/` — the 14 shared primitives; variant/state-bearing: button, badge, switch, toggle, tabs, input, context-menu; static: separator, skeleton, scroll-area, slider, tooltip.
- `ui/rn/src/app/App.tsx` — `const isTV = Platform.isTV ?? false; if (isTV) return <TVNavigator />;` confirms TV is a separate component tree.
- `ui/rn/src/features/tv/components/TVFocusable.tsx`, `ui/rn/src/features/tv/hooks/use-tv-focus.ts` — the behavioral TV focus wrapper that stays separate.
- `ui/rn/src/features/tv/theme/tv-tokens.ts` — the parallel TV token set (`tvColors`, `tvSizes`, `tvSpacing`, `tvFontSizes`, `tvRadii`), referenced by ~27 TV files; stays untouched.
- NativeWind wiring (already removed in the working tree at plan time): `ui/rn/babel.config.js` (the `nativewind/babel` plugin), `ui/rn/tailwind.config.js`, `ui/rn/nativewind-env.d.ts`, `ui/rn/src/app/metro-env.d.ts` (the `cssInterop` import), and the `nativewind` + `tailwindcss` entries in `ui/rn/package.json`. `ui/rn/metro.config.js` has no NativeWind wiring and needs no change.
- Stack: React Native 0.86 (`react-native-tvos` 0.86.0-2), React 19, TypeScript 7.0.2 (`strict: true`), jest 29, `react-test-renderer` 19, eslint, yarn. `@testing-library/react-native` is imported by existing tests but is not installed.

---

## Planning Contract

### Key Technical Decisions

- **KTD1 — Split the styling approach (authorized revision).** Factory for shared primitives (variants + pressed/disabled states); TV separate. The brainstorm's unified factory rested on a component that does not exist in the codebase; the split bounds the factory to real consumers and dissolves the review's TV/focus/platform findings by scoping them out.
- **KTD2 — cva-style declarative factory, no dependency.** A local `styled(Component, { base, variants, states })` modeled on the `class-variance-authority` pattern, adapted for React Native. Variant axes become typed union props on the returned component; a missing variant style is a compile error. Pressed state is wired through Pressable's function-style prop. Avoids an external styling dependency and keeps variant exhaustiveness fully typed.
- **KTD3 — Factory reads the hand-maintained shared theme.** Token resolution reads `src/shared/theme`; the generated `ui/design-tokens` build theme (drifted, RN-incompatible `rem` units) is not adopted. The token-reading surface stays narrow so a later switch is feasible.
- **KTD4 — Migration scope is primitives with variants or interactive states.** button, badge, switch, toggle, tabs, input, context-menu convert to the factory. Static primitives (separator, skeleton, scroll-area, slider, tooltip) are optional and deferred — they have no variants and gain little.
- **KTD5 — NativeWind removal is config + deps, no source migration.** Because NativeWind is unused in source, removal is mechanical: drop deps, revert the babel preset, delete tailwind/env files, remove the ambient `cssInterop` import. `metro.config.js` needs no change.

### High-Level Technical Design

The factory turns a style config into a typed component whose props are the config's variant axes, and resolves a style array at render.

```text
styled(Component, {
  base:       ViewStyle,
  variants:   { color: { default: {...}, ghost: {...} },
                size:  { sm: {...}, lg: {...} } },
  states:     { pressed: {...}, disabled: {...} },
})
  → returns <Styled color="ghost" size="sm" disabled style={override} />

static resolution (View/Text):
  [base, variants.color[color], variants.size[size], disabled && states.disabled, override]

Pressable resolution:
  style={({ pressed }) => [base, variants.color[color], ..., pressed && states.pressed, disabled && states.disabled, override]
```

Type-level: each variant axis is a mapped type keyed by the config's values, so the returned component's `color`/`size` props are unions of the configured keys, and a variant value with no matching style entry fails `tsc`. `StyleSheet.create` still backs the individual style objects; the factory composes them.

### Assumptions

- The factory's exact API ergonomics (argument shape, default-variant handling, compound variants) are settled during implementation; the plan fixes only the cva-style posture and the typed-exhaustiveness guarantee.
- A primitive's public props and variant names are preserved during migration so callers are unaffected.

---

## Implementation Units

### U1. Remove the NativeWind/Tailwind toolchain

- **Goal:** Land and verify the removal of the unused NativeWind/Tailwind build wiring from `ui/rn` — already in progress as uncommitted working-tree changes at plan time.
- **Requirements:** R5, R6.
- **Dependencies:** none.
- **Files:** `ui/rn/babel.config.js` (the `nativewind/babel` plugin is already removed; preset is `module:@react-native/babel-preset` — leave it); `ui/rn/package.json` (`nativewind` + `tailwindcss` already removed); `ui/rn/nativewind-env.d.ts` and `ui/rn/tailwind.config.js` (deleted); `ui/rn/src/app/metro-env.d.ts` (deleted). `ui/rn/metro.config.js` needs no change — it never had NativeWind wiring.
- **Approach:** Confirm the working-tree removal is complete — babel preset is `module:@react-native/babel-preset` with no `nativewind` plugin, the two config files and the `cssInterop` env declaration are gone, and the deps are out of `package.json`. Then verify Metro bundles cleanly and the zero-hit grep passes, and land the changes.
- **Patterns to follow:** the existing preset `module:@react-native/babel-preset` is correct — only the `nativewind/babel` plugin is removed.
- **Test scenarios:** Test expectation: none — config/build removal. Verify behaviorally: a clean Metro bundle starts, and the zero-hit grep returns nothing.
- **Verification:** Metro bundler starts clean; grep for `nativewind`, `tailwindcss`, `className`, `tw(`, and NativeWind-style `styled(` across `ui/rn` returns zero hits.

### U2. Build the `styled()` factory

- **Goal:** A typed factory that co-locates a component with base, variants, and interactive states, with caller-override merging and compile-time variant exhaustiveness.
- **Requirements:** R1, R2, R3, R4.
- **Dependencies:** none (foundational for U3).
- **Files:** `ui/rn/src/shared/styles/styled.ts` (new); `ui/rn/src/shared/styles/index.ts` (barrel, new); `ui/rn/src/shared/styles/__tests__/styled.test.ts` (new).
- **Approach:** Implement the cva-style factory per the High-Level Technical Design. Variant axes become typed union props; missing variant styles are compile errors (R2). Resolution merges base, selected variant styles, pressed/disabled states, and the caller override. Wire `pressed` through Pressable's function-style prop. The factory composes `StyleSheet.create`-backed objects and reads theme tokens supplied by the caller.
- **Technical design:** directional — see the High-Level Technical Design sketch. Exact default-variant and compound-variant semantics are settled during implementation.
- **Patterns to follow:** `class-variance-authority` (cva) variant-map pattern; `ui/rn/src/shared/components/ui/button.tsx` for the pressed/disabled style-array shape being replaced.
- **Test scenarios:** (use `react-test-renderer`, which is installed; `@testing-library/react-native` is not — see Verification Contract)
  - Happy: a factory-built component renders its base style; a variant prop selects the matching variant style; two variant axes compose.
  - Covers AE1: variant exhaustiveness is enforced by `yarn typecheck` (`tsc --noEmit`) as the compile-time gate — a variant value with no style entry fails the build. The jest test documents this constraint with a comment referencing the tsc gate (the repo has no compile-expect harness).
  - State: a `Pressable`-based factory component applies the `pressed` style when pressed and the `disabled` style when disabled.
  - Override: a caller-supplied `style` merges last and wins on conflicting keys.
  - Edge: omitting all variant props resolves to base only; an unknown variant value is a type error, not a runtime miss.
- **Verification:** `yarn test styled` passes; `yarn typecheck` passes including variant exhaustiveness.

### U3. Migrate shared UI primitives to the factory

- **Goal:** Convert the variant/state-bearing shared primitives to the factory and replace hardcoded values with theme tokens.
- **Requirements:** R7, R8, R9 (boundary); consumes R1–R4.
- **Dependencies:** U2.
- **Files:** `ui/rn/src/shared/components/ui/button.tsx`, `badge.tsx`, `switch.tsx`, `toggle.tsx`, `tabs.tsx`, `input.tsx`, `context-menu.tsx` (modify). Static primitives are optional and deferred per KTD4.
- **Approach:** Replace each primitive's `StyleSheet.create` + `styles[variant]` lookup with a `styled()` declaration, preserving the component's public props and variant names so callers are unaffected. Move hardcoded colors, sizes, and radii to theme tokens. Convert the `button.tsx` variant+size+pressed+disabled pattern first as the reference, then mirror it.
- **Patterns to follow:** the converted `button.tsx` as the in-repo reference; theme tokens from `src/shared/theme`.
- **Test scenarios:**
  - Per primitive: renders without error; each variant value resolves the expected style.
  - State: pressed/disabled styles apply where the primitive supports them.
  - Token usage: no hardcoded color/size/radius literals remain in migrated primitives (covers AE2; grep-enforceable).
  - Regression: existing callers compile and render unchanged (public API preserved).
- **Verification:** `yarn test` passes for primitive tests; `yarn typecheck` passes; app boots and primitives render (visual smoke).

### U4. Document the styling standard

- **Goal:** A written convention so new components follow the factory pattern and the token-first rule.
- **Requirements:** R10.
- **Dependencies:** U2, U3.
- **Files:** `ui/rn/src/shared/styles/README.md` (new), linked from `ui/rn/src/shared/`.
- **Approach:** Short doc covering the factory's API, a worked example (the converted button), when to use `styled()` vs. plain `StyleSheet.create`, and the rule that styles read theme tokens rather than hardcoding. Link it from the shared module so it is discoverable.
- **Test scenarios:** Test expectation: none — documentation.
- **Verification:** the doc exists, is linked, and is specific enough that a reader can style a new component by following it.

---

## Verification Contract

| Gate | Command | Applies to |
|------|---------|------------|
| Unit tests | `yarn test` | U2 (factory), U3 (primitives) |
| Type safety incl. variant exhaustiveness | `yarn typecheck` (`tsc --noEmit`) | U2, U3 |
| Lint | `yarn lint` (`eslint .`) | U1–U4 |
| Metro bundle smoke | `yarn start` (bundle builds, no NativeWind errors) | U1 |
| NativeWind zero-hit grep | no `nativewind`/`tailwindcss`/`className`/`tw(`/`styled(` across `ui/rn` | U1 |

Use `yarn` (never `npm`) for all UI/Node commands. Tests run inside the RN app's jest setup; no Docker is involved for this frontend work. Factory and primitive tests use `react-test-renderer` (installed); `@testing-library/react-native` and `@testing-library/react` are imported by existing tests but not installed, so `yarn test` is currently broken until that is resolved (see Outstanding Questions).

---

## Definition of Done

- NativeWind and Tailwind are fully removed from `ui/rn`; Metro bundles cleanly; the zero-hit grep passes.
- The `styled()` factory exists, is unit-tested, and enforces variant exhaustiveness at compile time.
- The variant/state-bearing shared primitives are migrated to the factory with no hardcoded theme values.
- The styling standard is documented and linked.
- `yarn test`, `yarn typecheck`, and `yarn lint` all pass.
- Per unit: U1 done when the grep is clean and Metro bundles; U2 done when factory tests and typecheck pass; U3 done when migrated primitives pass tests, typecheck, and render smoke; U4 done when the doc exists and is linked.
- Abandoned-attempt code from any approach that did not pan out is removed, not left in the diff.
