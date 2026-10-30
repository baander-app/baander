import { keyStartsWith, type LiveQueryDescriptor } from '@/shared/live/descriptor-types'

export const settingsLiveDescriptors: readonly LiveQueryDescriptor[] = [
  {
    family: 'preferences',
    matches: key => keyStartsWith(key, 'preference-history') || keyStartsWith(key, '/api/user/settings'),
  },
  {
    family: 'account',
    matches: key => keyStartsWith(key, 'passkeys'),
  },
]
