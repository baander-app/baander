import { css } from 'styled-components';

export const focusVisibleRing = css`
  &:focus-visible {
    border-color: var(--color-ring);
    outline: 2px solid var(--color-ring);
    outline-offset: 2px;
  }
`;

export const interactiveTransition = (
  properties: string[] = ['color', 'background-color', 'border-color', 'opacity', 'box-shadow', 'transform']
) => css`
  transition: ${properties.map(p => `${p} var(--duration-hover) ease-out`).join(', ')};
`;

export const disabledStyle = css`
  &:disabled { pointer-events: none; opacity: 0.5; }
`;

export const ariaInvalidRing = css`
  &[aria-invalid="true"] {
    border-color: var(--color-destructive);
    box-shadow: inset 0 0 0 1px var(--color-destructive);
  }
`;

export type TextRole =
  | 'hero'
  | 'title-lg'
  | 'title'
  | 'subtitle'
  | 'body'
  | 'body-sm'
  | 'caption'
  | 'label'
  | 'timecode'
  | 'mono-sm';

const MONO_TEXT_ROLES: ReadonlySet<TextRole> = new Set(['timecode', 'mono-sm']);

/**
 * Applies a type role from the design tokens. The family is always read from
 * `--font-ui` or `--font-mono` on the element itself, so a `lang`-dependent font
 * order reaches it. Script rules arrive as inherited custom properties: tracking is
 * multiplied by `--tracking-scale`, the line-height never drops below
 * `--script-line-height`, and `--script-size-boost` is added to the size. The mono
 * roles also set tabular numerals so changing digits keep their width. The dense
 * option sets the smaller dense size after the role's own size, for admin tables,
 * badges and chips.
 */
export const textRole = (role: TextRole, { dense = false }: { readonly dense?: boolean } = {}) => {
  const mono = MONO_TEXT_ROLES.has(role);

  return css`
    font-family: ${mono ? 'var(--font-mono)' : 'var(--font-ui)'};
    font-size: calc(var(--text-${role}-size) + var(--script-size-boost, 0px));
    font-weight: var(--text-${role}-weight);
    letter-spacing: calc(var(--text-${role}-tracking) * var(--tracking-scale, 1));
    line-height: max(var(--text-${role}-line-height), var(--script-line-height, 0));
    ${role === 'label' ? 'text-transform: var(--text-label-transform);' : ''}
    ${mono ? 'font-variant-numeric: tabular-nums;' : ''}
    ${dense ? 'font-size: calc(var(--text-dense-size) + var(--script-size-boost, 0px));' : ''}
  `;
};
