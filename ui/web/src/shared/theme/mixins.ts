import { css } from 'styled-components';

export const focusVisibleRing = css`
  &:focus-visible {
    border-color: var(--color-ring);
    box-shadow: 0 0 0 3px color-mix(in srgb, var(--color-ring) 50%, transparent);
    outline: none;
  }
`;

export const interactiveTransition = (
  properties: string[] = ['color', 'background-color', 'border-color', 'opacity', 'box-shadow', 'transform']
) => css`
  transition: ${properties.map(p => `${p} var(--duration-hover) ease-out`).join(', ')};
`;

export const inputFocusRing = css`
  &:focus-visible {
    border-color: var(--color-ring);
    box-shadow: 0 0 0 3px color-mix(in srgb, var(--color-ring) 50%, transparent);
  }
`;

export const disabledStyle = css`
  &:disabled { pointer-events: none; opacity: 0.5; }
`;

export const ariaInvalidRing = css`
  &[aria-invalid="true"] {
    border-color: var(--color-destructive);
    box-shadow: 0 0 0 3px color-mix(in srgb, var(--color-destructive) 20%, transparent);
  }
  [data-theme="dark"] & {
    &[aria-invalid="true"] {
      border-color: color-mix(in srgb, var(--color-destructive) 50%, transparent);
      box-shadow: 0 0 0 3px color-mix(in srgb, var(--color-destructive) 40%, transparent);
    }
  }
`;

export const darkMode = (styles: string) => css`
  [data-theme="dark"] & { ${styles} }
`;
