export type NotificationType = 'success' | 'error' | 'info' | 'warning';

export interface Notification {
  id: string;
  type: NotificationType;
  message: string;
  read: boolean;
  createdAt: Date;
  updatedAt?: Date;
}

export type CreateNotification = Omit<Notification, 'id' | 'read' | 'createdAt' | 'updatedAt'>;