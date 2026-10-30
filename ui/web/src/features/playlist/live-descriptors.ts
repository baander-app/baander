import { firstKeyMatches, type LiveQueryDescriptor } from '@/shared/live/descriptor-types'

export const playlistLiveDescriptors: readonly LiveQueryDescriptor[] = [
  {
    family: 'playlists',
    matches: key => firstKeyMatches(key, /^\/api\/playlists\/(?:[^/]+)?$/),
  },
]
