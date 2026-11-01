import { Popover } from 'radix-ui'
import styled from 'styled-components'
import { useNotificationReader } from '../hooks/use-notification-reader'
import { useNotificationActions } from '../hooks/use-notification-actions'
import { Button } from '@/shared/components/ui/button'
import { NotificationItem } from './NotificationItem'

const PopoutContent = styled(Popover.Content)`
  z-index: 50;
  width: min(20rem, calc(100vw - 2rem));
  overflow: hidden;
  border-radius: var(--radius-xl);
  border: 1px solid color-mix(in srgb, var(--color-border) 50%, transparent);
  background-color: var(--color-popover);
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
  font-size: 11px;
  font-weight: 500;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  color: var(--color-muted-foreground);
`

const MarkAllButton = styled(Button).attrs({ variant: 'ghost', size: 'sm' })`
  font-size: 11px;
  color: var(--color-muted-foreground);
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
  font-size: 13px;
  color: var(--color-muted-foreground);
`

export function NotificationPopout() {
  const { notifications, unreadCount, isLoading, isError, hasNextPage, isFetchingNextPage, isFetchNextPageError, loadMore, refetch } = useNotificationReader()
  const { markAllRead } = useNotificationActions()

  return (
    <Popover.Portal>
      <PopoutContent side="top" align="start" sideOffset={8} collisionPadding={8} aria-label="Notifications">
        <Header>
          <HeaderLabel>
            Notifications
          </HeaderLabel>
          {unreadCount > 0 && (
            <MarkAllButton onClick={() => markAllRead.mutate()} disabled={markAllRead.isPending}>
              Mark all read
            </MarkAllButton>
          )}
        </Header>

        <ScrollArea>
          {isLoading ? <EmptyState role="status">Loading notifications…</EmptyState> : isError && notifications.length === 0 ? (
            <EmptyState role="alert">Could not load notifications. <Button variant="ghost" onClick={() => { refetch().catch(() => {}) }}>Retry</Button></EmptyState>
          ) : notifications.length === 0 ? (
            <EmptyState>
              No notifications
            </EmptyState>
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
