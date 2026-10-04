import { AXIOS_INSTANCE } from '@/shared/api-client/axios-instance'

export interface NotificationItem {
  publicId: string
  category: 'security' | 'background_jobs' | 'media_changes' | 'admin_operations'
  eventType: string
  title: string | null
  body: string | null
  parameters: Record<string, unknown> | null
  isRead: boolean
  createdAt: string
}

export interface NotificationListResponse {
  data: NotificationItem[]
  nextCursor: string | null
}

export interface UnreadCountResponse {
  data: { count: number }
}

export const notificationApi = {
  list: (params?: { cursor?: string; limit?: number; category?: string; unread?: boolean }, signal?: AbortSignal) =>
    AXIOS_INSTANCE.get<NotificationListResponse>('/api/notifications/', { params, signal }).then((r) => r.data),

  unreadCount: (signal?: AbortSignal) =>
    AXIOS_INSTANCE.get<UnreadCountResponse>('/api/notifications/unread-count', { signal }).then((r) => r.data.data.count),

  markRead: (publicId: string) =>
    AXIOS_INSTANCE.patch(`/api/notifications/${publicId}/read`),

  markAllRead: () =>
    AXIOS_INSTANCE.patch('/api/notifications/read-all'),

  delete: (publicId: string) =>
    AXIOS_INSTANCE.delete(`/api/notifications/${publicId}`),
}
