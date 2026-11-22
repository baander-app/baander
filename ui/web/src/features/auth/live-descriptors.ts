import { keyStartsWith, type LiveQueryDescriptor } from '@/shared/live/descriptor-types'

export const authLiveDescriptors: readonly LiveQueryDescriptor[] = [
  {
    family: 'account',
    matches: key => keyStartsWith(key, '/api/auth/me'),
  },
  {
    family: 'account',
    refreshPolicy: 'demand',
    matches: key => keyStartsWith(key, 'oauth-authorize'),
  },
]
