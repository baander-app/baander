import {
  CreateNotification,
  CreateToast,
  isToastOptions,
  Notification,
  ToastModel,
} from '@/modules/notifications/models.ts';
import { createSlice, PayloadAction } from '@reduxjs/toolkit';


export interface NotificationsSlice {
  notifications: Notification[];
  toasts: ToastModel[];
}

const initialState: NotificationsSlice = {
  notifications: [],
  toasts: [],
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
      };

      if (notification.toast) {
        const toast: ToastModel = {
          id: notification.id,
          title: notification?.title,
          message: notification.message,
          type: notification.type,
          duration: isToastOptions(notification.toast) ? notification.toast.duration : 3000,
        };
        state.toasts.push(toast);
      }

      state.notifications.unshift(notification);
    },
    removeNotification: (state, action: PayloadAction<{ id: string }>) => {
      state.notifications = state.notifications.filter(notification => notification.id !== action.payload.id);
    },
    clearNotifications: (state) => {
      state.notifications = [];
    },
    markNotificationAsRead: (state, action: PayloadAction<{ id: string }>) => {
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
    createToast: (state, action: PayloadAction<CreateToast>) => {
      const toast: ToastModel = {
        ...action.payload,
        id: self.crypto.randomUUID(),
      };

      state.toasts.push(toast);
    },
    removeToast: (state, action: PayloadAction<{ id: string }>) => {
      state.toasts = state.toasts.filter(toast => toast.id !== action.payload.id);
    },
  },
});

export const {
  createNotification,
  removeNotification,
  clearNotifications,
  markNotificationAsRead,
  createToast,
  removeToast,
} = notificationsSlice.actions;
