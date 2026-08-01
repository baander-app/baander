import * as React from 'react';
import styled from 'styled-components';
import { Tooltip as TooltipPrimitive } from 'radix-ui';

function TooltipProvider({ delayDuration = 0, ...props }: React.ComponentProps<typeof TooltipPrimitive.Provider>) {
  return <TooltipPrimitive.Provider data-slot="tooltip-provider" delayDuration={delayDuration} {...props} />;
}

function Tooltip({ ...props }: React.ComponentProps<typeof TooltipPrimitive.Root>) {
  return <TooltipPrimitive.Root data-slot="tooltip" {...props} />;
}

function TooltipTrigger({ ...props }: React.ComponentProps<typeof TooltipPrimitive.Trigger>) {
  return <TooltipPrimitive.Trigger data-slot="tooltip-trigger" {...props} />;
}

const StyledTooltipContent = styled(TooltipPrimitive.Content)`
  z-index: 50;
  display: inline-flex;
  width: fit-content;
  max-width: 20rem;
  align-items: center;
  gap: 0.375rem;
  border-radius: var(--radius-md);
  background-color: var(--color-foreground);
  padding: 0.375rem 0.75rem;
  font-size: 0.75rem;
  color: var(--color-background);
  transform-origin: var(--radix-tooltip-content-transform-origin);
  &:has([data-slot="kbd"]) { padding-right: 0.375rem; }
  & [data-slot="kbd"] { position: relative; isolation: isolate; z-index: 50; border-radius: var(--radius-sm); }
  &[data-state="delayed-open"], &[data-open] { animation: fadeIn 150ms ease-out, zoomIn95 150ms ease-out; }
  &[data-state="closed"] { animation: fadeOut 150ms ease-out, zoomOut95 150ms ease-out; }
  &[data-side="bottom"] { animation: slideInFromTop 150ms ease-out; }
  &[data-side="left"] { animation: slideInFromRight 150ms ease-out; }
  &[data-side="right"] { animation: slideInFromLeft 150ms ease-out; }
  &[data-side="top"] { animation: slideInFromBottom 150ms ease-out; }
`;

const StyledArrow = styled(TooltipPrimitive.Arrow)`
  z-index: 50;
  width: 0.625rem;
  height: 0.625rem;
  transform: translateY(calc(-50% - 2px)) rotate(45deg);
  border-radius: 2px;
  background-color: var(--color-foreground);
  fill: var(--color-foreground);
`;

function TooltipContent({ sideOffset = 0, children, ...props }: React.ComponentProps<typeof TooltipPrimitive.Content>) {
  return (
    <TooltipPrimitive.Portal>
      <StyledTooltipContent data-slot="tooltip-content" sideOffset={sideOffset} {...props}>
        {children}
        <StyledArrow />
      </StyledTooltipContent>
    </TooltipPrimitive.Portal>
  );
}

export { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger };
