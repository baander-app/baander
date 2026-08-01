import { useEffect, useRef } from 'react'
import styled from 'styled-components'
import { useNotificationStore } from '../stores/notification-store'
import { notificationApi } from '../api/notification-api'
import { NotificationItem } from './NotificationItem'

const Popover = styled.div`
  position: absolute;
  right: 0;
  top: 100%;
  z-index: 50;
  margin-top: 0.25rem;
  width: 20rem;
  overflow: hidden;
  border-radius: var(--radius-md);
  border: 1px solid color-mix(in srgb, var(--color-border) 50%, transparent);
  background-color: var(--color-popover);
  box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -4px rgba(0, 0, 0, 0.1);
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

const MarkAllButton = styled.button`
  font-size: 11px;
  color: var(--color-muted-foreground);
  transition: color var(--duration-hover) ease-out;

  &:hover {
    color: var(--color-foreground);
  }
`

const ScrollArea = styled.div`
  max-height: 20rem;
  overflow-y: auto;
`

const EmptyState = styled.div`
  padding: 1.5rem 0.75rem;
  text-align: center;
  font-size: 13px;
  color: var(--color-muted-foreground);
`

export function NotificationPopout() {
  const isPopoutOpen = useNotificationStore((s) => s.isPopoutOpen)
  const setPopoutOpen = useNotificationStore((s) => s.setPopoutOpen)
  const markAllRead = useNotificationStore((s) => s.markAllRead)
  const notifications = useNotificationStore((s) => s.notifications)
  const unreadCount = useNotificationStore((s) => s.unreadCount)
  const ref = useRef<HTMLDivElement>(null)

  // Close on click outside
  useEffect(() => {
    if (!isPopoutOpen) return

    function handleClickOutside(e: MouseEvent) {
      if (ref.current && !ref.current.contains(e.target as Node)) {
        setPopoutOpen(false)
      }
    }

    document.addEventListener('mousedown', handleClickOutside)
    return () => document.removeEventListener('mousedown', handleClickOutside)
  }, [isPopoutOpen, setPopoutOpen])

  // Close on Escape
  useEffect(() => {
    if (!isPopoutOpen) return

    function handleEscape(e: KeyboardEvent) {
      if (e.key === 'Escape') {
        setPopoutOpen(false)
      }
    }

    document.addEventListener('keydown', handleEscape)
    return () => document.removeEventListener('keydown', handleEscape)
  }, [isPopoutOpen, setPopoutOpen])

  if (!isPopoutOpen) return null

  const handleMarkAllRead = async () => {
    try {
      await notificationApi.markAllRead()
      markAllRead()
    } catch {
      // Optimistic update already applied
    }
  }

  return (
    <Popover ref={ref}>
      <Header>
        <HeaderLabel>
          Notifications
        </HeaderLabel>
        {unreadCount > 0 && (
          <MarkAllButton onClick={handleMarkAllRead}>
            Mark all read
          </MarkAllButton>
        )}
      </Header>

      <ScrollArea>
        {notifications.length === 0 ? (
          <EmptyState>
            No notifications
          </EmptyState>
        ) : (
          notifications.map((notification) => (
            <NotificationItem key={notification.publicId} notification={notification} />
          ))
        )}
      </ScrollArea>
    </Popover>
  )
}
