import * as React from 'react';
import styled from 'styled-components';
import { Switch as SwitchPrimitive } from 'radix-ui';

const StyledSwitch = styled(SwitchPrimitive.Root)`
  display: inline-flex;
  height: 1.25rem;
  width: 2.25rem;
  flex-shrink: 0;
  cursor: pointer;
  align-items: center;
  border-radius: 9999px;
  border: 2px solid transparent;
  transition: background-color 60ms ease-out;
  &:focus-visible { outline: none; box-shadow: 0 0 0 2px var(--color-ring), 0 0 0 4px var(--color-background); }
  &:disabled { cursor: not-allowed; opacity: 0.5; }
  &[data-state="checked"] { background-color: var(--color-primary); }
  &[data-state="unchecked"] { background-color: var(--color-input); }
`;

const StyledThumb = styled(SwitchPrimitive.Thumb)`
  display: block;
  height: 1rem;
  width: 1rem;
  border-radius: 9999px;
  background-color: var(--color-background);
  box-shadow: 0 2px 4px rgba(0, 0, 0, 0.2);
  pointer-events: none;
  transition: transform 60ms ease-out;
  &[data-state="checked"] { transform: translateX(1rem); }
  &[data-state="unchecked"] { transform: translateX(0); }
`;

function Switch({ ...props }: React.ComponentProps<typeof SwitchPrimitive.Root>) {
  return (
    <StyledSwitch data-slot="switch" {...props}>
      <StyledThumb />
    </StyledSwitch>
  );
}

export { Switch };
