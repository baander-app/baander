import * as React from 'react';
import styled from 'styled-components';
import { Command as CommandPrimitive } from 'cmdk';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogTitle,
} from '@/shared/components/ui/dialog';
import {
  InputGroup,
  InputGroupAddon,
} from '@/shared/components/ui/input-group';
import { SearchIcon, CheckIcon } from 'lucide-react';

const ScreenReaderOnly = styled.div`
  position: absolute; width: 1px; height: 1px; padding: 0; margin: -1px;
  overflow: hidden; clip: rect(0, 0, 0, 0); white-space: nowrap; border: 0;
`;

const StyledCommand = styled(CommandPrimitive)`
  display: flex;
  width: 100%;
  height: 100%;
  flex-direction: column;
  overflow: hidden;
  border-radius: var(--radius-xl) !important;
  background-color: var(--color-popover);
  padding: 0.25rem;
  color: var(--color-popover-foreground);
`;

function Command({ ...props }: React.ComponentProps<typeof CommandPrimitive>) {
  return <StyledCommand data-slot="command" {...props} />;
}

function CommandDialog({
  title = 'Command Palette',
  description = 'Search for a command to run...',
  children,
  showCloseButton = false,
  ...props
}: React.ComponentProps<typeof Dialog> & {
  title?: string;
  description?: string;
  showCloseButton?: boolean;
}) {
  return (
    <Dialog {...props}>
      <ScreenReaderOnly>
        <DialogTitle>{title}</DialogTitle>
        <DialogDescription>{description}</DialogDescription>
      </ScreenReaderOnly>
      <DialogContent showCloseButton={showCloseButton} style={{ top: '33%', transform: 'translateY(0)', overflow: 'hidden', borderRadius: 'var(--radius-xl)', padding: 0 }}>
        {children}
      </DialogContent>
    </Dialog>
  );
}

const InputWrapper = styled.div`
  padding: 0.25rem 0.25rem 0;
`;

const StyledInput = styled(CommandPrimitive.Input)`
  width: 100%;
  font-size: 0.875rem;
  outline: none;
  &:disabled { cursor: not-allowed; opacity: 0.5; }
`;

function CommandInput({ ...props }: React.ComponentProps<typeof CommandPrimitive.Input>) {
  return (
    <InputWrapper data-slot="command-input-wrapper">
      <InputGroup style={{ height: '2rem', borderRadius: 'var(--radius-lg)', borderColor: 'color-mix(in srgb, var(--color-input) 30%, transparent)', backgroundColor: 'color-mix(in srgb, var(--color-input) 30%, transparent)', boxShadow: 'none' }}>
        <StyledInput data-slot="command-input" {...props} />
        <InputGroupAddon>
          <SearchIcon style={{ width: '1rem', height: '1rem', flexShrink: 0, opacity: 0.5 }} />
        </InputGroupAddon>
      </InputGroup>
    </InputWrapper>
  );
}

const StyledList = styled(CommandPrimitive.List)`
  max-height: 18rem;
  overflow-x: hidden;
  overflow-y: auto;
  scroll-padding: 0.25rem;
  outline: none;
  /* hide scrollbar */
  scrollbar-width: none;
  &::-webkit-scrollbar { display: none; }
`;

function CommandList({ ...props }: React.ComponentProps<typeof CommandPrimitive.List>) {
  return <StyledList data-slot="command-list" {...props} />;
}

const StyledEmpty = styled(CommandPrimitive.Empty)`
  padding: 1.5rem 0;
  text-align: center;
  font-size: 0.875rem;
`;

function CommandEmpty({ ...props }: React.ComponentProps<typeof CommandPrimitive.Empty>) {
  return <StyledEmpty data-slot="command-empty" {...props} />;
}

const StyledGroup = styled(CommandPrimitive.Group)`
  overflow: hidden;
  padding: 0.25rem;
  color: var(--color-foreground);
  & [cmdk-group-heading] {
    padding: 0.375rem 0.5rem;
    font-size: 0.75rem;
    font-weight: 500;
    color: var(--color-muted-foreground);
  }
`;

function CommandGroup({ ...props }: React.ComponentProps<typeof CommandPrimitive.Group>) {
  return <StyledGroup data-slot="command-group" {...props} />;
}

const StyledSeparator = styled(CommandPrimitive.Separator)`
  margin: 0 -0.25rem;
  height: 1px;
  background-color: var(--color-border);
`;

function CommandSeparator({ ...props }: React.ComponentProps<typeof CommandPrimitive.Separator>) {
  return <StyledSeparator data-slot="command-separator" {...props} />;
}

const StyledItem = styled(CommandPrimitive.Item)`
  position: relative;
  display: flex;
  cursor: default;
  align-items: center;
  gap: 0.5rem;
  border-radius: var(--radius-sm);
  padding: 0.375rem 0.5rem;
  font-size: 0.875rem;
  outline: none;
  user-select: none;
  color: var(--color-foreground);
  &[data-disabled="true"] { pointer-events: none; opacity: 0.5; }
  &[data-selected] { background-color: var(--color-muted); }
  & svg { pointer-events: none; flex-shrink: 0; }
  & svg:not([class*='size-']) { width: 1rem; height: 1rem; }
`;

const ShortcutCheck = styled(CheckIcon)`
  margin-left: auto;
  opacity: 0;
  [data-checked="true"] & { opacity: 1; }
`;

function CommandItem({ children, ...props }: React.ComponentProps<typeof CommandPrimitive.Item>) {
  return (
    <StyledItem data-slot="command-item" {...props}>
      {children}
      <ShortcutCheck style={{ width: '1rem', height: '1rem' }} />
    </StyledItem>
  );
}

const StyledShortcut = styled.span`
  margin-left: auto;
  font-size: 0.75rem;
  letter-spacing: 0.1em;
  color: var(--color-muted-foreground);
`;

function CommandShortcut({ ...props }: React.ComponentProps<'span'>) {
  return <StyledShortcut data-slot="command-shortcut" {...props} />;
}

export {
  Command,
  CommandDialog,
  CommandInput,
  CommandList,
  CommandEmpty,
  CommandGroup,
  CommandItem,
  CommandShortcut,
  CommandSeparator,
};
