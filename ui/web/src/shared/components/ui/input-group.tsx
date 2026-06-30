import * as React from 'react';
import styled, { css } from 'styled-components';
import { Button } from '@/shared/components/ui/button';
import { Input } from '@/shared/components/ui/input';
import { Textarea } from '@/shared/components/ui/textarea';
import { darkMode } from '@/shared/theme';

const StyledInputGroup = styled.div`
  position: relative;
  display: flex;
  height: 2rem;
  width: 100%;
  min-width: 0;
  align-items: center;
  border-radius: var(--radius-lg);
  border: 1px solid var(--color-input);
  transition: background-color var(--duration-hover) ease-out, border-color var(--duration-hover) ease-out;
  outline: none;
  &:has(:disabled) { background-color: color-mix(in srgb, var(--color-input) 50%, transparent); opacity: 0.5; }
  &:has([data-slot="input-group-control"]:focus-visible) {
    border-color: var(--color-ring);
    box-shadow: 0 0 0 3px color-mix(in srgb, var(--color-ring) 50%, transparent);
  }
  &:has([data-slot][aria-invalid="true"]) {
    border-color: var(--color-destructive);
    box-shadow: 0 0 0 3px color-mix(in srgb, var(--color-destructive) 20%, transparent);
  }
  &:has(> [data-align="block-end"]) { height: auto; flex-direction: column; }
  &:has(> [data-align="block-start"]) { height: auto; flex-direction: column; }
  &:has(> [data-align="block-end"]) > input { padding-top: 0.75rem; }
  &:has(> [data-align="block-start"]) > input { padding-bottom: 0.75rem; }
  &:has(> [data-align="inline-end"]) > input { padding-right: 0.375rem; }
  &:has(> [data-align="inline-start"]) > input { padding-left: 0.375rem; }
  ${darkMode(`
    background-color: color-mix(in srgb, var(--color-input) 30%, transparent);
    &:has(:disabled) { background-color: color-mix(in srgb, var(--color-input) 80%, transparent); }
    &:has([data-slot][aria-invalid="true"]) {
      box-shadow: 0 0 0 3px color-mix(in srgb, var(--color-destructive) 40%, transparent);
    }
  `)}
`;

function InputGroup({ ...props }: React.ComponentProps<'div'>) {
  return <StyledInputGroup data-slot="input-group" role="group" {...props} />;
}

const alignStyles: Record<string, ReturnType<typeof css>> = {
  'inline-start': css`
    order: -1; padding-left: 0.5rem;
    &:has(> button) { margin-left: -0.3rem; }
    &:has(> kbd) { margin-left: -0.15rem; }
  `,
  'inline-end': css`
    order: 999; padding-right: 0.5rem;
    &:has(> button) { margin-right: -0.3rem; }
    &:has(> kbd) { margin-right: -0.15rem; }
  `,
  'block-start': css`
    order: -1; width: 100%; justify-content: flex-start; padding: 0.5rem 0.625rem; padding-top: 0.5rem;
    ${StyledInputGroup}.has(> input) & { padding-top: 0.5rem; }
    &[class*="border-b"] { padding-bottom: 0.5rem; }
  `,
  'block-end': css`
    order: 999; width: 100%; justify-content: flex-start; padding: 0.5rem 0.625rem; padding-bottom: 0.5rem;
    ${StyledInputGroup}.has(> input) & { padding-bottom: 0.5rem; }
    &[class*="border-t"] { padding-top: 0.5rem; }
  `,
};

const StyledAddon = styled.div<{ $align: string }>`
  display: flex;
  height: auto;
  cursor: text;
  align-items: center;
  justify-content: center;
  gap: 0.5rem;
  padding: 0.375rem 0;
  font-size: 0.875rem;
  font-weight: 500;
  color: var(--color-muted-foreground);
  user-select: none;
  [data-disabled="true"] & { opacity: 0.5; }
  & > kbd { border-radius: calc(var(--radius-md) - 5px); }
  & > svg:not([class*='size-']) { width: 1rem; height: 1rem; }
  ${({ $align }) => alignStyles[$align] || alignStyles['inline-start']}
`;

function InputGroupAddon({ align = 'inline-start', ...props }: React.ComponentProps<'div'> & { align?: string }) {
  return (
    <StyledAddon
      role="group"
      data-slot="input-group-addon"
      data-align={align}
      $align={align}
      onClick={(e) => {
        if ((e.target as HTMLElement).closest('button')) return;
        e.currentTarget.parentElement?.querySelector('input')?.focus();
      }}
      {...props}
    />
  );
}

function InputGroupButton({
  type = 'button',
  variant = 'ghost',
  ...props
}: Omit<React.ComponentProps<typeof Button>, 'size'> & { size?: string }) {
  return (
    <Button
      type={type}
      variant={variant}
      data-slot="input-group-button"
      {...props}
    />
  );
}

const StyledText = styled.span`
  display: flex;
  align-items: center;
  gap: 0.5rem;
  font-size: 0.875rem;
  color: var(--color-muted-foreground);
  & svg { pointer-events: none; }
  & svg:not([class*='size-']) { width: 1rem; height: 1rem; }
`;

function InputGroupText({ ...props }: React.ComponentProps<'span'>) {
  return <StyledText data-slot="input-group-text" {...props} />;
}

const controlOverride = css`
  flex: 1;
  border-radius: 0;
  border: none;
  background: transparent;
  box-shadow: none;
  &:focus-visible { box-shadow: none; }
  &:disabled { background: transparent; }
  &[aria-invalid] { box-shadow: none; }
  ${darkMode(`
    background: transparent;
    &:disabled { background: transparent; }
  `)}
`;

const StyledInput = styled(Input)`
  ${controlOverride}
`;

function InputGroupInput({ ...props }: React.ComponentProps<'input'>) {
  return <StyledInput data-slot="input-group-control" {...props} />;
}

const StyledTextarea = styled(Textarea)`
  ${controlOverride}
  padding: 0.5rem 0;
  resize: none;
`;

function InputGroupTextarea({ ...props }: React.ComponentProps<'textarea'>) {
  return <StyledTextarea data-slot="input-group-control" {...props} />;
}

export {
  InputGroup,
  InputGroupAddon,
  InputGroupButton,
  InputGroupText,
  InputGroupInput,
  InputGroupTextarea,
};
