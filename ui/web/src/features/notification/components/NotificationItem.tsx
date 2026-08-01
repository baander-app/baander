import { useNavigate } from 'react-router-dom'
import styled, { css } from 'styled-components'
import { useNotificationStore } from '../stores/notification-store'
import { notificationApi } from '../api/notification-api'
import type { NotificationItem as NotificationItemType } from '../api/notification-api'

const categoryColorMap: Record<string, string> = {
  security: '#ef4444',
  background_jobs: '#3b82f6',
  media_changes: '#10b981',
  admin_operations: '#a855f7',
}

function relativeTime(dateStr: string): string {
  const now = Date.now()
  const then = new Date(dateStr).getTime()
  const diff = Math.max(0, now - then)

  const seconds = Math.floor(diff / 1000)
  if (seconds < 60) return 'just now'

  const minutes = Math.floor(seconds / 60)
  if (minutes < 60) return `${minutes}m ago`

  const hours = Math.floor(minutes / 60)
  if (hours < 24) return `${hours}h ago`

  const days = Math.floor(hours / 24)
  if (days < 30) return `${days}d ago`

  return new Date(dateStr).toLocaleDateString()
}

function navigateForEvent(eventType: string): string | null {
  if (eventType.startsWith('security.')) return '/admin/security?tab=users'
  if (eventType.startsWith('background_jobs.')) return '/admin?tab=jobs'
  if (eventType.startsWith('library.')) return '/admin/library'
  if (eventType.startsWith('user.')) return '/admin'
  if (eventType.startsWith('admin_operations.')) return '/admin'
  return null
}

const ItemButton = styled.button<{ $unread: boolean }>`
  display: flex;
  width: 100%;
  align-items: flex-start;
  gap: 0.625rem;
  padding: 0.5rem 0.75rem;
  text-align: left;
  transition: color var(--duration-hover) ease-out, background-color var(--duration-hover) ease-out;

  &:hover {
    background: color-mix(in srgb, var(--color-highlight) 50%, transparent);
  }

  ${({ $unread }) => $unread && css`
    background: color-mix(in srgb, var(--color-highlight) 20%, transparent);
  `}
`

const ColorBar = styled.div<{ $color: string }>`
  margin-top: 0.375rem;
  height: 100%;
  min-height: 1px;
  width: 0.125rem;
  flex-shrink: 0;
  align-self: stretch;
  border-radius: 9999px;
  background-color: ${({ $color }) => $color};
`

const ContentArea = styled.div`
  min-width: 0;
  flex: 1;
`

const Title = styled.p`
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  font-size: 13px;
  line-height: 1.375;
  color: var(--color-foreground);
`

const Time = styled.p`
  font-size: 11px;
  color: var(--color-muted-foreground);
`

export function NotificationItem({ notification }: { notification: NotificationItemType }) {
  const navigate = useNavigate()
  const markRead = useNotificationStore((s) => s.markRead)
  const barColor = categoryColorMap[notification.category] ?? 'var(--color-muted-foreground)'

  const handleClick = async () => {
    if (!notification.isRead) {
      try {
        await notificationApi.markRead(notification.publicId)
        markRead(notification.publicId)
      } catch {
        // Optimistic update already applied
      }
    }

    const target = navigateForEvent(notification.eventType)
    if (target) {
      navigate(target)
    }

    // Close popout after navigation
    useNotificationStore.getState().setPopoutOpen(false)
  }

  return (
    <ItemButton onClick={handleClick} $unread={!notification.isRead}>
      <ColorBar $color={barColor} />
      <ContentArea>
        <Title>
          {notification.title ?? notification.eventType}
        </Title>
        <Time>
          {relativeTime(notification.createdAt)}
        </Time>
      </ContentArea>
    </ItemButton>
  )
}
