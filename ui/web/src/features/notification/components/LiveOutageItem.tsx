import { useCallback, type RefObject } from 'react'
import styled, { css } from 'styled-components'
import { Button } from '@/shared/components/ui/button'
import { useLiveOutageStore, type LiveOutageEntry } from '@/shared/live/outage-store'
import { textRole } from '@/shared/theme'
import { relativeTime } from '../relative-time'

const ItemButton = styled(Button).attrs({ variant: 'ghost', type: 'button' })<{ $unread: boolean }>`
  display: flex;
  width: 100%;
  height: auto;
  align-items: flex-start;
  justify-content: flex-start;
  gap: 0.625rem;
  border-radius: 0;
  padding: 0.5rem 0.75rem;
  text-align: left;
  white-space: normal;

  ${({ $unread }) => $unread && css`
    background-color: color-mix(in srgb, var(--color-highlight) 20%, transparent);
  `}
`

const WarningBar = styled.span`
  margin-top: 0.375rem;
  min-height: 1px;
  width: 0.125rem;
  flex-shrink: 0;
  align-self: stretch;
  border-radius: 9999px;
  background-color: var(--color-warning);
`

const Content = styled.span`
  display: flex;
  min-width: 0;
  flex: 1;
  flex-direction: column;
`

const Title = styled.span`
  ${textRole('body-sm')}
  color: var(--color-foreground);
`

const Time = styled.span`
  ${textRole('caption')}
  color: var(--well-secondary-text, var(--color-muted-foreground));
`

interface LiveOutageItemProps {
  entry: LiveOutageEntry
  /** Takes focus when the outage ends while this item has it, so focus stays in the popout. */
  returnFocusTo: RefObject<HTMLElement | null>
}

/**
 * The bell entry for a long live-update outage. It is pinned above the server's notifications,
 * and selecting it only marks it read in this tab: there is nothing to open and the popout
 * stays open. It disappears when live updates recover.
 */
export function LiveOutageItem({ entry, returnFocusTo }: LiveOutageItemProps) {
  const markEntryRead = useLiveOutageStore((state) => state.markEntryRead)

  // React detaches the button's ref while the button is still in the document, so focus can move
  // before the browser drops it to the page body. When the whole popout closes, its own ref is
  // already detached and the popout decides where focus goes.
  const keepFocusInPopout = useCallback((button: HTMLButtonElement | null) => {
    if (button === null) return undefined
    return () => {
      if (button === document.activeElement) returnFocusTo.current?.focus()
    }
  }, [returnFocusTo])

  return (
    <ItemButton ref={keepFocusInPopout} $unread={!entry.isRead} onClick={() => markEntryRead()}>
      <WarningBar />
      <Content>
        <Title>Live updates unavailable</Title>
        <Time>{relativeTime(entry.since)}</Time>
      </Content>
    </ItemButton>
  )
}
