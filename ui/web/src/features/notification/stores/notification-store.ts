import { withNoopGuard } from '@/shared/stores/with-noop-guard'
import { withStoreDebug } from '@/shared/stores/debug'
import { create } from 'zustand'
import type { NotificationItem } from '../api/notification-api'

interface NotificationState {
  notifications: NotificationItem[]
  unreadCount: number
  isPopoutOpen: boolean

  setNotifications: (notifications: NotificationItem[]) => void
  addNotification: (notification: NotificationItem) => void
  markRead: (publicId: string) => void
  markAllRead: () => void
  setUnreadCount: (count: number) => void
  incrementUnreadCount: () => void
  setPopoutOpen: (open: boolean) => void
  togglePopout: () => void
}

export const useNotificationStore = create<NotificationState>()(
  withStoreDebug('notification.notification',
    withNoopGuard((set) => ({
      notifications: [],
      unreadCount: 0,
      isPopoutOpen: false,

      setNotifications: (notifications) => set((state) => state.notifications === notifications ? state : { notifications }),

      addNotification: (notification) =>
        set((state) => state.notifications.some((item) => item.publicId === notification.publicId) ? state : ({
          notifications: [notification, ...state.notifications].slice(0, 50),
          unreadCount: state.unreadCount + (notification.isRead ? 0 : 1),
        })),

      markRead: (publicId) =>
        set((state) => !state.notifications.some((item) => item.publicId === publicId && !item.isRead) ? state : ({
          notifications: state.notifications.map((n) =>
            n.publicId === publicId ? { ...n, isRead: true } : n,
          ),
          unreadCount: Math.max(0, state.unreadCount - 1),
        })),

      markAllRead: () =>
        set((state) => state.unreadCount === 0 && state.notifications.every((item) => item.isRead) ? state : ({
          notifications: state.notifications.map((n) => n.isRead ? n : { ...n, isRead: true }),
          unreadCount: 0,
        })),

      setUnreadCount: (count) => set((state) => state.unreadCount === count ? state : { unreadCount: count }),

      incrementUnreadCount: () =>
        set((state) => ({ unreadCount: state.unreadCount + 1 })),

      setPopoutOpen: (open) => set((state) => state.isPopoutOpen === open ? state : { isPopoutOpen: open }),

      togglePopout: () =>
        set((state) => ({ isPopoutOpen: !state.isPopoutOpen })),
    })),
  ),
)
