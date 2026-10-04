import { useEffect } from 'react'
import { useInfiniteQuery, useQuery } from '@tanstack/react-query'
import { notificationApi, type NotificationItem } from '../api/notification-api'
import { useNotificationStore } from '../stores/notification-store'

export const notificationsKey = ['notifications'] as const
export const unreadCountKey = ['notification-unread-count'] as const

export function useNotifications() {
  const setNotifications = useNotificationStore((s) => s.setNotifications)
  const setUnreadCount = useNotificationStore((s) => s.setUnreadCount)
  const notifications = useNotificationStore((s) => s.notifications)
  const unreadCount = useNotificationStore((s) => s.unreadCount)

  const query = useInfiniteQuery({
    queryKey: notificationsKey,
    initialPageParam: undefined as string | undefined,
    queryFn: ({ pageParam, signal }) => notificationApi.list({ limit: 20, cursor: pageParam }, signal),
    getNextPageParam: (lastPage) => lastPage.nextCursor ?? undefined,
    staleTime: 30_000,
  })
  const countQuery = useQuery({
    queryKey: unreadCountKey,
    queryFn: ({ signal }) => notificationApi.unreadCount(signal),
    staleTime: 30_000,
  })

  useEffect(() => {
    if (query.data) {
      const items = new Map<string, NotificationItem>()
      for (const page of query.data.pages) {
        for (const item of page.data) {
          if (!items.has(item.publicId)) items.set(item.publicId, item)
        }
      }
      setNotifications([...items.values()])
    }
  }, [query.data, setNotifications])
  useEffect(() => {
    if (countQuery.data !== undefined) setUnreadCount(countQuery.data)
  }, [countQuery.data, setUnreadCount])

  return {
    notifications,
    unreadCount,
    isLoading: query.isLoading,
    isError: query.isError,
    hasNextPage: query.hasNextPage,
    isFetchingNextPage: query.isFetchingNextPage,
    isFetchNextPageError: query.isFetchNextPageError,
    loadMore: () => {
      if (query.hasNextPage && !query.isFetching) void query.fetchNextPage()
    },
    refetch: query.refetch,
  }
}
