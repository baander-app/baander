/**
 * styled() — a typed component-style factory for Baander RN.
 *
 * Co-locates a component with base styles, typed variant props, and interactive
 * states (pressed, disabled), composing them into the style array React Native
 * merges at render. Modeled on the class-variance-authority (cva) pattern,
 * adapted for RN: variant values come from the config, so TypeScript enforces
 * that every variant a caller can pass has a matching style (R2 exhaustiveness),
 * and Pressable's function-style prop carries the pressed state.
 *
 * Styles read theme tokens supplied by the caller (R3); the factory itself is
 * token-agnostic and stays free of ui/rn-specific coupling so it can lift to
 * @baander/shared later.
 */

import React, { type ComponentType } from 'react';
import { Pressable, type StyleProp, type ViewStyle } from 'react-native';

export type Style = StyleProp<ViewStyle>;

/** Per-axis variant maps: axis -> { variant value -> style }. Inner styles are
 * required, so the set of allowed variant values is exactly the set of styled
 * values — the exhaustiveness guarantee (R2). */
export type VariantMap = Record<string, Record<string, Style>>;

export interface StyledConfig {
  base?: Style;
  variants?: VariantMap;
  states?: { pressed?: Style; disabled?: Style };
}

/** Props added by the factory: one optional prop per variant axis, typed to the
 * axis's configured values, plus `disabled` and a `style` override. */
export type VariantProps<C extends StyledConfig> =
  C extends { variants: infer V }
    ? V extends VariantMap
      ? { [K in keyof V]?: keyof V[K] }
      : {}
    : {};

export interface StyledExtraProps<C extends StyledConfig> extends VariantProps<C> {
  disabled?: boolean;
  style?: Style;
}

export type StyledComponentProps<P, C extends StyledConfig> = Omit<P, 'style' | 'disabled'> &
  StyledExtraProps<C>;

/**
 * Wrap a host component (Pressable, View, Text, ...) with a style config.
 *
 * Variant axis names (e.g. `color`, `size`) become typed props on the returned
 * component and are stripped before forwarding — they are style selectors, not
 * host props. `disabled` is forwarded when the host accepts it. A caller
 * `style` override is merged last and wins on conflicting keys (R4).
 *
 * When the host is Pressable, the pressed state is wired through its
 * function-style prop; otherwise `states.pressed` does not apply.
 */
export function styled<P extends Record<string, any>, C extends StyledConfig>(
  Component: ComponentType<P>,
  config: C,
): ComponentType<StyledComponentProps<P, C>> {
  const axes = config.variants ? Object.keys(config.variants) : [];
  const pressedStyle = config.states?.pressed;

  const Styled = React.forwardRef<any, StyledComponentProps<P, C>>((props, ref) => {
    const { disabled, style, ...rest } = props as Record<string, any>;

    // Pull variant selectors out of the forwarded props.
    const selected: Record<string, string> = {};
    for (const axis of axes) {
      if (rest[axis] !== undefined) {
        selected[axis] = rest[axis];
        delete rest[axis];
      }
    }

    const resolved = resolveStyles(config, selected, disabled);

    if (Component === Pressable) {
      return React.createElement(Component, {
        ...(rest as P),
        disabled,
        ref,
        // Caller `style` override merges last so it wins on conflict (R4) —
        // same ordering as the non-Pressable branch below.
        style: ({ pressed }: { pressed: boolean }) => [...resolved, pressed && pressedStyle, style],
      });
    }

    // Non-Pressable hosts (View/Text/TextInput) don't accept a `disabled` prop
    // (TextInput uses `editable`); the disabled STATE style already applied via
    // resolveStyles, so we don't forward the prop itself.
    return React.createElement(Component, {
      ...(rest as P),
      ref,
      style: [...resolved, style],
    });
  });

  Styled.displayName = `styled(${Component.displayName || Component.name || 'Component'})`;
  return Styled as unknown as ComponentType<StyledComponentProps<P, C>>;
}

function resolveStyles(
  config: StyledConfig,
  selected: Record<string, string>,
  disabled?: boolean,
): Style[] {
  const out: Style[] = [];
  if (config.base) out.push(config.base);
  if (config.variants) {
    for (const axis of Object.keys(config.variants)) {
      const value = selected[axis];
      const style = value !== undefined ? config.variants[axis][value] : undefined;
      if (style) out.push(style);
    }
  }
  if (disabled && config.states?.disabled) out.push(config.states.disabled);
  return out;
}
