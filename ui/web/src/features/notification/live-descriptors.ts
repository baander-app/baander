import { keyStartsWith, type LiveQueryDescriptor } from '@/shared/live/descriptor-types'

export const notificationLiveDescriptors: readonly LiveQueryDescriptor[] = [
  {
    family: 'notifications',
    matches: key => keyStartsWith(key, 'notifications') || keyStartsWith(key, 'notification-unread-count'),
  },
]
