import { useMutation, useQueryClient, type InfiniteData } from '@tanstack/react-query'
import { notificationApi, type NotificationListResponse } from '../api/notification-api'
import { useNotificationStore } from '../stores/notification-store'
import { useLiveCoordinator } from '@/shared/live/context'
import { captureOwnership, isCurrentOwnership } from '@/shared/live/ownership'
import { notificationsKey, unreadCountKey } from './use-notifications'

export function useNotificationActions() {
  const queryClient = useQueryClient()
  const coordinator = useLiveCoordinator()

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

  const performRead = async (publicId?: string) => {
    const owner = captureOwnership()
    const release = coordinator?.beginMutation(['notifications', 'admin-alerts'])
    try {
      await cancelReads()
      if (!isCurrentOwnership(owner)) return
      const response = publicId === undefined
        ? await notificationApi.markAllRead()
        : await notificationApi.markRead(publicId)
      if (!isCurrentOwnership(owner)) return response
      await cancelReads()
      if (isCurrentOwnership(owner)) updateRead(publicId)
      return response
    } finally {
      release?.()
    }
  }
  const markRead = useMutation({
    mutationFn: (publicId: string) => performRead(publicId),
    onSettled: () => { if (!coordinator) return invalidate() },
  })
  const markAllRead = useMutation({
    mutationFn: () => performRead(),
    onSettled: () => { if (!coordinator) return invalidate() },
  })
  return { markRead, markAllRead }
}
