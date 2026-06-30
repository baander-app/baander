import * as React from 'react';
import { styled, css } from 'styled-components';
import { Slot } from 'radix-ui';
import { focusVisibleRing, interactiveTransition, disabledStyle, ariaInvalidRing, darkMode } from '@/shared/theme';

const baseStyles = css`
  display: inline-flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
  border: 1px solid transparent;
  background-clip: padding-box;
  font-size: 0.875rem;
  font-weight: 500;
  white-space: nowrap;
  outline: none;
  user-select: none;
  ${interactiveTransition(['color', 'background-color', 'border-color', 'opacity', 'box-shadow', 'transform'])}
  transition-duration: var(--duration-hover);
  ${focusVisibleRing}
  ${disabledStyle}
  ${ariaInvalidRing}
  &:active:not(:has([aria-haspopup])) { transform: translateY(1px); }
  & svg { pointer-events: none; flex-shrink: 0; }
  & svg:not([class*='size-']) { width: 1rem; height: 1rem; }
`;

const variantStyles: Record<string, ReturnType<typeof css>> = {
  default: css`
    background-color: var(--color-primary);
    color: var(--color-primary-foreground);
    border-radius: var(--radius-lg);
    &:hover, a&:hover { background-color: color-mix(in srgb, var(--color-primary) 80%, transparent); }
  `,
  outline: css`
    border-color: var(--color-border);
    background-color: var(--color-background);
    border-radius: var(--radius-lg);
    &:hover { background-color: var(--color-muted); color: var(--color-foreground); }
    &[aria-expanded="true"] { background-color: var(--color-muted); color: var(--color-foreground); }
    ${darkMode(`
      border-color: var(--color-input);
      background-color: color-mix(in srgb, var(--color-input) 30%, transparent);
      &:hover { background-color: color-mix(in srgb, var(--color-input) 50%, transparent); }
    `)}
  `,
  secondary: css`
    background-color: var(--color-secondary);
    color: var(--color-secondary-foreground);
    border-radius: var(--radius-lg);
    &:hover { background-color: color-mix(in srgb, var(--color-secondary) 80%, transparent); }
    &[aria-expanded="true"] { background-color: var(--color-secondary); color: var(--color-secondary-foreground); }
  `,
  ghost: css`
    background-color: transparent;
    border-radius: var(--radius-lg);
    &:hover { background-color: var(--color-muted); color: var(--color-foreground); }
    &[aria-expanded="true"] { background-color: var(--color-muted); color: var(--color-foreground); }
    ${darkMode(`
      &:hover { background-color: color-mix(in srgb, var(--color-muted) 50%, transparent); }
    `)}
  `,
  destructive: css`
    background-color: color-mix(in srgb, var(--color-destructive) 10%, transparent);
    color: var(--color-destructive);
    border-radius: var(--radius-lg);
    &:hover { background-color: color-mix(in srgb, var(--color-destructive) 20%, transparent); }
    &:focus-visible { border-color: color-mix(in srgb, var(--color-destructive) 40%, transparent); box-shadow: 0 0 0 3px color-mix(in srgb, var(--color-destructive) 20%, transparent); }
    ${darkMode(`
      background-color: color-mix(in srgb, var(--color-destructive) 20%, transparent);
      &:hover { background-color: color-mix(in srgb, var(--color-destructive) 30%, transparent); }
      &:focus-visible { box-shadow: 0 0 0 3px color-mix(in srgb, var(--color-destructive) 40%, transparent); }
    `)}
  `,
  link: css`
    background-color: transparent;
    color: var(--color-primary);
    text-underline-offset: 4px;
    &:hover { text-decoration: underline; }
  `,
};

const sizeStyles: Record<string, ReturnType<typeof css>> = {
  default: css`
    height: 2rem; gap: 0.375rem; padding: 0 0.625rem;
    &:has([data-icon="inline-end"]) { padding-right: 0.5rem; }
    &:has([data-icon="inline-start"]) { padding-left: 0.5rem; }
  `,
  xs: css`
    height: 1.5rem; gap: 0.25rem; padding: 0 0.5rem; font-size: 0.75rem;
    border-radius: min(var(--radius-md), 10px);
    & svg:not([class*='size-']) { width: 0.75rem; height: 0.75rem; }
  `,
  sm: css`
    height: 1.75rem; gap: 0.25rem; padding: 0 0.625rem; font-size: 0.8rem;
    border-radius: min(var(--radius-md), 12px);
    & svg:not([class*='size-']) { width: 0.875rem; height: 0.875rem; }
  `,
  lg: css`
    height: 2.25rem; gap: 0.375rem; padding: 0 0.625rem;
    &:has([data-icon="inline-end"]) { padding-right: 0.5rem; }
    &:has([data-icon="inline-start"]) { padding-left: 0.5rem; }
  `,
  icon: css` width: 2rem; height: 2rem; `,
  'icon-xs': css`
    width: 1.5rem; height: 1.5rem;
    border-radius: min(var(--radius-md), 10px);
    & svg:not([class*='size-']) { width: 0.75rem; height: 0.75rem; }
  `,
  'icon-sm': css`
    width: 1.75rem; height: 1.75rem;
    border-radius: min(var(--radius-md), 12px);
  `,
  'icon-lg': css` width: 2.25rem; height: 2.25rem; `,
};

const StyledButton = styled.button<{ $variant: string; $size: string }>`
  ${baseStyles}
  ${({ $variant }) => variantStyles[$variant] || variantStyles.default}
  ${({ $size }) => sizeStyles[$size] || sizeStyles.default}
`;

function Button({
  variant = 'default',
  size = 'default',
  asChild = false,
  ...props
}: React.ComponentProps<'button'> & {
  variant?: 'default' | 'outline' | 'secondary' | 'ghost' | 'destructive' | 'link';
  size?: 'default' | 'xs' | 'sm' | 'lg' | 'icon' | 'icon-xs' | 'icon-sm' | 'icon-lg';
  asChild?: boolean;
}) {
  const Comp = asChild ? (Slot.Root as React.ComponentType<React.ComponentProps<'button'> & React.RefAttributes<HTMLButtonElement>>) : ('button' as const);

  return (
    <StyledButton
      as={Comp as never}
      data-slot="button"
      data-variant={variant}
      data-size={size}
      $variant={variant}
      $size={size}
      {...props}
    />
  );
}

export { Button };
