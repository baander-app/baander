import { useMutation, useQueryClient, type InfiniteData } from '@tanstack/react-query'
import { notificationApi, type NotificationListResponse } from '../api/notification-api'
import { useNotificationStore } from '../stores/notification-store'
import { notificationsKey, unreadCountKey } from './use-notifications'

export function useNotificationActions() {
  const queryClient = useQueryClient()

  const cancelReads = () => Promise.all([
    queryClient.cancelQueries({ queryKey: notificationsKey }),
    queryClient.cancelQueries({ queryKey: unreadCountKey }),
  ])
  const updateRead = (publicId?: string) => {
    const store = useNotificationStore.getState()
    const wasUnread = publicId !== undefined && store.notifications.some((item) => item.publicId === publicId && !item.isRead)
    const count = publicId === undefined ? 0 : Math.max(0, store.unreadCount - (wasUnread ? 1 : 0))
    const markItems = (items: NotificationListResponse['data']) => items.map((item) =>
      publicId === undefined || item.publicId === publicId ? { ...item, isRead: true } : item)
    queryClient.setQueryData<InfiniteData<NotificationListResponse>>(notificationsKey, (data) => data && ({
      ...data,
      pages: data.pages.map((page) => ({ ...page, data: markItems(page.data) })),
    }))
    queryClient.setQueryData(unreadCountKey, count)
    store.setNotifications(markItems(store.notifications))
    store.setUnreadCount(count)
  }
  const invalidate = () => Promise.all([
    queryClient.invalidateQueries({ queryKey: notificationsKey }),
    queryClient.invalidateQueries({ queryKey: unreadCountKey }),
    queryClient.invalidateQueries({ queryKey: ['admin-notifications'] }),
    queryClient.invalidateQueries({ queryKey: ['admin-alerts'] }),
  ])

  const markRead = useMutation({
    mutationFn: (publicId: string) => notificationApi.markRead(publicId),
    onMutate: cancelReads,
    onSuccess: async (_, publicId) => {
      await cancelReads()
      updateRead(publicId)
    },
    onSettled: invalidate,
  })
  const markAllRead = useMutation({
    mutationFn: () => notificationApi.markAllRead(),
    onMutate: cancelReads,
    onSuccess: async () => {
      await cancelReads()
      updateRead()
    },
    onSettled: invalidate,
  })
  return { markRead, markAllRead }
}
