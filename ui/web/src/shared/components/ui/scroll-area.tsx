import * as React from 'react';
import styled from 'styled-components';
import { ScrollArea as ScrollAreaPrimitive } from 'radix-ui';
import { focusVisibleRing, interactiveTransition } from '@/shared/theme';

const StyledRoot = styled(ScrollAreaPrimitive.Root)`
  position: relative;
`;

const StyledViewport = styled(ScrollAreaPrimitive.Viewport)`
  width: 100%;
  height: 100%;
  border-radius: inherit;
  ${interactiveTransition(['color', 'box-shadow'])}
  outline: none;
  ${focusVisibleRing}
`;

function ScrollArea({ children, ...props }: React.ComponentProps<typeof ScrollAreaPrimitive.Root>) {
  return (
    <StyledRoot data-slot="scroll-area" {...props}>
      <StyledViewport data-slot="scroll-area-viewport">
        {children}
      </StyledViewport>
      <ScrollBar />
      <ScrollAreaPrimitive.Corner />
    </StyledRoot>
  );
}

const StyledScrollbar = styled(ScrollAreaPrimitive.ScrollAreaScrollbar)`
  display: flex;
  touch-action: none;
  padding: 1px;
  ${interactiveTransition(['background-color'])}
  user-select: none;
  &[data-orientation="horizontal"] { height: 0.625rem; flex-direction: column; border-top: 1px solid transparent; }
  &[data-orientation="vertical"] { height: 100%; width: 0.625rem; border-left: 1px solid transparent; }
`;

const StyledThumb = styled(ScrollAreaPrimitive.ScrollAreaThumb)`
  position: relative;
  flex: 1 1 0;
  border-radius: 9999px;
  background-color: var(--color-border);
`;

function ScrollBar({ orientation = 'vertical', ...props }: React.ComponentProps<typeof ScrollAreaPrimitive.ScrollAreaScrollbar>) {
  return (
    <StyledScrollbar data-slot="scroll-area-scrollbar" data-orientation={orientation} orientation={orientation} {...props}>
      <StyledThumb data-slot="scroll-area-thumb" />
    </StyledScrollbar>
  );
}

export { ScrollArea, ScrollBar };
