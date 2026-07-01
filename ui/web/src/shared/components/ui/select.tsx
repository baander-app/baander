import * as React from "react"
import { Select as SelectPrimitive } from "radix-ui"
import styled, { css, keyframes, type DataAttributes } from "styled-components"
import { focusVisibleRing, interactiveTransition, ariaInvalidRing, darkMode } from "@/shared/theme"

const Select = SelectPrimitive.Root
const SelectValue = SelectPrimitive.Value

const contentFadeIn = keyframes`
  from { opacity: 0; }
  to { opacity: 1; }
`

const contentFadeOut = keyframes`
  from { opacity: 1; }
  to { opacity: 0; }
`

const contentZoomIn = keyframes`
  from { transform: scale(0.95); }
  to { transform: scale(1); }
`

const contentZoomOut = keyframes`
  from { transform: scale(1); }
  to { transform: scale(0.95); }
`

const slideInFromTop = keyframes`
  from { transform: translateY(-0.5rem); }
  to { transform: translateY(0); }
`

const slideInFromBottom = keyframes`
  from { transform: translateY(0.5rem); }
  to { transform: translateY(0); }
`

const slideInFromLeft = keyframes`
  from { transform: translateX(-0.5rem); }
  to { transform: translateX(0); }
`

const slideInFromRight = keyframes`
  from { transform: translateX(0.5rem); }
  to { transform: translateX(0); }
`

const svgStyles = css`
  & svg {
    pointer-events: none;
    flex-shrink: 0;
  }
  & svg:not([class*='size-']) {
    width: 1rem;
    height: 1rem;
  }
`

const StyledTrigger = styled(SelectPrimitive.Trigger).attrs<DataAttributes>({ 'data-slot': 'select-trigger' })`
  display: flex;
  height: 2rem;
  width: 100%;
  align-items: center;
  justify-content: space-between;
  border-radius: ${({ theme }) => theme.radii.lg};
  border: 1px solid var(--color-input);
  background: transparent;
  padding: 0.375rem 0.625rem;
  font-size: 0.875rem;
  outline: none;
  ${interactiveTransition(['color', 'border-color', 'background-color', 'box-shadow'])}

  &::placeholder {
    color: var(--color-muted-foreground);
  }

  ${focusVisibleRing}

  &:disabled {
    cursor: not-allowed;
    background: color-mix(in srgb, var(--color-input) 50%, transparent);
    opacity: 0.5;
  }

  ${ariaInvalidRing}

  ${darkMode(`
    background: color-mix(in srgb, var(--color-input) 30%, transparent);
    &:disabled {
      background: color-mix(in srgb, var(--color-input) 80%, transparent);
    }
  `)}

  ${svgStyles}
`

const SelectTrigger = React.forwardRef<
  React.ComponentRef<typeof SelectPrimitive.Trigger>,
  React.ComponentProps<typeof SelectPrimitive.Trigger>
>((props, ref) => (
  <StyledTrigger ref={ref} {...props}>
    {props.children}
    <SelectPrimitive.Icon asChild>
      <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" style={{ opacity: 0.5 }}><path d="m6 9 6 6 6-6"/></svg>
    </SelectPrimitive.Icon>
  </StyledTrigger>
))

const popperOffsets = css`
  &[data-side="bottom"] { transform: translateY(0.25rem); }
  &[data-side="left"] { transform: translateX(-0.25rem); }
  &[data-side="right"] { transform: translateX(0.25rem); }
  &[data-side="top"] { transform: translateY(-0.25rem); }
`

const StyledContent = styled(SelectPrimitive.Content).attrs<DataAttributes>({ 'data-slot': 'select-content' })<{
  $position?: "popper" | "item-aligned"
}>`
  position: relative;
  z-index: 50;
  max-height: 24rem;
  min-width: 8rem;
  overflow: hidden;
  border-radius: ${({ theme }) => theme.radii.lg};
  background: var(--color-popover);
  color: var(--color-popover-foreground);
  box-shadow: 0 4px 6px -1px rgb(0 0 0 / 0.1), 0 2px 4px -2px rgb(0 0 0 / 0.1);
  ring: 1px solid color-mix(in srgb, var(--color-foreground) 10%, transparent);
  outline: 1px solid color-mix(in srgb, var(--color-foreground) 10%, transparent);
  transform-origin: var(--radix-select-content-transform-origin);
  transition: all 100ms;

  &[data-state="open"] {
    animation:
      ${contentFadeIn} 150ms ease-out,
      ${contentZoomIn} 150ms ease-out;
  }

  &[data-state="closed"] {
    animation:
      ${contentFadeOut} 150ms ease-out,
      ${contentZoomOut} 150ms ease-out;
  }

  &[data-side="bottom"] { animation: ${slideInFromTop} 150ms ease-out; }
  &[data-side="left"] { animation: ${slideInFromRight} 150ms ease-out; }
  &[data-side="right"] { animation: ${slideInFromLeft} 150ms ease-out; }
  &[data-side="top"] { animation: ${slideInFromBottom} 150ms ease-out; }

  ${(p) => p.$position === "popper" && popperOffsets}
`

const StyledViewport = styled(SelectPrimitive.Viewport)<{
  $position?: "popper" | "item-aligned"
}>`
  padding: 0.25rem;
  ${(p) =>
    p.$position === "popper" &&
    css`
      height: var(--radix-select-trigger-height);
      width: 100%;
      min-width: var(--radix-select-trigger-width);
    `}
`

const SelectContent = React.forwardRef<
  React.ComponentRef<typeof SelectPrimitive.Content>,
  React.ComponentProps<typeof SelectPrimitive.Content>
>(({ position = "popper", children, ...props }, ref) => (
  <SelectPrimitive.Portal>
    <StyledContent ref={ref} $position={position} position={position} {...props}>
      <StyledViewport $position={position}>
        {children}
      </StyledViewport>
    </StyledContent>
  </SelectPrimitive.Portal>
))

const StyledItem = styled(SelectPrimitive.Item).attrs<DataAttributes>({ 'data-slot': 'select-item' })`
  position: relative;
  display: flex;
  width: 100%;
  cursor: default;
  user-select: none;
  align-items: center;
  border-radius: ${({ theme }) => theme.radii.md};
  padding: 0.375rem 0.5rem 0.375rem 2rem;
  font-size: 0.875rem;
  outline: none;

  &:focus {
    background: var(--color-accent);
    color: var(--color-accent-foreground);
  }

  &[data-disabled] {
    pointer-events: none;
    opacity: 0.5;
  }

  ${svgStyles}
`

const ItemIndicatorWrapper = styled.span`
  position: absolute;
  left: 0.5rem;
  display: flex;
  height: 1.375rem;
  width: 1.375rem;
  align-items: center;
  justify-content: center;
`

const SelectItem = React.forwardRef<
  React.ComponentRef<typeof SelectPrimitive.Item>,
  React.ComponentProps<typeof SelectPrimitive.Item>
>(({ children, ...props }, ref) => (
  <StyledItem ref={ref} {...props}>
    <ItemIndicatorWrapper>
      <SelectPrimitive.ItemIndicator>
        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
      </SelectPrimitive.ItemIndicator>
    </ItemIndicatorWrapper>
    <SelectPrimitive.ItemText>{children}</SelectPrimitive.ItemText>
  </StyledItem>
))

export { Select, SelectTrigger, SelectValue, SelectContent, SelectItem }
