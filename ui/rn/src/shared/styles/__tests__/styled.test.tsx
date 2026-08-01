/**
 * Unit tests for the styled() factory.
 *
 * NOTE: these do not run yet — the RN app has no jest configuration (no
 * `preset: 'react-native'`, no setup/mocks). They run once jest is wired up as
 * part of the RN 0.86 upgrade. Written against react-test-renderer (installed);
 * @testing-library/react-native is intentionally not used.
 *
 * Variant exhaustiveness (AE1 / R2) is a compile-time property enforced by
 * `tsc --noEmit` (run via `yarn typecheck`): a variant value with no matching
 * style entry fails the build because VariantProps derives each axis from the
 * config's keys. The cases below cover the runtime behavior; the type check is
 * the exhaustiveness gate.
 */

import React from 'react';
import { Pressable, View, Text } from 'react-native';
import TestRenderer from 'react-test-renderer';
import { styled } from '../styled';

const base = { backgroundColor: 'black' } as const;
const cardDefault = { backgroundColor: 'blue' } as const;
const cardOutline = { borderWidth: 1 } as const;
const sizeSm = { padding: 4 } as const;
const sizeLg = { padding: 16 } as const;
const pressedStyle = { opacity: 0.8 } as const;
const disabledStyle = { opacity: 0.5 } as const;

const Card = styled(View, {
  base,
  variants: { color: { default: cardDefault, outline: cardOutline }, size: { sm: sizeSm, lg: sizeLg } },
});

const Button = styled(Pressable, {
  base,
  variants: { color: { default: cardDefault, outline: cardOutline } },
  states: { pressed: pressedStyle, disabled: disabledStyle },
});

const styleOf = (instance: TestRenderer.ReactTestInstance): any =>
  instance.props.style;

describe('styled()', () => {
  it('renders the base style when no variant is given', () => {
    const r = TestRenderer.create(React.createElement(Card));
    // base is the first element of the merged style array
    expect(styleOf(r.root)).toContain(base);
  });

  it('selects the matching variant style and composes two axes', () => {
    const r = TestRenderer.create(React.createElement(Card, { color: 'outline', size: 'lg' }));
    const style = styleOf(r.root);
    expect(style).toContain(cardOutline);
    expect(style).toContain(sizeLg);
    expect(style).not.toContain(cardDefault);
  });

  it('merges a caller style override last (wins on conflict)', () => {
    const override = { backgroundColor: 'red' } as const;
    const r = TestRenderer.create(React.createElement(Card, { style: override }));
    expect(styleOf(r.root)[styleOf(r.root).length - 1]).toBe(override);
  });

  it('merges a caller style override last on a Pressable-based component', () => {
    const override = { backgroundColor: 'red' } as const;
    const r = TestRenderer.create(React.createElement(Button, { style: override }));
    const resolved = styleOf(r.root)({ pressed: false });
    expect(resolved[resolved.length - 1]).toBe(override);
  });

  it('wires the pressed state through Pressable function-style', () => {
    const r = TestRenderer.create(React.createElement(Button));
    const styleFn = styleOf(r.root);
    expect(typeof styleFn).toBe('function');
    const whenPressed = styleFn({ pressed: true });
    const whenNot = styleFn({ pressed: false });
    expect(whenPressed).toContain(pressedStyle);
    expect(whenNot.filter((s: any) => s === pressedStyle)).toHaveLength(0);
  });

  it('applies the disabled state style', () => {
    const r = TestRenderer.create(React.createElement(Button, { disabled: true }));
    const styleFn = styleOf(r.root);
    const resolved = styleFn({ pressed: false });
    expect(resolved).toContain(disabledStyle);
  });

  it('forwards host props (e.g. children to a styled Text) and strips variant selectors', () => {
    const Label = styled(Text, { base, variants: { tone: { muted: { color: 'gray' } as const } } });
    const r = TestRenderer.create(React.createElement(Label, { tone: 'muted' }, 'hi'));
    expect(r.root.props.children).toBe('hi');
    // tone is a style selector, not forwarded to Text
    expect(r.root.props.tone).toBeUndefined();
  });
});
