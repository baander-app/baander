import { useEffect } from 'react'
import { useQuery } from '@tanstack/react-query'
import { notificationApi } from '../api/notification-api'
import { useNotificationStore } from '../stores/notification-store'

export function useNotifications() {
  const setNotifications = useNotificationStore((s) => s.setNotifications)
  const setUnreadCount = useNotificationStore((s) => s.setUnreadCount)
  const notifications = useNotificationStore((s) => s.notifications)
  const unreadCount = useNotificationStore((s) => s.unreadCount)

  const query = useQuery({
    queryKey: ['notifications'],
    queryFn: async () => {
      const [items, count] = await Promise.all([
        notificationApi.list({ limit: 20 }),
        notificationApi.unreadCount(),
      ])
      return { items, count }
    },
    staleTime: 30_000,
  })

  // Sync fetched data into store
  useEffect(() => {
    if (query.data) {
      setNotifications(query.data.items)
      setUnreadCount(query.data.count)
    }
  }, [query.data, setNotifications, setUnreadCount])

  return {
    notifications,
    unreadCount,
    isLoading: query.isLoading,
    refetch: query.refetch,
  }
}
