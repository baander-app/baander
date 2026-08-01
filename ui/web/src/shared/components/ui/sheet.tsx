"use client"

import * as React from "react"
import { Dialog as SheetPrimitive } from "radix-ui"
import styled, { keyframes, type DataAttributes } from "styled-components"

import { Button } from "@/shared/components/ui/button"
import { XIcon } from "lucide-react"

/* ─── Keyframes ─── */

const fadeIn = keyframes`
  from { opacity: 0; }
  to { opacity: 1; }
`

const fadeOut = keyframes`
  from { opacity: 1; }
  to { opacity: 0; }
`

const slideInFromTop = keyframes`
  from { transform: translateY(-10px); }
  to { transform: translateY(0); }
`

const slideInFromBottom = keyframes`
  from { transform: translateY(10px); }
  to { transform: translateY(0); }
`

const slideInFromLeft = keyframes`
  from { transform: translateX(-10px); }
  to { transform: translateX(0); }
`

const slideInFromRight = keyframes`
  from { transform: translateX(10px); }
  to { transform: translateX(0); }
`

const slideOutToTop = keyframes`
  from { transform: translateY(0); }
  to { transform: translateY(-10px); }
`

const slideOutToBottom = keyframes`
  from { transform: translateY(0); }
  to { transform: translateY(10px); }
`

const slideOutToLeft = keyframes`
  from { transform: translateX(0); }
  to { transform: translateX(-10px); }
`

const slideOutToRight = keyframes`
  from { transform: translateX(0); }
  to { transform: translateX(10px); }
`

/* ─── Simple wrappers (no styling) ─── */

function Sheet({ ...props }: React.ComponentProps<typeof SheetPrimitive.Root>) {
  return <SheetPrimitive.Root data-slot="sheet" {...props} />
}

function SheetTrigger({
  ...props
}: React.ComponentProps<typeof SheetPrimitive.Trigger>) {
  return <SheetPrimitive.Trigger data-slot="sheet-trigger" {...props} />
}

function SheetClose({
  ...props
}: React.ComponentProps<typeof SheetPrimitive.Close>) {
  return <SheetPrimitive.Close data-slot="sheet-close" {...props} />
}

function SheetPortal({
  ...props
}: React.ComponentProps<typeof SheetPrimitive.Portal>) {
  return <SheetPrimitive.Portal data-slot="sheet-portal" {...props} />
}

/* ─── Styled Overlay ─── */

const Overlay = styled(SheetPrimitive.Overlay).attrs<DataAttributes>({
  'data-slot': 'sheet-overlay',
})`
  position: fixed;
  inset: 0;
  z-index: 50;
  background: rgba(0, 0, 0, 0.4);
  transition-duration: 100ms;

  @supports (backdrop-filter: blur(0px)) {
    backdrop-filter: blur(12px);
  }

  &[data-state="open"] {
    animation: ${fadeIn} 150ms ease-out;
  }

  &[data-state="closed"] {
    animation: ${fadeOut} 150ms ease-in;
  }
`

/* ─── Styled Content ─── */

const StyledContent = styled(SheetPrimitive.Content).attrs<DataAttributes>({
  'data-slot': 'sheet-content',
})`
  position: fixed;
  z-index: 50;
  display: flex;
  flex-direction: column;
  gap: 1rem;
  background: var(--color-popover);
  background-clip: padding-box;
  font-size: 0.875rem;
  line-height: 1.6;
  color: var(--color-popover-foreground);
  box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 8px 10px -6px rgba(0, 0, 0, 0.1);
  transition: all 200ms ease-in-out;

  /* ── Side variants ── */
  &[data-side="top"] {
    inset-inline: 0;
    top: 0;
    height: auto;

    &[data-state="open"] {
      animation: ${fadeIn} 150ms ease-out, ${slideInFromTop} 200ms ease-out;
    }
    &[data-state="closed"] {
      animation: ${fadeOut} 150ms ease-in, ${slideOutToTop} 200ms ease-in;
    }
  }

  &[data-side="bottom"] {
    inset-inline: 0;
    bottom: 0;
    height: auto;

    &[data-state="open"] {
      animation: ${fadeIn} 150ms ease-out, ${slideInFromBottom} 200ms ease-out;
    }
    &[data-state="closed"] {
      animation: ${fadeOut} 150ms ease-in, ${slideOutToBottom} 200ms ease-in;
    }
  }

  &[data-side="left"] {
    inset-block: 0;
    left: 0;
    height: 100%;
    width: 75%;
    border-right: 1px solid var(--color-border);

    @media (min-width: 640px) {
      max-width: 24rem;
    }

    &[data-state="open"] {
      animation: ${fadeIn} 150ms ease-out, ${slideInFromLeft} 200ms ease-out;
    }
    &[data-state="closed"] {
      animation: ${fadeOut} 150ms ease-in, ${slideOutToLeft} 200ms ease-in;
    }
  }

  &[data-side="right"] {
    inset-block: 0;
    right: 0;
    height: 100%;
    width: 75%;
    border-left: 1px solid var(--color-border);

    @media (min-width: 640px) {
      max-width: 24rem;
    }

    &[data-state="open"] {
      animation: ${fadeIn} 150ms ease-out, ${slideInFromRight} 200ms ease-out;
    }
    &[data-state="closed"] {
      animation: ${fadeOut} 150ms ease-in, ${slideOutToRight} 200ms ease-in;
    }
  }
`

/* ─── Styled Close Button ─── */

const CloseButtonWrapper = styled(SheetPrimitive.Close).attrs<DataAttributes>({
  'data-slot': 'sheet-close',
})`
  position: absolute;
  top: 0.75rem;
  right: 0.75rem;
`

/* ─── Styled Header / Footer ─── */

const StyledHeader = styled.div.attrs<DataAttributes>({ 'data-slot': 'sheet-header' })`
  display: flex;
  flex-direction: column;
  gap: 0.125rem;
  padding: 1rem;
`

const StyledFooter = styled.div.attrs<DataAttributes>({ 'data-slot': 'sheet-footer' })`
  margin-top: auto;
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
  padding: 1rem;
`

/* ─── Styled Title / Description ─── */

const StyledTitle = styled(SheetPrimitive.Title).attrs<DataAttributes>({
  'data-slot': 'sheet-title',
})`
  font-family: var(--font-heading);
  font-size: 1rem;
  line-height: 1.6;
  font-weight: 500;
  color: var(--color-foreground);
`

const StyledDescription = styled(SheetPrimitive.Description).attrs<DataAttributes>({
  'data-slot': 'sheet-description',
})`
  font-size: 0.875rem;
  line-height: 1.6;
  color: var(--color-muted-foreground);
`

/* ─── Screen-reader only text ─── */

const SrOnly = styled.span`
  position: absolute;
  width: 1px;
  height: 1px;
  padding: 0;
  margin: -1px;
  overflow: hidden;
  clip: rect(0, 0, 0, 0);
  white-space: nowrap;
  border-width: 0;
`

/* ─── Composite Components ─── */

function SheetOverlay({
  ...props
}: React.ComponentProps<typeof SheetPrimitive.Overlay>) {
  return <Overlay {...props} />
}

function SheetContent({
  children,
  side = "right",
  showCloseButton = true,
  ...props
}: React.ComponentProps<typeof SheetPrimitive.Content> & {
  side?: "top" | "right" | "bottom" | "left"
  showCloseButton?: boolean
}) {
  return (
    <SheetPortal>
      <SheetOverlay />
      <StyledContent data-side={side} {...props}>
        {children}
        {showCloseButton && (
          <CloseButtonWrapper asChild>
            <Button variant="ghost" size="icon-sm">
              <XIcon />
              <SrOnly>Close</SrOnly>
            </Button>
          </CloseButtonWrapper>
        )}
      </StyledContent>
    </SheetPortal>
  )
}

function SheetHeader(props: React.ComponentProps<"div">) {
  return <StyledHeader {...props} />
}

function SheetFooter(props: React.ComponentProps<"div">) {
  return <StyledFooter {...props} />
}

function SheetTitle({
  ...props
}: React.ComponentProps<typeof SheetPrimitive.Title>) {
  return <StyledTitle {...props} />
}

function SheetDescription({
  ...props
}: React.ComponentProps<typeof SheetPrimitive.Description>) {
  return <StyledDescription {...props} />
}

export {
  Sheet,
  SheetTrigger,
  SheetClose,
  SheetContent,
  SheetHeader,
  SheetFooter,
  SheetTitle,
  SheetDescription,
}
