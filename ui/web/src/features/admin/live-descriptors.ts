import { firstKeyMatches, keyStartsWith, type LiveQueryDescriptor } from '@/shared/live/descriptor-types'

export const adminLiveDescriptors: readonly LiveQueryDescriptor[] = [
  {
    family: 'activity',
    sampleIntervalMs: 30000,
    matches: key => keyStartsWith(key, 'admin', 'activity')
      && ['summary', 'top-tracks', 'top-artists', 'engagement'].includes(String(key[2])),
  },
  {
    family: 'admin-metadata',
    matches: key => [
      'admin-album-duplicates',
      'admin-genres',
      'admin-metadata-providers',
    ].some(prefix => keyStartsWith(key, prefix))
      || (keyStartsWith(key, 'admin', 'recommendations') && (key[2] === 'jobs' || key[2] === 'job')),
  },
  {
    family: 'admin-metadata',
    sampleIntervalMs: 30000,
    matches: key => keyStartsWith(key, 'admin-metadata-sync-status')
      || (keyStartsWith(key, 'admin', 'lyrics') && (key[2] === 'coverage' || key[2] === 'sync-status'))
      || (keyStartsWith(key, 'admin', 'recommendations')
        && ['coverage', 'source-quality', 'freshness'].includes(String(key[2]))),
  },
  {
    family: 'deployment-definitions',
    deploymentFixed: true,
    matches: key => [
      'config-check',
      'admin-scheduler-commands',
      '/api/admin/settings/definitions',
    ].some(prefix => keyStartsWith(key, prefix)),
  },
  {
    family: 'admin-runtime',
    sampleIntervalMs: 5000,
    matches: key => keyStartsWith(key, 'server-stats')
      || keyStartsWith(key, 'debug', 'coroutines')
      || keyStartsWith(key, 'debug', 'workers'),
  },
  {
    family: 'admin-runtime',
    sampleIntervalMs: 10000,
    matches: key => keyStartsWith(key, 'debug', 'spans'),
  },
  {
    family: 'admin-jobs',
    sampleIntervalMs: 5000,
    matches: key => ['job-list', 'job-detail', 'status-overview'].some(prefix => keyStartsWith(key, prefix)),
  },
  {
    family: 'admin-jobs',
    sampleIntervalMs: 30000,
    matches: key => [
      'analytics-summary',
      'analytics-timing',
      'analytics-failures',
      'transport-status',
    ].some(prefix => keyStartsWith(key, prefix)),
  },
  {
    family: 'admin-jobs',
    matches: key => keyStartsWith(key, 'admin-scheduler'),
  },
  {
    family: 'admin-security',
    matches: key => keyStartsWith(key, 'admin-login-blocks'),
  },
  {
    family: 'admin-security',
    sampleIntervalMs: 5000,
    matches: key => keyStartsWith(key, 'rate-limiters'),
  },
  {
    family: 'admin-media',
    sampleIntervalMs: 5000,
    matches: key => keyStartsWith(key, 'admin-media-storage-stats')
      || keyStartsWith(key, 'admin-transcode-sessions'),
  },
  {
    family: 'admin-media',
    refreshPolicy: 'demand',
    matches: key => keyStartsWith(key, 'admin-media-missing-check'),
  },
  {
    family: 'radio',
    matches: key => keyStartsWith(key, 'admin', 'radio')
      && ['stations', 'countries', 'sources'].includes(String(key[2])),
  },
  {
    family: 'admin-users',
    matches: key => [
      'admin-user-libraries',
      'admin-users',
      '/api/admin/oauth/clients',
    ].some(prefix => keyStartsWith(key, prefix))
      || firstKeyMatches(key, /^\/api\/admin\/users\/[^/]+\/settings$/),
  },
  {
    family: 'admin-alerts',
    matches: key => keyStartsWith(key, 'admin-alerts') || keyStartsWith(key, 'admin-notifications'),
  },
  {
    family: 'admin-settings',
    matches: key => keyStartsWith(key, '/api/admin/settings'),
  },
]
