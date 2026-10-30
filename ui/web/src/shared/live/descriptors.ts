import { adminLiveDescriptors } from '@/features/admin/live-descriptors'
import { authLiveDescriptors } from '@/features/auth/live-descriptors'
import { catalogLiveDescriptors } from '@/features/catalog/live-descriptors'
import { libraryLiveDescriptors } from '@/features/library/live-descriptors'
import { notificationLiveDescriptors } from '@/features/notification/live-descriptors'
import { playlistLiveDescriptors } from '@/features/playlist/live-descriptors'
import { radioLiveDescriptors } from '@/features/radio/live-descriptors'
import { sessionLiveDescriptors } from '@/features/session/live-descriptors'
import { settingsLiveDescriptors } from '@/features/settings/live-descriptors'
import type { LiveQueryDescriptor } from './descriptor-types'
import type { LiveFamily } from './protocol'

export const LIVE_QUERY_DESCRIPTORS: readonly LiveQueryDescriptor[] = [
  ...adminLiveDescriptors,
  ...authLiveDescriptors,
  ...catalogLiveDescriptors,
  ...libraryLiveDescriptors,
  ...notificationLiveDescriptors,
  ...playlistLiveDescriptors,
  ...radioLiveDescriptors,
  ...sessionLiveDescriptors,
  ...settingsLiveDescriptors,
]

export const LIVE_FAMILY_DEPENDENCIES: Readonly<Partial<Record<LiveFamily, readonly LiveFamily[]>>> = {
  'admin-users': ['account', 'libraries'],
  'admin-alerts': ['notifications'],
  account: ['admin-users'],
  catalog: ['activity', 'playlists', 'libraries', 'recommendations', 'lyrics'],
  activity: ['recommendations'],
  libraries: ['catalog', 'activity', 'recommendations'],
  notifications: ['admin-alerts'],
}

/** Walk the dependency graph once, including cycles and each original family. */
export function expandLiveFamilies(families: Iterable<LiveFamily>): LiveFamily[] {
  const expanded = new Set(families)
  for (const family of expanded) {
    for (const dependent of LIVE_FAMILY_DEPENDENCIES[family] ?? []) {
      expanded.add(dependent)
    }
  }
  return [...expanded]
}
