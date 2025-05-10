export type NotificationType = 'success' | 'error' | 'info' | 'warning';

export interface ToastModel {
  id: string;
  message: string;
  type: NotificationType;
  title?: string;
  duration?: number;
}

export type CreateToast = Omit<ToastModel, 'id'>;

export interface ToastOptions {
  duration?: number;
}

export function isToastOptions(value: any): value is ToastOptions {
  return value && typeof value.duration === 'number';
}

export interface Notification {
  id: string;
  type: NotificationType;
  title?: string;
  message: string;
  read: boolean;
  /**
   * Nudge the user with a toast as well
   */
  toast?: ToastOptions | boolean;
  createdAt: Date;
  updatedAt?: Date;
}

export type CreateNotification = Omit<Notification, 'id' | 'read' | 'createdAt' | 'updatedAt'>;