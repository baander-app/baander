import * as React from "react"
import { ContextMenu as ContextMenuPrimitive } from "radix-ui"
import styled, { css } from "styled-components"
import { focusVisibleRing, darkMode } from "@/shared/theme"
import { ChevronRightIcon, CheckIcon } from "lucide-react"

/* ── Shared menu animation mixin ─────────────────────────────────────── */
const menuAnimation = css`
  animation-duration: 100ms;
  animation-timing-function: ease;

  &[data-state="open"] {
    animation-name: fadeIn, zoomIn95;
  }
  &[data-state="closed"] {
    animation-name: fadeOut, zoomOut95;
  }
  &[data-side="bottom"] {
    &[data-state="open"] { animation-name: fadeIn, zoomIn95, slideInFromTop; }
  }
  &[data-side="top"] {
    &[data-state="open"] { animation-name: fadeIn, zoomIn95, slideInFromBottom; }
  }
  &[data-side="left"] {
    &[data-state="open"] { animation-name: fadeIn, zoomIn95, slideInFromRight; }
  }
  &[data-side="right"] {
    &[data-state="open"] { animation-name: fadeIn, zoomIn95, slideInFromLeft; }
  }
`

const subMenuAnimation = css`
  animation-duration: 100ms;
  animation-timing-function: ease;

  &[data-state="open"] {
    animation-name: fadeIn, zoomIn95;
  }
  &[data-state="closed"] {
    animation-name: fadeOut, zoomOut95;
  }
  &[data-side="bottom"] {
    &[data-state="open"] { animation-name: fadeIn, zoomIn95, slideInFromTop; }
  }
  &[data-side="top"] {
    &[data-state="open"] { animation-name: fadeIn, zoomIn95, slideInFromBottom; }
  }
  &[data-side="left"] {
    &[data-state="open"] { animation-name: fadeIn, zoomIn95, slideInFromRight; }
  }
  &[data-side="right"] {
    &[data-state="open"] { animation-name: fadeIn, zoomIn95, slideInFromLeft; }
  }
`

const svgDefaults = css`
  & svg {
    pointer-events: none;
    flex-shrink: 0;
  }
  & svg:not([class*="size-"]) {
    width: 1rem;
    height: 1rem;
  }
`

const disabledStyles = css`
  &[data-disabled] {
    pointer-events: none;
    opacity: 0.5;
  }
`

/* ── Styled primitives ───────────────────────────────────────────────── */

const ContentStyled = styled(ContextMenuPrimitive.Content).attrs({
  "data-slot": "context-menu-content",
})`
  z-index: 50;
  max-height: var(--radix-context-menu-content-available-height);
  min-width: 9rem;
  transform-origin: var(--radix-context-menu-content-transform-origin);
  overflow-x: hidden;
  overflow-y: auto;
  border-radius: var(--radius-lg);
  background-color: var(--color-popover);
  padding: 0.25rem;
  color: var(--color-popover-foreground);
  box-shadow: 0 4px 6px -1px rgb(0 0 0 / 0.1), 0 2px 4px -2px rgb(0 0 0 / 0.1);
  outline: none;
  ring: 1px;
  box-shadow: 0 0 0 1px color-mix(in srgb, var(--color-foreground) 10%, transparent);

  ${menuAnimation}
`

const ItemStyled = styled(ContextMenuPrimitive.Item).attrs<{
  inset?: boolean
  variant?: "default" | "destructive"
}>((p) => ({
  "data-slot": "context-menu-item",
  "data-inset": p.inset || undefined,
  "data-variant": p.variant || "default",
}))`
  position: relative;
  display: flex;
  cursor: default;
  align-items: center;
  gap: 0.375rem;
  border-radius: var(--radius-md);
  padding: 0.25rem 0.375rem;
  font-size: 0.875rem;
  line-height: 1.25rem;
  outline: none;
  user-select: none;

  &:focus {
    background-color: var(--color-accent);
    color: var(--color-accent-foreground);
  }

  &:focus svg {
    color: var(--color-accent-foreground);
  }

  &[data-inset] {
    padding-left: 1.75rem;
  }

  &[data-variant="destructive"] {
    color: var(--color-destructive);
  }
  &[data-variant="destructive"]:focus {
    background-color: color-mix(in srgb, var(--color-destructive) 10%, transparent);
    color: var(--color-destructive);
  }
  ${darkMode(css`
    &[data-variant="destructive"]:focus {
      background-color: color-mix(in srgb, var(--color-destructive) 20%, transparent);
    }
  `)}
  &[data-variant="destructive"] svg {
    color: var(--color-destructive);
  }

  ${disabledStyles}
  ${svgDefaults}
`

const SubTriggerStyled = styled(ContextMenuPrimitive.SubTrigger).attrs<{
  inset?: boolean
}>((p) => ({
  "data-slot": "context-menu-sub-trigger",
  "data-inset": p.inset || undefined,
}))`
  display: flex;
  cursor: default;
  align-items: center;
  gap: 0.375rem;
  border-radius: var(--radius-md);
  padding: 0.25rem 0.375rem;
  font-size: 0.875rem;
  line-height: 1.25rem;
  outline: none;
  user-select: none;

  &:focus {
    background-color: var(--color-accent);
    color: var(--color-accent-foreground);
  }

  &[data-inset] {
    padding-left: 1.75rem;
  }

  &[data-state="open"] {
    background-color: var(--color-accent);
    color: var(--color-accent-foreground);
  }

  ${svgDefaults}
`

const SubContentStyled = styled(ContextMenuPrimitive.SubContent).attrs({
  "data-slot": "context-menu-sub-content",
})`
  z-index: 50;
  min-width: 8rem;
  transform-origin: var(--radix-context-menu-content-transform-origin);
  overflow: hidden;
  border-radius: var(--radius-lg);
  border: 1px solid var(--color-border);
  background-color: var(--color-popover);
  padding: 0.25rem;
  color: var(--color-popover-foreground);
  box-shadow: 0 10px 15px -3px rgb(0 0 0 / 0.1), 0 4px 6px -4px rgb(0 0 0 / 0.1);

  ${subMenuAnimation}
`

const CheckboxItemStyled = styled(ContextMenuPrimitive.CheckboxItem).attrs<{
  inset?: boolean
}>((p) => ({
  "data-slot": "context-menu-checkbox-item",
  "data-inset": p.inset || undefined,
}))`
  position: relative;
  display: flex;
  cursor: default;
  align-items: center;
  gap: 0.375rem;
  border-radius: var(--radius-md);
  padding: 0.25rem 2rem 0.25rem 0.375rem;
  font-size: 0.875rem;
  line-height: 1.25rem;
  outline: none;
  user-select: none;

  &:focus {
    background-color: var(--color-accent);
    color: var(--color-accent-foreground);
  }

  &[data-inset] {
    padding-left: 1.75rem;
  }

  ${disabledStyles}
  ${svgDefaults}
`

const RadioItemStyled = styled(ContextMenuPrimitive.RadioItem).attrs<{
  inset?: boolean
}>((p) => ({
  "data-slot": "context-menu-radio-item",
  "data-inset": p.inset || undefined,
}))`
  position: relative;
  display: flex;
  cursor: default;
  align-items: center;
  gap: 0.375rem;
  border-radius: var(--radius-md);
  padding: 0.25rem 2rem 0.25rem 0.375rem;
  font-size: 0.875rem;
  line-height: 1.25rem;
  outline: none;
  user-select: none;

  &:focus {
    background-color: var(--color-accent);
    color: var(--color-accent-foreground);
  }

  &[data-inset] {
    padding-left: 1.75rem;
  }

  ${disabledStyles}
  ${svgDefaults}
`

const IndicatorSpan = styled.span`
  position: absolute;
  right: 0.5rem;
  top: 50%;
  transform: translateY(-50%);
  display: flex;
  align-items: center;
  justify-content: center;
  pointer-events: none;
`

const LabelStyled = styled(ContextMenuPrimitive.Label).attrs<{
  inset?: boolean
}>((p) => ({
  "data-slot": "context-menu-label",
  "data-inset": p.inset || undefined,
}))`
  padding: 0.25rem 0.375rem;
  font-size: 0.75rem;
  font-weight: 500;
  color: var(--color-muted-foreground);

  &[data-inset] {
    padding-left: 1.75rem;
  }
`

const SeparatorStyled = styled(ContextMenuPrimitive.Separator).attrs({
  "data-slot": "context-menu-separator",
})`
  margin: 0.25rem -0.25rem;
  height: 1px;
  background-color: var(--color-border);
`

const ShortcutStyled = styled.span.attrs({
  "data-slot": "context-menu-shortcut",
})`
  margin-left: auto;
  font-size: 0.75rem;
  letter-spacing: 0.1em;
  color: var(--color-muted-foreground);
`

const ChevronIcon = styled(ChevronRightIcon)`
  margin-left: auto;
`

/* ── Components ──────────────────────────────────────────────────────── */

function ContextMenu({
  ...props
}: React.ComponentProps<typeof ContextMenuPrimitive.Root>) {
  return <ContextMenuPrimitive.Root data-slot="context-menu" {...props} />
}

function ContextMenuTrigger({
  ...props
}: React.ComponentProps<typeof ContextMenuPrimitive.Trigger>) {
  return (
    <ContextMenuPrimitive.Trigger
      data-slot="context-menu-trigger"
      style={{ userSelect: "none" }}
      {...props}
    />
  )
}

function ContextMenuGroup({
  ...props
}: React.ComponentProps<typeof ContextMenuPrimitive.Group>) {
  return (
    <ContextMenuPrimitive.Group data-slot="context-menu-group" {...props} />
  )
}

function ContextMenuPortal({
  ...props
}: React.ComponentProps<typeof ContextMenuPrimitive.Portal>) {
  return (
    <ContextMenuPrimitive.Portal data-slot="context-menu-portal" {...props} />
  )
}

function ContextMenuSub({
  ...props
}: React.ComponentProps<typeof ContextMenuPrimitive.Sub>) {
  return <ContextMenuPrimitive.Sub data-slot="context-menu-sub" {...props} />
}

function ContextMenuRadioGroup({
  ...props
}: React.ComponentProps<typeof ContextMenuPrimitive.RadioGroup>) {
  return (
    <ContextMenuPrimitive.RadioGroup
      data-slot="context-menu-radio-group"
      {...props}
    />
  )
}

function ContextMenuContent({
  ...props
}: React.ComponentProps<typeof ContextMenuPrimitive.Content> & {
  side?: "top" | "right" | "bottom" | "left"
}) {
  return (
    <ContextMenuPrimitive.Portal>
      <ContentStyled {...props} />
    </ContextMenuPrimitive.Portal>
  )
}

function ContextMenuItem({
  inset,
  variant = "default",
  ...props
}: React.ComponentProps<typeof ContextMenuPrimitive.Item> & {
  inset?: boolean
  variant?: "default" | "destructive"
}) {
  return (
    <ItemStyled inset={inset} variant={variant} {...props} />
  )
}

function ContextMenuSubTrigger({
  inset,
  children,
  ...props
}: React.ComponentProps<typeof ContextMenuPrimitive.SubTrigger> & {
  inset?: boolean
}) {
  return (
    <SubTriggerStyled inset={inset} {...props}>
      {children}
      <ChevronIcon />
    </SubTriggerStyled>
  )
}

function ContextMenuSubContent({
  ...props
}: React.ComponentProps<typeof ContextMenuPrimitive.SubContent>) {
  return <SubContentStyled {...props} />
}

function ContextMenuCheckboxItem({
  children,
  checked,
  inset,
  ...props
}: React.ComponentProps<typeof ContextMenuPrimitive.CheckboxItem> & {
  inset?: boolean
}) {
  return (
    <CheckboxItemStyled inset={inset} checked={checked} {...props}>
      <IndicatorSpan>
        <ContextMenuPrimitive.ItemIndicator>
          <CheckIcon />
        </ContextMenuPrimitive.ItemIndicator>
      </IndicatorSpan>
      {children}
    </CheckboxItemStyled>
  )
}

function ContextMenuRadioItem({
  children,
  inset,
  ...props
}: React.ComponentProps<typeof ContextMenuPrimitive.RadioItem> & {
  inset?: boolean
}) {
  return (
    <RadioItemStyled inset={inset} {...props}>
      <IndicatorSpan>
        <ContextMenuPrimitive.ItemIndicator>
          <CheckIcon />
        </ContextMenuPrimitive.ItemIndicator>
      </IndicatorSpan>
      {children}
    </RadioItemStyled>
  )
}

function ContextMenuLabel({
  inset,
  ...props
}: React.ComponentProps<typeof ContextMenuPrimitive.Label> & {
  inset?: boolean
}) {
  return <LabelStyled inset={inset} {...props} />
}

function ContextMenuSeparator({
  ...props
}: React.ComponentProps<typeof ContextMenuPrimitive.Separator>) {
  return <SeparatorStyled {...props} />
}

function ContextMenuShortcut({
  ...props
}: React.ComponentProps<"span">) {
  return <ShortcutStyled {...props} />
}

export {
  ContextMenu,
  ContextMenuTrigger,
  ContextMenuContent,
  ContextMenuItem,
  ContextMenuCheckboxItem,
  ContextMenuRadioItem,
  ContextMenuLabel,
  ContextMenuSeparator,
  ContextMenuShortcut,
  ContextMenuGroup,
  ContextMenuPortal,
  ContextMenuSub,
  ContextMenuSubContent,
  ContextMenuSubTrigger,
  ContextMenuRadioGroup,
}
