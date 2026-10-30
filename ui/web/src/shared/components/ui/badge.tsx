import * as React from 'react';
import { styled, css } from 'styled-components';
import { Slot } from 'radix-ui';
import { focusVisibleRing, ariaInvalidRing } from '@/shared/theme';

const baseStyles = css`
  display: inline-flex;
  color: var(--color-foreground);
  height: 1.25rem;
  width: fit-content;
  flex-shrink: 0;
  align-items: center;
  justify-content: center;
  gap: 0.25rem;
  overflow: hidden;
  border-radius: var(--radius-4xl);
  border: 1px solid transparent;
  padding: 0.125rem 0.5rem;
  font-size: 0.75rem;
  font-weight: 500;
  white-space: nowrap;
  transition: all var(--duration-hover) ease-out;
  ${focusVisibleRing}
  ${ariaInvalidRing}
  & > svg { pointer-events: none; width: 0.75rem !important; height: 0.75rem !important; }
  &:has([data-icon="inline-end"]) { padding-right: 0.375rem; }
  &:has([data-icon="inline-start"]) { padding-left: 0.375rem; }
`;

const variantStyles: Record<string, ReturnType<typeof css>> = {
  default: css`
    background-color: var(--color-accent-text);
    color: var(--color-primary-foreground);
    a&:hover { background-color: var(--color-primary-hover); }
  `,
  secondary: css`
    background-color: var(--color-secondary);
    color: var(--color-secondary-foreground);
    a&:hover { background-color: color-mix(in srgb, var(--color-secondary) 80%, transparent); }
  `,
  destructive: css`
    background-color: var(--color-destructive);
    color: var(--color-destructive-foreground);
    a&:hover { background-color: var(--color-destructive-hover); }
  `,
  outline: css`
    border-color: var(--color-border);
    color: var(--color-foreground);
    a&:hover { background-color: var(--color-muted); color: var(--color-muted-foreground); }
  `,
  ghost: css`
    &:hover { background-color: var(--color-muted); color: var(--color-muted-foreground); }
  `,
  link: css`
    color: var(--color-primary);
    text-underline-offset: 4px;
    &:hover { text-decoration: underline; }
  `,
};

const StyledBadge = styled.span<{ $variant: string }>`
  ${baseStyles}
  ${({ $variant }) => variantStyles[$variant] || variantStyles.default}
`;

function Badge({
  variant = 'default',
  asChild = false,
  ...props
}: React.ComponentProps<'span'> & {
  variant?: 'default' | 'secondary' | 'destructive' | 'outline' | 'ghost' | 'link';
  asChild?: boolean;
}) {
  const Comp = asChild ? (Slot.Root as React.ComponentType<React.ComponentProps<'span'> & React.RefAttributes<HTMLSpanElement>>) : ('span' as const);

  return (
    <StyledBadge
      as={Comp as never}
      data-slot="badge"
      data-variant={variant}
      $variant={variant}
      {...props}
    />
  );
}

export { Badge };
