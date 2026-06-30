import * as React from "react"
import { Dialog as DialogPrimitive } from "radix-ui"
import styled, { keyframes } from "styled-components"

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

const contentShow = keyframes`
  from {
    opacity: 0;
    transform: translate(-50%, -50%) scale(0.95);
  }
  to {
    opacity: 1;
    transform: translate(-50%, -50%) scale(1);
  }
`

const contentHide = keyframes`
  from {
    opacity: 1;
    transform: translate(-50%, -50%) scale(1);
  }
  to {
    opacity: 0;
    transform: translate(-50%, -50%) scale(0.95);
  }
`

/* ─── Simple wrappers (no styling) ─── */

function Dialog({ ...props }: React.ComponentProps<typeof DialogPrimitive.Root>) {
  return <DialogPrimitive.Root data-slot="dialog" {...props} />
}

function DialogTrigger({ ...props }: React.ComponentProps<typeof DialogPrimitive.Trigger>) {
  return <DialogPrimitive.Trigger data-slot="dialog-trigger" {...props} />
}

function DialogPortal({ ...props }: React.ComponentProps<typeof DialogPrimitive.Portal>) {
  return <DialogPrimitive.Portal data-slot="dialog-portal" {...props} />
}

function DialogClose({ ...props }: React.ComponentProps<typeof DialogPrimitive.Close>) {
  return <DialogPrimitive.Close data-slot="dialog-close" {...props} />
}

/* ─── Styled Overlay ─── */

const Overlay = styled(DialogPrimitive.Overlay).attrs({
  'data-slot': 'dialog-overlay',
})`
  position: fixed;
  inset: 0;
  isolation: isolate;
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

const StyledContent = styled(DialogPrimitive.Content).attrs({
  'data-slot': 'dialog-content',
})`
  position: fixed;
  top: 50%;
  left: 50%;
  z-index: 50;
  display: grid;
  width: 100%;
  max-width: calc(100% - 2rem);
  transform: translate(-50%, -50%);
  gap: 1rem;
  border-radius: var(--radius-xl);
  background-color: var(--color-popover);
  padding: 1rem;
  font-size: 0.875rem;
  color: var(--color-popover-foreground);
  box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 8px 10px -6px rgba(0, 0, 0, 0.1);
  transition-duration: 100ms;
  outline: none;

  @media (min-width: 640px) {
    max-width: 24rem;
  }

  &[data-state="open"] {
    animation: ${contentShow} 150ms ease-out;
  }

  &[data-state="closed"] {
    animation: ${contentHide} 150ms ease-in;
  }
`

/* ─── Styled Close Button ─── */

const CloseButtonWrapper = styled(DialogPrimitive.Close).attrs({
  'data-slot': 'dialog-close',
})`
  position: absolute;
  top: 0.5rem;
  right: 0.5rem;
`

/* ─── Styled Header / Footer ─── */

const StyledHeader = styled.div.attrs({ 'data-slot': 'dialog-header' })`
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
`

const StyledFooter = styled.div.attrs({ 'data-slot': 'dialog-footer' })`
  display: flex;
  flex-direction: column-reverse;
  gap: 0.5rem;
  margin-left: -1rem;
  margin-right: -1rem;
  margin-bottom: -1rem;
  border-bottom-left-radius: var(--radius-xl);
  border-bottom-right-radius: var(--radius-xl);
  background-color: color-mix(in srgb, var(--color-muted) 50%, transparent);
  padding: 1rem;

  @media (min-width: 640px) {
    flex-direction: row;
    justify-content: flex-end;
  }
`

/* ─── Styled Title / Description ─── */

const StyledTitle = styled(DialogPrimitive.Title).attrs({
  'data-slot': 'dialog-title',
})`
  font-family: var(--font-heading);
  font-size: 1rem;
  line-height: 1;
  font-weight: 500;
`

const StyledDescription = styled(DialogPrimitive.Description).attrs({
  'data-slot': 'dialog-description',
})`
  font-size: 0.875rem;
  color: var(--color-muted-foreground);

  a {
    text-decoration: underline;
    text-underline-offset: 3px;

    &:hover {
      color: var(--color-foreground);
    }
  }
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

function DialogOverlay({ ...props }: React.ComponentProps<typeof DialogPrimitive.Overlay>) {
  return <Overlay {...props} />
}

function DialogContent({
  children,
  showCloseButton = true,
  ...props
}: React.ComponentProps<typeof DialogPrimitive.Content> & {
  showCloseButton?: boolean
}) {
  return (
    <DialogPortal>
      <DialogOverlay />
      <StyledContent {...props}>
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
    </DialogPortal>
  )
}

function DialogHeader({ ...props }: React.ComponentProps<"div">) {
  return <StyledHeader {...props} />
}

function DialogFooter({
  showCloseButton = false,
  children,
  ...props
}: React.ComponentProps<"div"> & {
  showCloseButton?: boolean
}) {
  return (
    <StyledFooter {...props}>
      {children}
      {showCloseButton && (
        <DialogPrimitive.Close asChild>
          <Button variant="outline">Close</Button>
        </DialogPrimitive.Close>
      )}
    </StyledFooter>
  )
}

function DialogTitle({ ...props }: React.ComponentProps<typeof DialogPrimitive.Title>) {
  return <StyledTitle {...props} />
}

function DialogDescription({ ...props }: React.ComponentProps<typeof DialogPrimitive.Description>) {
  return <StyledDescription {...props} />
}

export {
  Dialog,
  DialogClose,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogOverlay,
  DialogPortal,
  DialogTitle,
  DialogTrigger,
}
