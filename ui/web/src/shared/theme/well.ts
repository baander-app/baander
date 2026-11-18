import { css } from 'styled-components';

/**
 * How long the well takes to appear on one surface and to leave another, with its easing.
 * Every surface that hands the well over uses this value, so the two changes take the same time.
 */
export const WELL_HANDOFF = '120ms ease-out';

/**
 * Registers the well's presence as a number, for the global stylesheet: 1 while a surface holds
 * the well, 0 once it has yielded it. A gradient does not interpolate and neither does an
 * unregistered custom property; a registered number does, so the gradient, the inset edge and
 * the lip fade together on the element that paints them. The presence belongs to that element
 * alone and does not inherit, so a fade restyles nothing inside it. A browser without
 * `@property` reads the fallback of 1 and changes at once.
 */
export const wellProperties = css`
  @property --well-presence { syntax: '<number>'; inherits: false; initial-value: 1; }
`;

/**
 * The well's measures on a surface of the given colour: how far the face darkens at its upper
 * edge, how far down that shade fades into the face, the opacity of the inset edge and of the
 * lower lip, and the secondary text colour that stays readable over the shade. Set them on the
 * element that holds the well. The fade is a share of a short surface's height; a tall surface
 * sets a fixed length instead, so its shade keeps the same profile however tall it grows.
 */
export const wellColors = (surface: string) => css`
  --well-surface: ${surface};
  --well-depth: 4%;
  --well-fade: 85%;
  --well-edge-opacity: 16%;
  --well-lip-opacity: 80%;
  --well-secondary-text: color-mix(in srgb, var(--color-muted-foreground) 85%, var(--color-foreground));

  :root[data-theme='dark'] &, :root:not([data-theme]) & {
    --well-depth: 20%;
    --well-edge-opacity: 75%;
    --well-lip-opacity: 8%;
  }
`;

/**
 * An amount of shading at the well's presence: the full amount at 1, none at 0. Each shading
 * colour takes the presence inside its own expression, so at rest it computes to exactly the
 * value it has without a presence; mixing a finished colour again would shift it slightly.
 */
const present = (amount: string) => `calc(${amount} * var(--well-presence, 1))`;

/**
 * The well's shading over a surface that carries `wellColors`: the gradient, the inset upper edge
 * and the lower lip. A surface that floats above the page, such as an overlay, sets `--well-lift`
 * to its drop shadow; it stays while the shading fades, because it lifts the surface, not the well.
 */
export const wellShading = css`
  background-image: linear-gradient(
    to bottom,
    color-mix(in srgb, var(--well-surface) calc(100% - ${present('var(--well-depth)')}), black),
    var(--well-surface) var(--well-fade),
    color-mix(in srgb, var(--well-surface) calc(100% - ${present('1%')}), white)
  );
  box-shadow:
    inset 0 1px 2px rgb(0 0 0 / ${present('var(--well-edge-opacity)')}),
    0 1px 0 rgb(255 255 255 / ${present('var(--well-lip-opacity)')}),
    var(--well-lift, 0 0 transparent);
`;

/** A surface that has given the well up: its face is flat and it has no edge or lip. */
export const wellYielded = css`
  --well-presence: 0;
`;

/** Fades the shading when a surface yields the well or takes it back. */
export const wellHandoffTransition = css`
  transition: --well-presence ${WELL_HANDOFF};
`;
