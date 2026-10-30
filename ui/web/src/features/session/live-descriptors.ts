import { keyStartsWith, type LiveQueryDescriptor } from '@/shared/live/descriptor-types'

export const sessionLiveDescriptors: readonly LiveQueryDescriptor[] = [
  {
    family: 'devices',
    matches: key => keyStartsWith(key, '/api/devices'),
  },
  {
    family: 'sessions',
    matches: key => keyStartsWith(key, 'session', 'current'),
  },
]
