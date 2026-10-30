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
