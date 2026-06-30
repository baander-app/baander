import * as React from 'react';
import { styled, css } from 'styled-components';
import { Toggle as TogglePrimitive } from 'radix-ui';
import { focusVisibleRing, interactiveTransition, disabledStyle, ariaInvalidRing } from '@/shared/theme';

const baseStyles = css`
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 0.25rem;
  border-radius: var(--radius-lg);
  font-size: 0.875rem;
  font-weight: 500;
  white-space: nowrap;
  outline: none;
  ${interactiveTransition(['color', 'background-color', 'border-color', 'opacity'])}
  transition-duration: var(--duration-hover);
  &:hover { background-color: var(--color-muted); color: var(--color-foreground); }
  ${focusVisibleRing}
  ${disabledStyle}
  ${ariaInvalidRing}
  &[aria-pressed="true"], &[data-state="on"] { background-color: var(--color-muted); }
  & svg { pointer-events: none; flex-shrink: 0; }
  & svg:not([class*='size-']) { width: 1rem; height: 1rem; }
`;

const variantStyles: Record<string, ReturnType<typeof css>> = {
  default: css` background-color: transparent; `,
  outline: css`
    border: 1px solid var(--color-input);
    background-color: transparent;
    &:hover { background-color: var(--color-muted); }
  `,
};

const sizeStyles: Record<string, ReturnType<typeof css>> = {
  default: css`
    height: 2rem; min-width: 2rem; padding: 0 0.625rem;
    &:has([data-icon="inline-end"]) { padding-right: 0.5rem; }
    &:has([data-icon="inline-start"]) { padding-left: 0.5rem; }
  `,
  sm: css`
    height: 1.75rem; min-width: 1.75rem; padding: 0 0.625rem; font-size: 0.8rem;
    border-radius: min(var(--radius-md), 12px);
    & svg:not([class*='size-']) { width: 0.875rem; height: 0.875rem; }
  `,
  lg: css`
    height: 2.25rem; min-width: 2.25rem; padding: 0 0.625rem;
    &:has([data-icon="inline-end"]) { padding-right: 0.5rem; }
    &:has([data-icon="inline-start"]) { padding-left: 0.5rem; }
  `,
};

const StyledToggle = styled(TogglePrimitive.Root)<{ $variant: string; $size: string }>`
  ${baseStyles}
  ${({ $variant }) => variantStyles[$variant] || variantStyles.default}
  ${({ $size }) => sizeStyles[$size] || sizeStyles.default}
`;

function Toggle({
  variant = 'default',
  size = 'default',
  ...props
}: React.ComponentProps<typeof TogglePrimitive.Root> & {
  variant?: 'default' | 'outline';
  size?: 'default' | 'sm' | 'lg';
}) {
  return (
    <StyledToggle
      data-slot="toggle"
      $variant={variant}
      $size={size}
      {...props}
    />
  );
}

export { Toggle };
