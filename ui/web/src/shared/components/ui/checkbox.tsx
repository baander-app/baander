import * as React from 'react';
import styled from 'styled-components';
import { Checkbox as CheckboxPrimitive } from 'radix-ui';
import { Check } from 'lucide-react';
import { focusVisibleRing, interactiveTransition } from '@/shared/theme';

const StyledCheckbox = styled(CheckboxPrimitive.Root)`
  display: inline-flex;
  width: 1rem;
  height: 1rem;
  flex-shrink: 0;
  align-items: center;
  justify-content: center;
  padding: 0;
  cursor: pointer;
  border-radius: var(--radius-sm);
  border: 1px solid var(--color-input-boundary);
  background-color: transparent;
  color: var(--color-primary-foreground);
  ${interactiveTransition(['background-color', 'border-color'])}
  ${focusVisibleRing}
  &:disabled { cursor: not-allowed; opacity: 0.5; }
  &[data-state="checked"] { border-color: var(--color-primary); background-color: var(--color-primary); }
`;

const StyledIndicator = styled(CheckboxPrimitive.Indicator)`
  display: flex;
  align-items: center;
  justify-content: center;
  & svg { width: 0.75rem; height: 0.75rem; }
`;

function Checkbox({ ...props }: React.ComponentProps<typeof CheckboxPrimitive.Root>) {
  return (
    <StyledCheckbox data-slot="checkbox" {...props}>
      <StyledIndicator data-slot="checkbox-indicator">
        <Check strokeWidth={3} aria-hidden />
      </StyledIndicator>
    </StyledCheckbox>
  );
}

export { Checkbox };
