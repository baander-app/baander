import styled from 'styled-components'
import { Bell } from 'lucide-react'
import { useNotificationStore } from '../stores/notification-store'

const BellButton = styled.button`
  position: relative;
  display: flex;
  height: 1.5rem;
  width: 1.5rem;
  align-items: center;
  justify-content: center;
  border-radius: var(--radius-sm);
  background: none;
  border: none;
  padding: 0;
  color: var(--color-muted-foreground);
  transition: color var(--duration-hover) ease-out, background-color var(--duration-hover) ease-out;

  &:hover {
    background-color: var(--color-highlight);
    color: var(--color-foreground);
  }
`

const UnreadBadge = styled.span`
  position: absolute;
  right: -0.125rem;
  top: -0.125rem;
  display: flex;
  min-width: 0.875rem;
  align-items: center;
  justify-content: center;
  border-radius: 9999px;
  background-color: var(--color-destructive);
  padding: 0 0.25rem;
  font-size: 9px;
  font-weight: 500;
  line-height: 1;
  color: var(--color-destructive-foreground);
`

export function NotificationBell() {
  const unreadCount = useNotificationStore((s) => s.unreadCount)
  const togglePopout = useNotificationStore((s) => s.togglePopout)
  const isPopoutOpen = useNotificationStore((s) => s.isPopoutOpen)

  return (
    <BellButton
      onClick={togglePopout}
      aria-label={`${unreadCount} unread notifications`}
      aria-expanded={isPopoutOpen}
    >
      <Bell size={16} />
      {unreadCount > 0 && (
        <UnreadBadge>
          {unreadCount > 99 ? '99+' : unreadCount}
        </UnreadBadge>
      )}
    </BellButton>
  )
}
