---
title: The as prop on a styled wrapper replaces the wrapped component
date: "2026-11-04"
category: ui-bugs
module: Web content text (ContentText) and styled-components wrappers
problem_type: ui_bug
component: frontend
symptoms:
  - "An element rendered through a styled(ContentText) wrapper with as=\"p\" has no lang or data-script attribute"
  - "React warns that a DOM element does not recognise the textRole prop, and text leaks into the DOM as an attribute"
  - Script rules and Han glyph forms silently stop applying to that element while the text still renders
root_cause: wrong_api
resolution_type: code_fix
severity: medium
applies_when:
  - Writing a component that other code wraps with styled() and that renders its own element
  - "Giving a styled(Component) wrapper an as prop to change the rendered tag"
tags: [styled-components, as-prop, forwardedAs, react, contenttext, lang]
---

# The as prop on a styled wrapper replaces the wrapped component

## Problem

`ContentText` renders one content string with its resolved `lang` and `data-script` tokens. It originally took an `as` prop to choose its tag. Features styled it with `styled(ContentText)` and passed `as="p"`. styled-components consumes `as` on every styled component and renders that tag *instead of* the wrapped component, so `ContentText` never ran: the element lost its language and script markers, and props meant for `ContentText` reached the DOM.

## Symptoms

- Playlist tests failed on a title that should have carried `lang="ja"`; the element had no `lang`.
- React warned about the unknown `textRole` DOM prop.
- Nothing visibly broke: the text rendered, only without its script rules and glyph-form selection.

## What Didn't Work

- `forwardedAs` on every wrapper. It works (styled-components passes `forwardedAs` through as `as`), but it spread to about 50 call sites, needed a census rule to catch the next missed one, and a direct `as` on a wrapper still failed silently.

## Solution

Name the component's own tag prop something styled-components does not reserve. `ContentText` takes `element?: ElementType` and passes it to its inner styled element as `as={element}` (`ui/web/src/shared/typography/ContentText.tsx`). Wrappers pass `element="p"` straight through. The census (`ui/web/tests/typography/census.test.ts`, rule `content-text-as`) still fails on `as` given to a `styled(ContentText)` wrapper declared in the same file, because that would still swap out the component.

## Why This Works

styled-components handles `as` and `forwardedAs` itself; every other non-`$` prop on a wrapped custom component is forwarded unchanged. A differently named prop therefore always reaches the wrapped component, which applies it to its own inner element.

## Prevention

- A component that renders an element and is meant to be wrapped by `styled()` should never expose its tag choice as `as`; use a name such as `element`.
- Tests for such a component should assert its own output attributes (`lang`, `data-script`) through a styled wrapper, not only when rendered bare.
