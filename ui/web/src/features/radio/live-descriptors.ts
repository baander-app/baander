import { keyStartsWith, type LiveQueryDescriptor } from '@/shared/live/descriptor-types'

export const radioLiveDescriptors: readonly LiveQueryDescriptor[] = [
  {
    family: 'radio',
    matches: key => keyStartsWith(key, 'radio')
      && ['countries', 'stations', 'subscriptions', 'starred'].includes(String(key[1])),
  },
]
