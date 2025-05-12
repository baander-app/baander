import { CreateNotification, Notification } from '@/modules/notifications/models.ts';
import { store } from '@/store';
import { createNotification } from '@/store/notifications/notifications-slice.ts';

export class NotificationFacade {
  static create(options: CreateNotification) {
    const notification: Notification = {
      id: self.crypto.randomUUID(),
      ...options,
      read: false,
      createdAt: new Date(),
    }

    store.dispatch(createNotification(notification))
  }
}