import { useQuery } from '@tanstack/react-query'
import { notificationApi } from '@/features/notification/api/notification-api'
import { useNotificationActions } from '@/features/notification/hooks/use-notification-actions'

export function useAdminNotifications() {
  const { markRead, markAllRead } = useNotificationActions()

  const query = useQuery({
    queryKey: ['admin-notifications'],
    queryFn: async () => {
      const [items, count] = await Promise.all([
        notificationApi.list({ category: 'admin_operations', limit: 20 }),
        notificationApi.unreadCount(),
      ])
      return { items: items.data, count }
    },
    staleTime: 30_000,
  })

  // Sync into store (filtered for admin view)
  const adminNotifications = (query.data?.items ?? []).filter(
    (n) => n.category === 'admin_operations',
  )
  const adminUnreadCount = adminNotifications.filter((n) => !n.isRead).length

  return {
    notifications: adminNotifications,
    unreadCount: adminUnreadCount,
    isLoading: query.isLoading,
    markRead: markRead.mutate,
    markAllRead: markAllRead.mutate,
    isMarkingAll: markAllRead.isPending,
  }
}
