import styled from 'styled-components'
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogDescription,
} from '@/shared/components/ui/dialog'
import { Separator } from '@/shared/components/ui/separator'
import {
  getShortcutsByCategory,
  isPlatformMac,
  parseKeyDisplay,
} from '@/shared/lib/shortcut-registry'

interface KeyboardShortcutsHelpProps {
  open: boolean
  onOpenChange: (open: boolean) => void
}

const StyledDialogContent = styled(DialogContent)`
  @media (min-width: 640px) {
    max-width: 32rem;
  }
`

const ScrollArea = styled.div`
  max-height: 60vh;
  overflow-y: auto;
`

const CategoryGroup = styled.div`
  margin-bottom: 1rem;

  &:last-child {
    margin-bottom: 0;
  }
`

const CategoryTitle = styled.h3`
  margin-bottom: 0.5rem;
  font-size: 0.875rem;
  font-weight: 600;
  color: var(--color-foreground);
`

const ShortcutList = styled.div`
  display: flex;
  flex-direction: column;
  gap: 0.125rem;
`

const ShortcutRow = styled.div`
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 0.375rem 0;
`

const ShortcutDescription = styled.span`
  font-size: 0.875rem;
  color: var(--color-muted-foreground);
`

const KeyGroup = styled.div`
  display: flex;
  align-items: center;
  gap: 0.25rem;
`

const Kbd = styled.kbd`
  display: inline-flex;
  height: 1.5rem;
  min-width: 1.5rem;
  align-items: center;
  justify-content: center;
  border-radius: var(--radius-md);
  background-color: var(--color-secondary);
  padding: 0 0.375rem;
  font-size: 0.75rem;
  font-weight: 500;
  font-family: inherit;
  color: var(--color-foreground);
`

const Footer = styled.p`
  margin-top: 0.5rem;
  font-size: 0.75rem;
  color: var(--color-muted-foreground);
  text-align: center;
`

export function KeyboardShortcutsHelp({ open, onOpenChange }: KeyboardShortcutsHelpProps) {
  const categories = getShortcutsByCategory()
  const mac = isPlatformMac()

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <StyledDialogContent>
        <DialogHeader>
          <DialogTitle>Keyboard Shortcuts</DialogTitle>
          <DialogDescription>
            Navigate and control playback from your keyboard.
          </DialogDescription>
        </DialogHeader>
        <Separator />
        <ScrollArea>
          {Array.from(categories.entries()).map(([category, shortcuts]) => {
            if (shortcuts.length === 0) return null
            return (
              <CategoryGroup key={category}>
                <CategoryTitle>{category}</CategoryTitle>
                <ShortcutList>
                  {shortcuts.map((shortcut) => {
                    const display = mac ? shortcut.keys.mac : shortcut.keys.default
                    const tokens = parseKeyDisplay(display)

                    return (
                      <ShortcutRow key={shortcut.id}>
                        <ShortcutDescription>{shortcut.description}</ShortcutDescription>
                        <KeyGroup>
                          {tokens.map((token, i) => (
                            <Kbd key={`${shortcut.id}-${i}`}>{token}</Kbd>
                          ))}
                        </KeyGroup>
                      </ShortcutRow>
                    )
                  })}
                </ShortcutList>
              </CategoryGroup>
            )
          })}
        </ScrollArea>
        <Separator />
        <Footer>Shortcuts work when no text field is focused.</Footer>
      </StyledDialogContent>
    </Dialog>
  )
}
