import captureSources from '../../../../tests/Fixtures/LiveUpdates/capture-sources.json'
import { LIVE_PROTOCOL_CONTROLS } from '@/shared/live/coverage-assets'
import { LIVE_COVERAGE } from '@/shared/live/coverage'
import { LIVE_FAMILIES } from '@/shared/live/protocol'
import { describe, expect, it } from 'vitest'
import { consumerId, findConsumers, unclassified } from './coverage-census'

describe('live query coverage census', () => {
  it('rejects a new consuming hook including an aliased import', () => {
    const consumers = findConsumers('src/features/catalog/NewView.tsx', `
      import { useGetSongIndex as useSongs } from '@/shared/api-client/gen/endpoints'
      export function View() { return useSongs({ limit: 20 }) }
    `)
    expect(unclassified(consumers, [])).toEqual([
      'src/features/catalog/NewView.tsx#useGetSongIndex:generated',
    ])
  })

  it('distinguishes filtered and infinite keys and list/count variants', () => {
    const consumers = findConsumers('src/features/catalog/hooks.ts', `
      import { useQuery, useInfiniteQuery } from '@tanstack/react-query'
      useQuery({ queryKey: ['songs', filters], queryFn: read })
      useInfiniteQuery({ queryKey: ['songs', filters, 'infinite'], queryFn: read })
      useQuery({ queryKey: ['notification-unread-count'], queryFn: count })
    `)
    expect(consumers.map((consumer) => consumer.variant)).toEqual([
      "['songs',filters]", "['songs',filters,'infinite']", "['notification-unread-count']",
    ])
    expect(unclassified(consumers, consumers.map(consumerId))).toEqual([])
  })

  it('detects changed constant keys and generated-hook key overrides', () => {
    const original = `import { useQuery } from '@tanstack/react-query'; const KEY = ['songs']; useQuery({ queryKey: KEY, queryFn: read })`
    const changed = original.replace("['songs']", "['other']")
    expect(unclassified(findConsumers('src/view.ts', changed), findConsumers('src/view.ts', original).map(consumerId))).toHaveLength(1)
    const custom = findConsumers('src/view.ts', `
      import * as api from '@/shared/api-client/gen/endpoints'
      api.useGetSongIndex({}, { query: { queryKey: ['custom-songs'] } })
    `)
    expect(custom[0].variant).toBe("generated;queryKey=['custom-songs']")
  })

  it('ignores unused generated exports and imports', () => {
    expect(findConsumers('src/shared/api-client/gen/endpoints/index.ts', `
      export function useGetUnused() { return useQuery({ queryKey: ['unused'] }) }
    `)).toEqual([])
    expect(findConsumers('src/features/catalog/Page.tsx', `
      import { useGetUnused } from '@/shared/api-client/gen/endpoints'
    `)).toEqual([])
  })

  it('rejects direct persistent readers independently of Query', () => {
    const consumers = findConsumers('src/features/layout/recents.ts', `
      import { AXIOS_INSTANCE as client } from '@/shared/api-client/axios-instance'
      client.get('/api/user/recent', { params })
    `)
    expect(unclassified(consumers, [])).toEqual([
      "src/features/layout/recents.ts#AXIOS_INSTANCE.get:'/api/user/recent'",
    ])
  })
})

const proofSources = import.meta.glob(['../../src/**/*.{test,spec}.{ts,tsx}', '../../tests/**/*.{test,spec}.{ts,tsx}'], {
  query: '?raw',
  import: 'default',
  eager: true,
}) as Record<string, string>

const applicationSources = import.meta.glob('../../src/**/*.{ts,tsx}', {
  query: '?raw',
  import: 'default',
  eager: true,
}) as Record<string, string>

function applicationConsumers() {
  return Object.entries(applicationSources).flatMap(([file, source]) =>
    findConsumers(file.replace('../../', ''), source),
  )
}

describe('registered application coverage', () => {
  it('classifies every current query owner and direct reader exactly once', () => {
    const registered = [...LIVE_COVERAGE.flatMap((descriptor) => [...descriptor.consumers]), ...LIVE_PROTOCOL_CONTROLS.map(control => control.consumer)]
    const actual = applicationConsumers()
    expect(actual.length).toBeGreaterThan(100)
    expect(unclassified(actual, registered)).toEqual([])
    const actualIds = new Set(actual.map(consumerId))
    expect(registered.filter((id) => !actualIds.has(id))).toEqual([])
    expect(new Set(registered).size).toBe(registered.length)
  })

  it('classifies authorization recovery as a protocol control outside resource refresh coverage', () => {
    expect(LIVE_PROTOCOL_CONTROLS).toEqual([expect.objectContaining({
      consumer: 'src/shared/live/recovery.ts#getLiveRecovery:generated',
      purpose: 'authorization-recovery',
    })])
    const resources = LIVE_COVERAGE.flatMap(row => [...row.consumers])
    expect(resources).not.toContain(LIVE_PROTOCOL_CONTROLS[0].consumer)
  })

  it('requires classification for a consuming hook added inside an already covered file', () => {
    const file = 'src/features/catalog/pages/HomePage.tsx'
    const source = applicationSources[`../../${file}`]
    const changed = `${source}\nimport { useGetNewEndpoint } from '@/shared/api-client/gen/endpoints'\nuseGetNewEndpoint()`
    expect(unclassified(findConsumers(file, changed), LIVE_COVERAGE.flatMap((row) => [...row.consumers])))
      .toEqual([`${file}#useGetNewEndpoint:generated`])
  })

  it('records list/count and URL/infinite/filtered variants together', () => {
    const notificationKeys = LIVE_COVERAGE.filter((row) => row.family === 'notifications').flatMap((row) => [...row.keyVariants])
    expect(notificationKeys.some((key) => key.includes("['notifications']"))).toBe(true)
    expect(notificationKeys.some((key) => key.includes("['notification-unread-count']"))).toBe(true)
    const catalogKeys = LIVE_COVERAGE.filter((row) => row.family === 'catalog').flatMap((row) => [...row.keyVariants])
    expect(catalogKeys.some((key) => key.includes('/api/songs/'))).toBe(true)
    expect(catalogKeys.some((key) => key.includes("getGetSongIndexQueryKey(params),'infinite'"))).toBe(true)
    expect(catalogKeys.some((key) => key.includes('params ? [params]'))).toBe(true)
  })

  it('references reviewed storage tables and response-cache policies', () => {
    const tables = new Set(captureSources.sources.map((source) => source.table))
    const cachePolicies = new Set(Object.keys(captureSources.response_caches))
    for (const descriptor of LIVE_COVERAGE) {
      expect(descriptor.sources.length, descriptor.id).toBeGreaterThan(0)
      for (const table of [...descriptor.sourceTables, ...descriptor.deploymentTables]) expect(tables.has(table), `${descriptor.id}: ${table}`).toBe(true)
      for (const table of descriptor.sourceTables) expect(captureSources.sources.find((source) => source.table === table)?.classification, `${descriptor.id}: ${table}`).toBe('capture')
      for (const table of descriptor.deploymentTables) expect(captureSources.sources.find((source) => source.table === table)?.classification, `${descriptor.id}: ${table}`).toBe('excluded')
      for (const cache of descriptor.responseCaches) expect(cachePolicies.has(cache), `${descriptor.id}: ${cache}`).toBe(true)
    }
  })

  it('declares the agreed vocabulary, explicit policies and existing unit proof paths', () => {
    expect([...new Set(LIVE_COVERAGE.map((row) => row.family))].sort()).toEqual([...LIVE_FAMILIES].sort())
    expect(new Set(LIVE_COVERAGE.map((row) => row.id)).size).toBe(LIVE_COVERAGE.length)
    expect(new Set(LIVE_COVERAGE.map((row) => row.policy.kind))).toEqual(new Set(['stored', 'sampled', 'demand', 'deployment']))
    for (const descriptor of LIVE_COVERAGE) {
      expect(descriptor.policy.reason.length).toBeGreaterThan(20)
      expect(descriptor.audiences.length).toBeGreaterThan(0)
      expect(descriptor.consumers.length).toBeGreaterThan(0)
      expect(Object.keys(descriptor.behaviorVerification), descriptor.id).toEqual(['unitProofs'])
      expect(descriptor.behaviorVerification.unitProofs.length, descriptor.id).toBeGreaterThan(0)
      for (const proof of descriptor.behaviorVerification.unitProofs) {
        const source = Object.entries(proofSources).find(([file]) =>
          new URL(file, import.meta.url).pathname.endsWith(`/${proof}`),
        )?.[1]
        expect(source, `${descriptor.id}: ${proof}`).toBeDefined()
      }
      expect(descriptor.coverageVerification).toContain('tests/live-updates/coverage.test.ts')
      if (descriptor.policy.kind === 'stored') expect(descriptor.sourceTables.length).toBeGreaterThan(0)
    }
  })
})
