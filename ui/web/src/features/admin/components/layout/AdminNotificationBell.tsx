import { useState, useRef, useEffect } from 'react'
import styled, { css } from 'styled-components'
import { Bell } from 'lucide-react'
import { useAdminNotifications } from '../../hooks/use-admin-notifications'
import { useNotificationStore } from '@/features/notification/stores/notification-store'
import { interactiveTransition } from '@/shared/theme'

function relativeTime(dateStr: string): string {
  const diff = Date.now() - new Date(dateStr).getTime()
  const seconds = Math.floor(diff / 1000)
  if (seconds < 60) return 'just now'
  const minutes = Math.floor(seconds / 60)
  if (minutes < 60) return `${minutes}m ago`
  const hours = Math.floor(minutes / 60)
  if (hours < 24) return `${hours}h ago`
  const days = Math.floor(hours / 24)
  return `${days}d ago`
}

const categoryBorderColors: Record<string, string> = {
  admin_operations: '#3b82f6',
  security: '#ef4444',
  background_jobs: '#f59e0b',
  media_changes: '#10b981',
}

const Container = styled.div`
  position: relative;
`

const BellButton = styled.button`
  position: relative;
  display: flex;
  height: 2rem;
  width: 2rem;
  align-items: center;
  justify-content: center;
  border-radius: 0.375rem;
  color: var(--color-muted-foreground);
  ${interactiveTransition(['color', 'background-color'])}

  &:hover {
    background-color: color-mix(in srgb, var(--color-accent) 40%, transparent);
    color: var(--color-foreground);
  }
`

const Badge = styled.span`
  position: absolute;
  right: -0.125rem;
  top: -0.125rem;
  display: flex;
  height: 1rem;
  min-width: 1rem;
  align-items: center;
  justify-content: center;
  border-radius: 50%;
  background-color: #ef4444;
  padding: 0 0.25rem;
  font-size: 10px;
  font-weight: 500;
  color: white;
`

const Popout = styled.div`
  position: absolute;
  right: 0;
  top: 100%;
  z-index: 50;
  margin-top: 0.25rem;
  width: 20rem;
  border-radius: 0.375rem;
  border: 1px solid var(--color-border);
  background-color: var(--color-card);
  box-shadow: 0 4px 24px rgba(0, 0, 0, 0.4);
`

const PopoutHeader = styled.div`
  display: flex;
  align-items: center;
  justify-content: space-between;
  border-bottom: 1px solid var(--color-border);
  padding: 0.5rem 0.75rem;
`

const PopoutTitle = styled.span`
  font-size: 0.75rem;
  font-weight: 500;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  color: var(--color-muted-foreground);
`

const MarkAllButton = styled.button`
  font-size: 11px;
  color: var(--color-muted-foreground);
  ${interactiveTransition(['color'])}

  &:hover { color: var(--color-foreground); }
  &:disabled { opacity: 0.5; }
`

const PopoutBody = styled.div`
  max-height: 18rem;
  overflow-y: auto;
`

const EmptyState = styled.div`
  padding: 1.5rem 0.75rem;
  text-align: center;
  font-size: 0.75rem;
  color: var(--color-muted-foreground);
`

const NotificationItem = styled.button<{ $borderColor: string; $isRead: boolean }>`
  display: flex;
  width: 100%;
  gap: 0.5rem;
  border-left: 2px solid ${({ $borderColor }) => $borderColor};
  padding: 0.625rem 0.75rem;
  text-align: left;
  ${interactiveTransition(['background-color', 'opacity'])}

  &:hover { background-color: color-mix(in srgb, var(--color-accent) 10%, transparent); }
  ${({ $isRead }) => $isRead && css`opacity: 0.6;`}
`

const NotificationContent = styled.div`
  min-width: 0;
  flex: 1;
`

const NotificationTitle = styled.div`
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  font-size: 13px;
`

const NotificationTime = styled.div`
  font-size: 11px;
  color: var(--color-muted-foreground);
`

const UnreadDot = styled.span`
  margin-top: 0.25rem;
  height: 0.375rem;
  width: 0.375rem;
  flex-shrink: 0;
  border-radius: 50%;
  background-color: #3b82f6;
`

export function AdminNotificationBell() {
  const [open, setOpen] = useState(false)
  const popoutRef = useRef<HTMLDivElement>(null)
  const { notifications, unreadCount, markRead, markAllRead, isMarkingAll } = useAdminNotifications()

  const storeUnread = useNotificationStore((s) => s.unreadCount)
  const displayCount = unreadCount > 0 ? unreadCount : storeUnread

  useEffect(() => {
    if (!open) return
    function handleClick(e: MouseEvent) {
      if (popoutRef.current && !popoutRef.current.contains(e.target as Node)) {
        setOpen(false)
      }
    }
    document.addEventListener('mousedown', handleClick)
    return () => document.removeEventListener('mousedown', handleClick)
  }, [open])

  return (
    <Container ref={popoutRef}>
      <BellButton
        type="button"
        onClick={() => setOpen((prev) => !prev)}
        aria-label={`Notifications${displayCount > 0 ? ` (${displayCount} unread)` : ''}`}
      >
        <Bell size={15} strokeWidth={1.5} />
        {displayCount > 0 && (
          <Badge>{displayCount > 99 ? '99+' : displayCount}</Badge>
        )}
      </BellButton>

      {open && (
        <Popout>
          <PopoutHeader>
            <PopoutTitle>Admin Notifications</PopoutTitle>
            {notifications.length > 0 && (
              <MarkAllButton type="button" onClick={() => markAllRead()} disabled={isMarkingAll}>
                Mark all read
              </MarkAllButton>
            )}
          </PopoutHeader>

          <PopoutBody>
            {notifications.length === 0 ? (
              <EmptyState>No admin notifications</EmptyState>
            ) : (
              notifications.map((n) => (
                <NotificationItem
                  key={n.publicId}
                  type="button"
                  $borderColor={categoryBorderColors[n.category] ?? 'var(--color-muted)'}
                  $isRead={n.isRead}
                  onClick={() => { markRead(n.publicId); setOpen(false) }}
                >
                  <NotificationContent>
                    <NotificationTitle>{n.title ?? n.eventType}</NotificationTitle>
                    <NotificationTime>{relativeTime(n.createdAt)}</NotificationTime>
                  </NotificationContent>
                  {!n.isRead && <UnreadDot />}
                </NotificationItem>
              ))
            )}
          </PopoutBody>
        </Popout>
      )}
    </Container>
  )
}
