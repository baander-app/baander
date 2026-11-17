---
title: Fade a gradient surface through a number inside its own colour expressions
date: "2026-11-17"
category: best-practices
module: Web theme and the footer player's well
problem_type: best_practice
component: frontend
severity: medium
applies_when:
  - A surface painted with a gradient and box-shadows must fade to a flat face and back
  - The surface must render at rest exactly as it did before the fade existed
  - Checking that a styling refactor left a surface's pixels unchanged
tags: [css, at-property, color-mix, gradient, box-shadow, styled-components, pixel-comparison, playwright]
---

# Fade a gradient surface through a number inside its own colour expressions

## Context

The footer player's central display holds the well: a `linear-gradient` background, an inset
box-shadow and a one-pixel outer lip. While the sidebar notification pane is open the display has
to go flat, and it has to take the well back when the pane closes, over 120ms. The footer at rest
had to stay as it was. A `background-image` gradient does not interpolate and neither does an
unregistered custom property, so a plain transition fades the shadows while the gradient snaps.

The work is on the branch `feat/notification-pane-well` (commit subjects "refactor(web): move the
footer's well into a shared definition (U1)" and "refactor(web): keep the well's presence on its
element and tidy the handoff spec") and is not merged yet. Every figure below was measured on the
worktree preview in Chromium at 1280px with a track paused, against captures of the original
source. Two captures of the original source are identical to each other.

## Guidance

Register one number with `@property` and put it inside each colour's own expression, so that at
its resting value the expression is the one the surface already had.
`ui/web/src/shared/theme/well.ts` does this with `--well-presence`:

```css
@property --well-presence { syntax: '<number>'; inherits: false; initial-value: 1; }

background-image: linear-gradient(
  to bottom,
  color-mix(in srgb, var(--well-surface) calc(100% - calc(var(--well-depth) * var(--well-presence, 1))), black),
  var(--well-surface) 85%,
  color-mix(in srgb, var(--well-surface) calc(100% - calc(1% * var(--well-presence, 1))), white)
);
box-shadow:
  inset 0 1px 2px rgb(0 0 0 / calc(var(--well-edge-opacity) * var(--well-presence, 1))),
  0 1px 0 rgb(255 255 255 / calc(var(--well-lip-opacity) * var(--well-presence, 1)));
transition: --well-presence 120ms ease-out;
```

At a presence of 1 the first stop is `color-mix(in srgb, surface 96%, black)`, which is what the
footer declared before. The yielded state sets `--well-presence: 0`. A browser without `@property`
reads the fallback of 1 and changes at once. The number does not inherit: only the element that
paints the shading reads it, so a fade restyles nothing inside it.

Three other ways were measured and changed the footer at rest:

- **A separate shading layer faded by opacity.** A pseudo-element behind the content carried the
  gradient and both shadows. The display ends between pixels (its lower edge sat at 83.296875px),
  and two stacked boxes anti-alias differently from one. The lip came out weaker: 243 became 239
  over a card of 235 in the balanced mood, and 26 became 22 over 10 in the dark mood. 103 of 1,296
  sampled pixels changed.
- **Registering the four shading colours as `<color>` and transitioning them.** The dark, cool and
  balanced moods stayed byte-identical. The warm mood's gradient differed by one level in one
  channel on 27 of 1,296 samples, the same way in every capture.
- **One registered number, with each finished colour mixed again**
  (`color-mix(in srgb, <colour> calc(presence * 100%), <flat>)`). The same 27 warm samples
  differed. A second `color-mix()` around a finished colour shifts its value by less than a level,
  which is enough to change how a gradient rounds where a channel sits near a boundary.

## Why This Matters

Each rejected variant looks the same to the eye and passes the footer's contrast and geometry
spec, which asserts thresholds and compares no captures. Only a pixel comparison against the
original told them apart, and only a second capture of the original showed that a difference was
real and not noise between runs.

The comparison also has limits worth knowing before trusting it. With the shipped code the dark,
cool and balanced moods were byte-identical to the original over the whole footer in every
capture. The warm mood was not stable: after an animated mood switch, four of five captures
differed from the original by one level in one channel on 2,476 to 3,536 of 117,760 pixels and one
matched it; with the mood set without the animated switch, three captures each differed on 11
pixels. The first capture's sampled columns were identical (0 of 1,296) while a later capture's
differed on 33. The computed declarations are the same as before, and the cause in the browser's
rasteriser was not found.

## When to Apply

- When a gradient, a shadow or both must animate and the resting state is under a "do not change"
  requirement. Fold the animated number into the existing expressions; do not move the paint to
  another box and do not wrap finished colours.
- When verifying "unchanged at rest": capture before editing, compare the whole image and not only
  sampled columns, compare in every mood, repeat the capture of the new code, and capture the
  original twice to learn what noise looks like.

## Examples

The comparison that caught the differences read each capture with Pillow and counted differing
pixels by size, so a seek thumb at a different position (differences of many levels in a
13-pixel box) was not confused with rounding (one level in one channel across the gradient):

```python
from PIL import Image

before = Image.open('before/footer-warm.png').convert('RGB').load()
after = Image.open('after/footer-warm.png').convert('RGB').load()
one_level = larger = 0
for y in range(height):
    for x in range(width):
        if before[x, y] != after[x, y]:
            if max(abs(before[x, y][c] - after[x, y][c]) for c in range(3)) <= 1:
                one_level += 1
            else:
                larger += 1
```

The registration also works when styled-components inserts rules through the CSSOM, as a
production build does: with that mode forced on in the preview, `transitionrun` on the display
reported `--well-presence` in three runs. A production build was not served.

## Related

- `docs/solutions/best-practices/browser-font-checks-that-pass-without-proving-anything.md`: break
  the thing a browser check guards and confirm the check fails. The handoff spec's guard checks
  followed it.
- `ROADMAP.md`, section "Notification pane well", records the measurements and the open item on
  the warm mood.
