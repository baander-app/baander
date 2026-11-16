import { useLiveOutageStore } from '@/shared/live/outage-store'
import { useNotificationStore } from './notification-store'

/**
 * One while this tab lists an outage entry the listener has not opened, otherwise zero. The
 * entry lives only in this tab, so it is added at read time and never written into the
 * server's unread count, which every refresh overwrites.
 */
export function useUnreadOutageCount(): number {
  return useLiveOutageStore((state) => state.entry !== null && !state.entry.isRead ? 1 : 0)
}

/** The app bell's unread count: the server's unread notifications plus an unread outage entry. */
export function useCombinedUnreadCount(): number {
  const serverUnread = useNotificationStore((state) => state.unreadCount)
  return serverUnread + useUnreadOutageCount()
}
