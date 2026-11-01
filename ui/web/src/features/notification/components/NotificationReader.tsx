import type { ReactNode } from 'react'
import { useNotifications } from '../hooks/use-notifications'
import { NotificationContext } from '../hooks/use-notification-reader'

export function NotificationReader({ children }: { children: ReactNode }) {
  const notifications = useNotifications()
  return <NotificationContext.Provider value={notifications}>{children}</NotificationContext.Provider>
}
