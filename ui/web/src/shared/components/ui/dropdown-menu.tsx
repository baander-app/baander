import * as React from "react"
import { DropdownMenu as DropdownMenuPrimitive } from "radix-ui"
import styled, { css, type DataAttributes } from "styled-components"
import { darkMode } from "@/shared/theme"
import { CheckIcon, ChevronRightIcon } from "lucide-react"

/* ── Shared menu animation mixin ─────────────────────────────────────── */
const menuAnimation = css`
  animation-duration: 100ms;
  animation-timing-function: ease;

  &[data-state="open"] {
    animation-name: fadeIn, zoomIn95;
  }
  &[data-state="closed"] {
    animation-name: fadeOut, zoomOut95;
    overflow: hidden;
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

const ContentStyled = styled(DropdownMenuPrimitive.Content).attrs<DataAttributes>({
  "data-slot": "dropdown-menu-content",
})`
  z-index: 50;
  max-height: var(--radix-dropdown-menu-content-available-height);
  width: var(--radix-dropdown-menu-trigger-width);
  min-width: 8rem;
  transform-origin: var(--radix-dropdown-menu-content-transform-origin);
  overflow-x: hidden;
  overflow-y: auto;
  border-radius: var(--radius-lg);
  background-color: var(--color-popover);
  padding: 0.25rem;
  color: var(--color-popover-foreground);
  box-shadow: 0 4px 6px -1px rgb(0 0 0 / 0.1), 0 2px 4px -2px rgb(0 0 0 / 0.1);
  outline: none;
  box-shadow: 0 0 0 1px color-mix(in srgb, var(--color-foreground) 10%, transparent),
    0 4px 6px -1px rgb(0 0 0 / 0.1),
    0 2px 4px -2px rgb(0 0 0 / 0.1);

  ${menuAnimation}
`

const ItemStyled = styled(DropdownMenuPrimitive.Item).attrs<DataAttributes & {
  inset?: boolean
  variant?: "default" | "destructive"
}>((p) => ({
  "data-slot": "dropdown-menu-item",
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

  &:not([data-variant="destructive"]):focus * {
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

const SubTriggerStyled = styled(DropdownMenuPrimitive.SubTrigger).attrs<DataAttributes & {
  inset?: boolean
}>((p) => ({
  "data-slot": "dropdown-menu-sub-trigger",
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

  &:not([data-variant="destructive"]):focus * {
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

const SubContentStyled = styled(DropdownMenuPrimitive.SubContent).attrs<DataAttributes>({
  "data-slot": "dropdown-menu-sub-content",
})`
  z-index: 50;
  min-width: 96px;
  transform-origin: var(--radix-dropdown-menu-content-transform-origin);
  overflow: hidden;
  border-radius: var(--radius-lg);
  background-color: var(--color-popover);
  padding: 0.25rem;
  color: var(--color-popover-foreground);
  box-shadow: 0 10px 15px -3px rgb(0 0 0 / 0.1), 0 4px 6px -4px rgb(0 0 0 / 0.1);
  outline: none;
  box-shadow: 0 0 0 1px color-mix(in srgb, var(--color-foreground) 10%, transparent),
    0 10px 15px -3px rgb(0 0 0 / 0.1),
    0 4px 6px -4px rgb(0 0 0 / 0.1);

  ${subMenuAnimation}
`

const CheckboxItemStyled = styled(DropdownMenuPrimitive.CheckboxItem).attrs<DataAttributes & {
  inset?: boolean
}>((p) => ({
  "data-slot": "dropdown-menu-checkbox-item",
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

  &:focus * {
    color: var(--color-accent-foreground);
  }

  &[data-inset] {
    padding-left: 1.75rem;
  }

  ${disabledStyles}
  ${svgDefaults}
`

const RadioItemStyled = styled(DropdownMenuPrimitive.RadioItem).attrs<DataAttributes & {
  inset?: boolean
}>((p) => ({
  "data-slot": "dropdown-menu-radio-item",
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

  &:focus * {
    color: var(--color-accent-foreground);
  }

  &[data-inset] {
    padding-left: 1.75rem;
  }

  ${disabledStyles}
  ${svgDefaults}
`

const IndicatorSpan = styled.span.attrs<DataAttributes>({
  "data-slot": "dropdown-menu-item-indicator",
})`
  position: absolute;
  right: 0.5rem;
  top: 50%;
  transform: translateY(-50%);
  display: flex;
  align-items: center;
  justify-content: center;
  pointer-events: none;
`

const LabelStyled = styled(DropdownMenuPrimitive.Label).attrs<DataAttributes & {
  inset?: boolean
}>((p) => ({
  "data-slot": "dropdown-menu-label",
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

const SeparatorStyled = styled(DropdownMenuPrimitive.Separator).attrs<DataAttributes>({
  "data-slot": "dropdown-menu-separator",
})`
  margin: 0.25rem -0.25rem;
  height: 1px;
  background-color: var(--color-border);
`

const ShortcutStyled = styled.span.attrs<DataAttributes>({
  "data-slot": "dropdown-menu-shortcut",
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

function DropdownMenu({
  ...props
}: React.ComponentProps<typeof DropdownMenuPrimitive.Root>) {
  return <DropdownMenuPrimitive.Root data-slot="dropdown-menu" {...props} />
}

function DropdownMenuPortal({
  ...props
}: React.ComponentProps<typeof DropdownMenuPrimitive.Portal>) {
  return (
    <DropdownMenuPrimitive.Portal data-slot="dropdown-menu-portal" {...props} />
  )
}

function DropdownMenuTrigger({
  ...props
}: React.ComponentProps<typeof DropdownMenuPrimitive.Trigger>) {
  return (
    <DropdownMenuPrimitive.Trigger
      data-slot="dropdown-menu-trigger"
      {...props}
    />
  )
}

function DropdownMenuContent({
  align = "start",
  sideOffset = 4,
  ...props
}: React.ComponentProps<typeof DropdownMenuPrimitive.Content>) {
  return (
    <DropdownMenuPrimitive.Portal>
      <ContentStyled sideOffset={sideOffset} align={align} {...props} />
    </DropdownMenuPrimitive.Portal>
  )
}

function DropdownMenuGroup({
  ...props
}: React.ComponentProps<typeof DropdownMenuPrimitive.Group>) {
  return (
    <DropdownMenuPrimitive.Group data-slot="dropdown-menu-group" {...props} />
  )
}

function DropdownMenuItem({
  inset,
  variant = "default",
  ...props
}: React.ComponentProps<typeof DropdownMenuPrimitive.Item> & {
  inset?: boolean
  variant?: "default" | "destructive"
}) {
  return (
    <ItemStyled inset={inset} variant={variant} {...props} />
  )
}

function DropdownMenuCheckboxItem({
  children,
  checked,
  inset,
  ...props
}: React.ComponentProps<typeof DropdownMenuPrimitive.CheckboxItem> & {
  inset?: boolean
}) {
  return (
    <CheckboxItemStyled inset={inset} checked={checked} {...props}>
      <IndicatorSpan>
        <DropdownMenuPrimitive.ItemIndicator>
          <CheckIcon />
        </DropdownMenuPrimitive.ItemIndicator>
      </IndicatorSpan>
      {children}
    </CheckboxItemStyled>
  )
}

function DropdownMenuRadioGroup({
  ...props
}: React.ComponentProps<typeof DropdownMenuPrimitive.RadioGroup>) {
  return (
    <DropdownMenuPrimitive.RadioGroup
      data-slot="dropdown-menu-radio-group"
      {...props}
    />
  )
}

function DropdownMenuRadioItem({
  children,
  inset,
  ...props
}: React.ComponentProps<typeof DropdownMenuPrimitive.RadioItem> & {
  inset?: boolean
}) {
  return (
    <RadioItemStyled inset={inset} {...props}>
      <IndicatorSpan>
        <DropdownMenuPrimitive.ItemIndicator>
          <CheckIcon />
        </DropdownMenuPrimitive.ItemIndicator>
      </IndicatorSpan>
      {children}
    </RadioItemStyled>
  )
}

function DropdownMenuLabel({
  inset,
  ...props
}: React.ComponentProps<typeof DropdownMenuPrimitive.Label> & {
  inset?: boolean
}) {
  return <LabelStyled inset={inset} {...props} />
}

function DropdownMenuSeparator({
  ...props
}: React.ComponentProps<typeof DropdownMenuPrimitive.Separator>) {
  return <SeparatorStyled {...props} />
}

function DropdownMenuShortcut({
  ...props
}: React.ComponentProps<"span">) {
  return <ShortcutStyled {...props} />
}

function DropdownMenuSub({
  ...props
}: React.ComponentProps<typeof DropdownMenuPrimitive.Sub>) {
  return <DropdownMenuPrimitive.Sub data-slot="dropdown-menu-sub" {...props} />
}

function DropdownMenuSubTrigger({
  inset,
  children,
  ...props
}: React.ComponentProps<typeof DropdownMenuPrimitive.SubTrigger> & {
  inset?: boolean
}) {
  return (
    <SubTriggerStyled inset={inset} {...props}>
      {children}
      <ChevronIcon />
    </SubTriggerStyled>
  )
}

function DropdownMenuSubContent({
  ...props
}: React.ComponentProps<typeof DropdownMenuPrimitive.SubContent>) {
  return <SubContentStyled {...props} />
}

export {
  DropdownMenu,
  DropdownMenuPortal,
  DropdownMenuTrigger,
  DropdownMenuContent,
  DropdownMenuGroup,
  DropdownMenuLabel,
  DropdownMenuItem,
  DropdownMenuCheckboxItem,
  DropdownMenuRadioGroup,
  DropdownMenuRadioItem,
  DropdownMenuSeparator,
  DropdownMenuShortcut,
  DropdownMenuSub,
  DropdownMenuSubTrigger,
  DropdownMenuSubContent,
}
