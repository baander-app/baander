import { createContext, useContext } from 'react'
import type { useNotifications } from './use-notifications'

export const NotificationContext = createContext<ReturnType<typeof useNotifications> | null>(null)

export function useNotificationReader() {
  const reader = useContext(NotificationContext)
  if (!reader) throw new Error('Notifications require the authenticated notification reader')
  return reader
}
