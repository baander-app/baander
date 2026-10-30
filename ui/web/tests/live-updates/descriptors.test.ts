import { describe, expect, it } from 'vitest'
import * as generated from '@/shared/api-client/gen/endpoints'
import { LIVE_COVERAGE } from '@/shared/live/coverage'
import { expandLiveFamilies, LIVE_FAMILY_DEPENDENCIES, LIVE_QUERY_DESCRIPTORS } from '@/shared/live/descriptors'
import type { LiveFamily } from '@/shared/live/protocol'

function matches(key: readonly unknown[]) {
  return LIVE_QUERY_DESCRIPTORS.filter(descriptor => descriptor.matches(key))
}

const handwrittenVariants: readonly [LiveFamily, readonly unknown[]][] = [
  ['activity', ['admin', 'activity', 'summary', 'week']],
  ['activity', ['admin', 'activity', 'top-tracks', 'month']],
  ['activity', ['admin', 'activity', 'top-artists', 'month']],
  ['activity', ['admin', 'activity', 'engagement', 'month']],
  ['admin-metadata', ['admin-album-duplicates', 'library-a']],
  ['admin-metadata', ['admin-genres', { flat: true }]],
  ['admin-metadata', ['admin-metadata-providers']],
  ['admin-metadata', ['admin', 'recommendations', 'jobs', 20]],
  ['admin-metadata', ['admin', 'recommendations', 'job', 'job-a']],
  ['admin-metadata', ['admin', 'lyrics', 'coverage']],
  ['admin-metadata', ['admin', 'lyrics', 'sync-status']],
  ['admin-metadata', ['admin-metadata-sync-status']],
  ['admin-metadata', ['admin', 'recommendations', 'coverage']],
  ['admin-metadata', ['admin', 'recommendations', 'source-quality']],
  ['admin-metadata', ['admin', 'recommendations', 'freshness']],
  ['deployment-definitions', ['config-check']],
  ['deployment-definitions', ['admin-scheduler-commands']],
  ['admin-runtime', ['server-stats']],
  ['admin-runtime', ['debug', 'coroutines']],
  ['admin-runtime', ['debug', 'workers']],
  ['admin-runtime', ['debug', 'spans', 50]],
  ['admin-jobs', ['job-list', { status: 'running' }]],
  ['admin-jobs', ['job-detail', 'job-a']],
  ['admin-jobs', ['status-overview']],
  ['admin-jobs', ['analytics-summary', '2026-10-01', '2026-10-30']],
  ['admin-jobs', ['analytics-timing', '2026-10-01', '2026-10-30']],
  ['admin-jobs', ['analytics-failures', '2026-10-01', '2026-10-30', 20]],
  ['admin-jobs', ['transport-status']],
  ['admin-jobs', ['admin-scheduler']],
  ['admin-security', ['admin-login-blocks', { limit: 20 }]],
  ['admin-security', ['rate-limiters']],
  ['admin-media', ['admin-media-storage-stats']],
  ['admin-media', ['admin-transcode-sessions']],
  ['admin-media', ['admin-media-missing-check']],
  ['radio', ['admin', 'radio', 'stations', { country: 'DK' }]],
  ['radio', ['admin', 'radio', 'countries']],
  ['radio', ['admin', 'radio', 'sources']],
  ['admin-users', ['admin-user-libraries', 'user-a']],
  ['admin-users', ['admin-users', { role: 'admin' }]],
  ['admin-alerts', ['admin-alerts']],
  ['admin-alerts', ['admin-notifications']],
  ['account', ['oauth-authorize', { client_id: 'client-a' }]],
  ['catalog', ['album-duplicates', 'album-a']],
  ['catalog', [...generated.getGetSongIndexQueryKey({ limit: 20 }), 'infinite']],
  ['activity', [...generated.getGetActivityHistoryQueryKey({ limit: 20 }), 'infinite']],
  ['radio', ['catalog', 'starred-stations']],
  ['libraries', ['libraries', 'music']],
  ['libraries', ['libraries', 'library-a']],
  ['libraries', ['libraries', 'library-a', 'stats']],
  ['notifications', ['notifications']],
  ['notifications', ['notification-unread-count']],
  ['radio', ['radio', 'countries']],
  ['radio', ['radio', 'stations', { country: 'DK', query: 'jazz' }]],
  ['radio', ['radio', 'subscriptions']],
  ['radio', ['radio', 'starred']],
  ['devices', ['devices']],
  ['sessions', ['session', 'current']],
  ['preferences', ['preference-history', 'audio']],
  ['account', ['passkeys']],
]

describe('feature query descriptors', () => {
  it.each(handwrittenVariants)('matches the %s query key %j exactly once', (family, key) => {
    expect(matches(key).map(descriptor => descriptor.family)).toEqual([family])
  })

  it('matches actual generated builders for every currently consumed generated hook', () => {
    let checked = 0
    for (const row of LIVE_COVERAGE) {
      for (const consumer of row.consumers) {
        const hook = consumer.match(/#(useGet\w+):generated$/)?.[1]
        if (!hook) continue
        const builderName = `get${hook.slice(3)}QueryKey`
        const candidate: unknown = generated[builderName as keyof typeof generated]
        expect(typeof candidate, builderName).toBe('function')
        const builder = candidate as (...arguments_: unknown[]) => readonly unknown[]
        for (const parameter of [undefined, { limit: 20 }, 'resource-a']) {
          const key = builder(parameter)
          expect(
            matches(key).map(descriptor => descriptor.family),
            `${consumer}: ${JSON.stringify(key)}`,
          ).toEqual([row.family])
        }
        checked++
      }
    }
    expect(checked).toBeGreaterThan(30)
  })

  it('excludes protocol controls, unused endpoints and adjacent nested resources', () => {
    for (const key of [
      generated.getGetLiveRecoveryQueryKey(),
      ['/api/auth/refresh'],
      ['/api/auth/ticket'],
      ['/api/albums/album-a/cover'],
      ['/api/songs/song-a/lyrics/unknown'],
      ['/api/admin/settings/definitions/unknown'],
      ['/api/admin/users/user-a/settings/unknown'],
      ['debug', 'unknown'],
      ['admin', 'recommendations', 'unknown'],
      ['admin', 'radio', 'unknown'],
      ['radio', 'unknown'],
    ]) {
      expect(matches(key), JSON.stringify(key)).toEqual([])
    }
  })

  it('keeps deployment and demand policies separate from ordinary stored refreshes', () => {
    expect(matches(generated.getGetAdminSettingsDefinitionsQueryKey())[0].deploymentFixed).toBe(true)
    expect(matches(['/api/admin/settings'])[0].family).toBe('admin-settings')
    for (const key of [['oauth-authorize'], ['/api/lyrics/search'], ['admin-media-missing-check']]) {
      expect(matches(key)[0].refreshPolicy).toBe('demand')
    }
  })

  it('preserves distinct sampling cadences within the same wire family', () => {
    const samples: readonly [readonly unknown[], number][] = [
      [['server-stats'], 5000],
      [['debug', 'workers'], 5000],
      [['debug', 'coroutines'], 5000],
      [['admin-transcode-sessions'], 5000],
      [['rate-limiters'], 5000],
      [['debug', 'spans', 20], 10000],
      [['analytics-summary'], 30000],
      [['job-list'], 5000],
      [['admin', 'activity', 'summary'], 30000],
    ]
    for (const [key, interval] of samples) {
      expect(matches(key)[0].sampleIntervalMs).toBe(interval)
    }
  })
})

describe('query family dependencies', () => {
  it('keeps the compact runtime graph equal to the feature inventory', () => {
    const expected = new Map<LiveFamily, Set<LiveFamily>>()
    for (const row of LIVE_COVERAGE) {
      if (row.dependentFamilies.length === 0) continue
      const dependents = expected.get(row.family) ?? new Set<LiveFamily>()
      for (const dependent of row.dependentFamilies) {
        dependents.add(dependent)
      }
      expected.set(row.family, dependents)
    }
    expect(Object.keys(LIVE_FAMILY_DEPENDENCIES).sort()).toEqual([...expected.keys()].sort())
    for (const [family, dependents] of expected) {
      expect([...(LIVE_FAMILY_DEPENDENCIES[family] ?? [])].sort(), family).toEqual([...dependents].sort())
    }
  })

  it('expands downstream families transitively and terminates cyclic dependencies', () => {
    expect(new Set(expandLiveFamilies(['catalog']))).toEqual(new Set([
      'catalog', 'activity', 'playlists', 'libraries', 'recommendations', 'lyrics',
    ]))
    expect(new Set(expandLiveFamilies(['account']))).toEqual(new Set([
      'account', 'admin-users', 'libraries', 'catalog', 'activity', 'playlists', 'recommendations', 'lyrics',
    ]))
    expect(expandLiveFamilies(['notifications', 'notifications', 'admin-alerts'])).toEqual([
      'notifications', 'admin-alerts',
    ])
    expect(expandLiveFamilies(['devices'])).toEqual(['devices'])
    expect(expandLiveFamilies([])).toEqual([])
  })
})
