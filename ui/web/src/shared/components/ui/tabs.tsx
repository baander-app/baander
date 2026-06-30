import * as React from "react"
import { Tabs as TabsPrimitive } from "radix-ui"
import styled, { css } from "styled-components"
import { focusVisibleRing, interactiveTransition, disabledStyle, darkMode } from "@/shared/theme"

const StyledTabs = styled(TabsPrimitive.Root).attrs((p) => ({
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
  align-items: center;
  justify-content: center;
  border-radius: ${({ theme }) => theme.radii.lg};
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

const StyledTabsList = styled(TabsPrimitive.List).attrs((p) => ({
  'data-slot': 'tabs-list',
  'data-variant': (p as any).$variant ?? 'default',
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

const StyledTabsTrigger = styled(TabsPrimitive.Trigger).attrs({ 'data-slot': 'tabs-trigger' })`
  position: relative;
  display: inline-flex;
  height: calc(100% - 1px);
  flex: 1;
  align-items: center;
  justify-content: center;
  gap: 0.375rem;
  border-radius: ${({ theme }) => theme.radii.md};
  border: 1px solid transparent;
  padding: 0.125rem 0.375rem;
  font-size: 0.875rem;
  font-weight: 500;
  white-space: nowrap;
  color: color-mix(in srgb, var(--color-foreground) 60%, transparent);
  outline: none;
  ${interactiveTransition(['color', 'background-color', 'border-color', 'opacity'])}

  &:hover {
    color: var(--color-foreground);
  }

  ${focusVisibleRing}

  &:disabled {
    pointer-events: none;
    opacity: 0.5;
  }

  ${darkMode(`
    color: var(--color-muted-foreground);
    &:hover {
      color: var(--color-foreground);
    }
  `)}

  /* Vertical tabs: triggers fill width, left-aligned */
  ${StyledTabs}[data-orientation="vertical"] & {
    width: 100%;
    justify-content: flex-start;
  }

  /* Active state within default variant tabs-list */
  &[data-active] {
    background: var(--color-background);
    color: var(--color-foreground);
  }

  /* Active state within default variant list: shadow */
  ${StyledTabsList}[data-variant="default"] &[data-active] {
    box-shadow: 0 1px 2px 0 rgb(0 0 0 / 0.05);
  }

  /* Active state within line variant list: no shadow */
  ${StyledTabsList}[data-variant="line"] &[data-active] {
    box-shadow: none;
    background: transparent;
  }

  ${darkMode(`
    /* Dark mode active overrides */
    &[data-active] {
      border-color: var(--color-input);
      background: color-mix(in srgb, var(--color-input) 30%, transparent);
      color: var(--color-foreground);
    }

    ${StyledTabsList}[data-variant="line"] &[data-active] {
      border-color: transparent;
      background: transparent;
    }
  `)}

  /* After pseudo-element: the underline/indicator bar */
  &::after {
    content: '';
    position: absolute;
    background: var(--color-foreground);
    opacity: 0;
    transition: opacity var(--duration-hover) ease-out;
  }

  /* Horizontal indicator: bottom bar */
  ${StyledTabs}[data-orientation="horizontal"] &::after {
    left: 0;
    right: 0;
    bottom: -5px;
    height: 2px;
  }

  /* Vertical indicator: right bar */
  ${StyledTabs}[data-orientation="vertical"] &::after {
    top: 0;
    bottom: 0;
    right: -0.25rem;
    width: 2px;
  }

  /* Line variant: show indicator when active */
  ${StyledTabsList}[data-variant="line"] &[data-active]::after {
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

const StyledTabsContent = styled(TabsPrimitive.Content).attrs({ 'data-slot': 'tabs-content' })`
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

// Deprecated: variant styling is now handled by styled-components
const tabsListVariants = (_opts?: { variant?: 'default' | 'line'; className?: string }) => ''

export { Tabs, TabsList, TabsTrigger, TabsContent, tabsListVariants }
