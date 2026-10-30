import { keyStartsWith, type LiveQueryDescriptor } from '@/shared/live/descriptor-types'

export const libraryLiveDescriptors: readonly LiveQueryDescriptor[] = [
  {
    family: 'libraries',
    matches: key => keyStartsWith(key, 'libraries'),
  },
]
