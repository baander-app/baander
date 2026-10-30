import * as React from "react"
import { Tabs as TabsPrimitive } from "radix-ui"
import styled, { css, type DataAttributes } from "styled-components"
import { focusVisibleRing, interactiveTransition } from "@/shared/theme"

const StyledTabs = styled(TabsPrimitive.Root).attrs<DataAttributes>((p) => ({
  'data-slot': 'tabs',
  'data-orientation': p.orientation ?? 'horizontal',
}))`
  display: flex;
  gap: 0.5rem;

  &[data-orientation="horizontal"] {
    flex-direction: column;
  }
`

const Tabs = React.forwardRef<
  React.ComponentRef<typeof TabsPrimitive.Root>,
  React.ComponentProps<typeof TabsPrimitive.Root>
>(({ orientation = "horizontal", ...props }, ref) => (
  <StyledTabs ref={ref} orientation={orientation} {...props} />
))

const tabsListBase = css`
  display: inline-flex;
  width: fit-content;
  max-width: 100%;
  overflow-x: auto;
  align-items: center;
  justify-content: flex-start;
  border-radius: ${({ theme }) => theme.radii.md};
  padding: 3px;
  color: var(--color-muted-foreground);
`

const tabsListGroupContext = css`
  /* Horizontal layout: height 8 when parent tabs is horizontal */
  height: 2rem;

  /* Vertical layout: full-height column when parent tabs is vertical */
  ${StyledTabs}[data-orientation="vertical"] & {
    height: fit-content;
    flex-direction: column;
  }

  /* Line variant: no rounding */
  &[data-variant="line"] {
    border-radius: 0;
  }
`

const tabsListDefault = css`
  background: var(--color-muted);
`

const tabsListLine = css`
  gap: 0.25rem;
  background: transparent;
`

const StyledTabsList = styled(TabsPrimitive.List).attrs<DataAttributes & { $variant?: "default" | "line" }>((p) => ({
  'data-slot': 'tabs-list',
  'data-variant': p.$variant ?? 'default',
}))<{ $variant?: "default" | "line" }>`
  ${tabsListBase}
  ${tabsListGroupContext}

  ${(p) => {
    switch (p.$variant) {
      case "line":
        return tabsListLine
      default:
        return tabsListDefault
    }
  }}
`

const TabsList = React.forwardRef<
  React.ComponentRef<typeof TabsPrimitive.List>,
  React.ComponentProps<typeof TabsPrimitive.List> & {
    variant?: "default" | "line"
  }
>(({ variant = "default", ...props }, ref) => (
  <StyledTabsList ref={ref} $variant={variant} {...props} />
))

const StyledTabsTrigger = styled(TabsPrimitive.Trigger).attrs<DataAttributes>({ 'data-slot': 'tabs-trigger' })`
  position: relative;
  display: inline-flex;
  height: calc(100% - 1px);
  flex: 1;
  align-items: center;
  justify-content: center;
  gap: 0.375rem;
  border-radius: ${({ theme }) => theme.radii.md};
  border: 1px solid transparent;
  background: transparent;
  padding: 0.125rem 0.375rem;
  font-size: 0.875rem;
  font-weight: 500;
  white-space: nowrap;
  color: var(--color-muted-foreground);
  outline: none;
  ${interactiveTransition(['color', 'background-color', 'border-color', 'opacity'])}

  &:hover {
    color: var(--color-foreground);
  }

  ${focusVisibleRing}
  &:focus-visible { outline-offset: -2px; }

  &:disabled {
    pointer-events: none;
    opacity: 0.5;
  }

  /* Vertical tabs: triggers fill width, left-aligned */
  ${StyledTabs}[data-orientation="vertical"] & {
    width: 100%;
    justify-content: flex-start;
  }

  /* Active state within default variant tabs-list */
  &[data-state="active"] {
    transition: none;
    background: var(--color-background);
    color: var(--color-foreground);
  }

  /* Active state within line variant list: no shadow */
  ${StyledTabsList}[data-variant="line"] &[data-state="active"] {
    box-shadow: none;
    background: transparent;
  }

  /* After pseudo-element: the underline/indicator bar */
  &::after {
    content: '';
    position: absolute;
    background: var(--color-foreground);
    opacity: 0;
    transition: none;
  }

  /* Horizontal indicator: bottom bar */
  ${StyledTabs}[data-orientation="horizontal"] &::after {
    left: 0;
    right: 0;
    bottom: 0;
    height: 2px;
  }

  /* Vertical indicator: right bar */
  ${StyledTabs}[data-orientation="vertical"] &::after {
    top: 0;
    bottom: 0;
    right: 0;
    width: 2px;
  }

  /* Line variant: show indicator when active */
  ${StyledTabsList}[data-variant="line"] &[data-state="active"]::after {
    opacity: 1;
  }

  & svg {
    pointer-events: none;
    flex-shrink: 0;
  }
  & svg:not([class*='size-']) {
    width: 1rem;
    height: 1rem;
  }
`

const TabsTrigger = React.forwardRef<
  React.ComponentRef<typeof TabsPrimitive.Trigger>,
  React.ComponentProps<typeof TabsPrimitive.Trigger>
>((props, ref) => (
  <StyledTabsTrigger ref={ref} {...props} />
))

const StyledTabsContent = styled(TabsPrimitive.Content).attrs<DataAttributes>({ 'data-slot': 'tabs-content' })`
  flex: 1;
  font-size: 0.875rem;
  outline: none;
`

const TabsContent = React.forwardRef<
  React.ComponentRef<typeof TabsPrimitive.Content>,
  React.ComponentProps<typeof TabsPrimitive.Content>
>((props, ref) => (
  <StyledTabsContent ref={ref} {...props} />
))

export { Tabs, TabsList, TabsTrigger, TabsContent }
