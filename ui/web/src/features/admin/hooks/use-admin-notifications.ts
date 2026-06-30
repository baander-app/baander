import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { notificationApi } from '@/features/notification/api/notification-api'
import { useNotificationStore } from '@/features/notification/stores/notification-store'

export function useAdminNotifications() {
  const markReadInStore = useNotificationStore((s) => s.markRead)
  const markAllReadInStore = useNotificationStore((s) => s.markAllRead)
  const queryClient = useQueryClient()

  const query = useQuery({
    queryKey: ['admin-notifications'],
    queryFn: async () => {
      const [items, count] = await Promise.all([
        notificationApi.list({ category: 'admin_operations', limit: 20 }),
        notificationApi.unreadCount(),
      ])
      return { items, count }
    },
    staleTime: 30_000,
  })

  // Sync into store (filtered for admin view)
  const adminNotifications = (query.data?.items ?? []).filter(
    (n) => n.category === 'admin_operations',
  )
  const adminUnreadCount = adminNotifications.filter((n) => !n.isRead).length

  const markRead = useMutation({
    mutationFn: (publicId: string) => notificationApi.markRead(publicId),
    onSuccess: (_, publicId) => {
      markReadInStore(publicId)
      queryClient.invalidateQueries({ queryKey: ['admin-notifications'] })
    },
  })

  const markAllRead = useMutation({
    mutationFn: () => notificationApi.markAllRead(),
    onSuccess: () => {
      markAllReadInStore()
      queryClient.invalidateQueries({ queryKey: ['admin-notifications'] })
    },
  })

  return {
    notifications: adminNotifications,
    unreadCount: adminUnreadCount,
    isLoading: query.isLoading,
    markRead: markRead.mutate,
    markAllRead: markAllRead.mutate,
    isMarkingAll: markAllRead.isPending,
  }
}
