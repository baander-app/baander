import { Button } from '@/shared/components/ui/button'
import styled from 'styled-components'
import { Bell } from 'lucide-react'
import { useNotificationStore } from '../stores/notification-store'
import { textRole } from '@/shared/theme'

const BellButton = styled(Button).attrs({ variant: 'ghost', size: 'icon-xs' })`
  border: 0;
  background-color: transparent;
  color: var(--color-foreground);
  position: relative;
  display: flex;
  height: 1.5rem;
  width: 1.5rem;
  align-items: center;
  justify-content: center;
  border-radius: var(--radius-md);
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
  ${textRole('caption')}
  font-size: var(--text-dense-size);
  font-weight: 500;
  color: var(--color-destructive-foreground);
`

export function NotificationBell(props: React.ComponentProps<typeof Button>) {
  const unreadCount = useNotificationStore((s) => s.unreadCount)

  return (
    <BellButton
      {...props}
      aria-label={`${unreadCount} unread notifications`}
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
