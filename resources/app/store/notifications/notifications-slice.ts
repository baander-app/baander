import { CreateNotification, Notification } from '@/modules/notifications/models.ts';
import { createSlice, PayloadAction } from '@reduxjs/toolkit';

export interface NotificationsSlice {
  notifications: Notification[];
}

const initialState: NotificationsSlice = {
  notifications: [],
};

export const notificationsSlice = createSlice({
  name: 'notifications',
  initialState,
  reducers: {
    createNotification: (state, action: PayloadAction<CreateNotification>) => {
      const notification: Notification = {
        id: self.crypto.randomUUID(),
        ...action.payload,
        read: false,
        createdAt: new Date(),
      }

      state.notifications.push(notification);
    },
    removeNotification: (state, action: PayloadAction<{id: string}>) => {
      state.notifications = state.notifications.filter(notification => notification.id !== action.payload.id);
    },
    clearNotifications: (state) => {
      state.notifications = [];
    },
    markAsRead: (state, action: PayloadAction<{ id: string }>) => {
      state.notifications = state.notifications.map(notification => {
        if (notification.id === action.payload.id) {
          return {
            ...notification,
            isRead: true,
          };
        }
        return notification;
      });
    },
  },
});

export const {
  createNotification,
  removeNotification,
  clearNotifications,
  markAsRead,
} = notificationsSlice.actions;
