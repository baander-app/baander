import { firstKeyMatches, keyStartsWith, type LiveQueryDescriptor } from '@/shared/live/descriptor-types'

export const catalogLiveDescriptors: readonly LiveQueryDescriptor[] = [
  {
    family: 'catalog',
    matches: key => keyStartsWith(key, 'album-duplicates')
      || keyStartsWith(key, 'catalog', 'timeline')
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
    matches: key => keyStartsWith(key, '/api/activity/history') || keyStartsWith(key, '/api/user/recent'),
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
