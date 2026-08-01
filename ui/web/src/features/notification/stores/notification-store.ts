import { create } from 'zustand'
import { devtools } from 'zustand/middleware'
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
  devtools(
    (set) => ({
      notifications: [],
      unreadCount: 0,
      isPopoutOpen: false,

      setNotifications: (notifications) => set({ notifications }),

      addNotification: (notification) =>
        set((state) => ({
          notifications: [notification, ...state.notifications].slice(0, 50),
          unreadCount: state.unreadCount + (notification.isRead ? 0 : 1),
        })),

      markRead: (publicId) =>
        set((state) => ({
          notifications: state.notifications.map((n) =>
            n.publicId === publicId ? { ...n, isRead: true } : n,
          ),
          unreadCount: Math.max(0, state.unreadCount - 1),
        })),

      markAllRead: () =>
        set((state) => ({
          notifications: state.notifications.map((n) => ({ ...n, isRead: true })),
          unreadCount: 0,
        })),

      setUnreadCount: (count) => set({ unreadCount: count }),

      incrementUnreadCount: () =>
        set((state) => ({ unreadCount: state.unreadCount + 1 })),

      setPopoutOpen: (open) => set({ isPopoutOpen: open }),

      togglePopout: () =>
        set((state) => ({ isPopoutOpen: !state.isPopoutOpen })),
    }),
    { name: 'notification-store' },
  ),
)
