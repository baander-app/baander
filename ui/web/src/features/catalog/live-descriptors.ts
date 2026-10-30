import { firstKeyMatches, keyStartsWith, type LiveQueryDescriptor } from '@/shared/live/descriptor-types'

export const catalogLiveDescriptors: readonly LiveQueryDescriptor[] = [
  {
    family: 'catalog',
    matches: key => keyStartsWith(key, 'album-duplicates')
      || firstKeyMatches(key, /^\/api\/(albums|artists|songs|movies)\/(?:[^/]+)?$/)
      || keyStartsWith(key, '/api/genres/'),
  },
  {
    family: 'lyrics',
    matches: key => firstKeyMatches(key, /^\/api\/songs\/[^/]+\/lyrics$/),
  },
  {
    family: 'lyrics',
    refreshPolicy: 'demand',
    matches: key => keyStartsWith(key, '/api/lyrics/search'),
  },
  {
    family: 'activity',
    matches: key => keyStartsWith(key, '/api/activity/history'),
  },
  {
    family: 'recommendations',
    matches: key => keyStartsWith(key, '/api/recommendations/')
      || keyStartsWith(key, '/api/recommendations/for-you'),
  },
  {
    family: 'radio',
    matches: key => keyStartsWith(key, 'catalog', 'starred-stations'),
  },
]
