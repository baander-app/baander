import { CreateNotification, Notification } from '@/app/modules/notifications/models.ts';
import { store } from '@/app/store';
import { createNotification } from '@/app/store/notifications/notifications-slice.ts';

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