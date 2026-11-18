import { useRef, type KeyboardEvent } from 'react'
import { Popover } from 'radix-ui'
import styled from 'styled-components'
import { useNotificationReader } from '../hooks/use-notification-reader'
import { useNotificationActions } from '../hooks/use-notification-actions'
import { useUnreadOutageCount } from '../stores/unread-count'
import { Button } from '@/shared/components/ui/button'
import { useLiveOutageStore } from '@/shared/live/outage-store'
import { LiveOutageItem } from './LiveOutageItem'
import { NotificationItem } from './NotificationItem'
import { textRole, WELL_HANDOFF, wellColors, wellShading } from '@/shared/theme'

/**
 * The pane holds the well on the footer display's face. Like the display it has no outline: the
 * inset edge and the lip mark its bounds, and the overlay shadow lifts it off what lies beneath.
 * It is taller than the display, so its shade fades over a fixed length that matches the
 * display's profile.
 */
const PopoutContent = styled(Popover.Content)`
  ${wellColors('var(--color-muted)')}
  --well-fade: 4rem;
  --well-lift: 0 4px 24px rgb(0 0 0 / 0.4);

  z-index: 50;
  width: min(20rem, calc(100vw - 2rem));
  overflow: hidden;
  border-radius: var(--radius-xl);
  background-color: var(--color-muted);
  ${wellShading}

  /* The closed pane stays mounted only while an animation runs, so it fades by one. */
  &[data-state='open'] { animation: fadeIn ${WELL_HANDOFF}; }
  &[data-state='closed'] { animation: fadeOut ${WELL_HANDOFF}; }
`

const Header = styled.div`
  display: flex;
  height: 2rem;
  align-items: center;
  justify-content: space-between;
  border-bottom: 1px solid color-mix(in srgb, var(--color-border) 30%, transparent);
  padding: 0 0.75rem;
`

const HeaderLabel = styled.span`
  ${textRole('label')}
  color: var(--well-secondary-text);
`

const MarkAllButton = styled(Button).attrs({ variant: 'ghost', size: 'sm' })`
  ${textRole('caption')}
  font-weight: 500;
  color: var(--well-secondary-text);
  transition: color var(--duration-hover) ease-out;

  &:hover {
    color: var(--color-foreground);
  }
`

const ScrollArea = styled.div`
  max-height: min(20rem, calc(var(--radix-popover-content-available-height) - 2rem));
  overflow-y: auto;
`

const EmptyState = styled.div`
  padding: 1.5rem 0.75rem;
  text-align: center;
  ${textRole('body-sm')}
  color: var(--well-secondary-text);
`

/**
 * The pane stays mounted while it fades out. Without this its Escape would reach the sidebar,
 * which moves focus to the navigation toggle, and focus would not return to the bell.
 */
function keepEscapeInPane(event: KeyboardEvent<HTMLDivElement>) {
  if (event.key === 'Escape') event.stopPropagation()
}

export function NotificationPopout() {
  const { notifications, unreadCount, isLoading, isError, hasNextPage, isFetchingNextPage, isFetchNextPageError, loadMore, refetch } = useNotificationReader()
  const { markAllRead } = useNotificationActions()
  const outageEntry = useLiveOutageStore((s) => s.entry)
  const markOutageEntryRead = useLiveOutageStore((s) => s.markEntryRead)
  const outageUnread = useUnreadOutageCount()
  const contentRef = useRef<HTMLDivElement>(null)

  const markEverythingRead = () => {
    markOutageEntryRead()
    if (unreadCount > 0) markAllRead.mutate()
  }

  return (
    <Popover.Portal>
      <PopoutContent
        ref={contentRef}
        side="top"
        align="start"
        sideOffset={8}
        collisionPadding={8}
        aria-label="Notifications"
        onKeyDown={keepEscapeInPane}
      >
        <Header>
          <HeaderLabel>
            Notifications
          </HeaderLabel>
          {unreadCount + outageUnread > 0 && (
            <MarkAllButton onClick={markEverythingRead} disabled={markAllRead.isPending}>
              Mark all read
            </MarkAllButton>
          )}
        </Header>

        <ScrollArea>
          {outageEntry && <LiveOutageItem entry={outageEntry} returnFocusTo={contentRef} />}
          {isLoading ? <EmptyState role="status">Loading notifications…</EmptyState> : isError && notifications.length === 0 ? (
            <EmptyState role="alert">Could not load notifications. <Button variant="ghost" onClick={() => { refetch().catch(() => {}) }}>Retry</Button></EmptyState>
          ) : notifications.length === 0 ? (
            !outageEntry && (
              <EmptyState>
                No notifications
              </EmptyState>
            )
          ) : (
            notifications.map((notification) => (
              <NotificationItem key={notification.publicId} notification={notification} />
            ))
          )}
          {markAllRead.isError && <EmptyState role="alert">Could not mark notifications as read. Try again.</EmptyState>}
          {hasNextPage && (
            <EmptyState>
              {isFetchNextPageError && <p role="alert">Could not load more notifications.</p>}
              <Button variant="ghost" disabled={isFetchingNextPage} onClick={loadMore}>
                {isFetchingNextPage ? 'Loading…' : isFetchNextPageError ? 'Retry' : 'Load more'}
              </Button>
            </EmptyState>
          )}
        </ScrollArea>
      </PopoutContent>
    </Popover.Portal>
  )
}
