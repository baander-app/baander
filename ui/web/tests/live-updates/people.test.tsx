import { act, renderHook } from '@testing-library/react'
import { QueryClientProvider } from '@tanstack/react-query'
import { afterEach, describe, expect, it, vi } from 'vitest'
import {
  usePersonShow,
  getPersonIndexQueryKey,
  getPersonShowQueryKey,
  getPersonCreditsQueryKey,
  getPersonMovieCreditsQueryKey,
  getPersonArtistQueryKey,
  getTvSeriesIndexQueryKey,
  getTvSeriesShowQueryKey,
  getTvSeriesEpisodesQueryKey,
  getTvEpisodeShowQueryKey,
  getTvSeriesCreditsQueryKey,
  getTvEpisodeCreditsQueryKey,
} from '@/shared/api-client/gen/endpoints'
import { catalogLiveDescriptors } from '@/features/catalog/live-descriptors'
import { OwnedQueryClient } from '@/shared/live/query-client'
import { LiveQueryCoordinator } from '@/shared/live/query-coordinator'
import type { LiveSnapshot } from '@/shared/live/recovery'

const { read } = vi.hoisted(() => ({ read: vi.fn() }))
vi.mock('@/shared/api-client/axios-instance', () => ({ customInstance: read }))
const releases: (() => void)[] = []
afterEach(() => {
  releases.splice(0).reverse().forEach(release => release())
  vi.useRealTimers()
  read.mockReset()
})

describe('Person and television live reads', () => {
  it('assigns profile, credits, music links and TV pages to exactly one sampled owner', () => {
    for (const key of [
      getPersonIndexQueryKey({ page: 2 }),
      getPersonShowQueryKey('person'),
      getPersonCreditsQueryKey('person', { page: 2, kind: 'movie' }),
      getPersonMovieCreditsQueryKey('movie', { page: 2 }),
      getPersonArtistQueryKey('artist'),
      getTvSeriesIndexQueryKey({ page: 2 }),
      getTvSeriesShowQueryKey('series'),
      getTvSeriesCreditsQueryKey('series', { page: 2 }),
      getTvSeriesEpisodesQueryKey('series', { page: 2 }),
      getTvEpisodeShowQueryKey('episode'),
      getTvEpisodeCreditsQueryKey('episode', { page: 2 }),
    ]) {
      const owners = catalogLiveDescriptors.filter(descriptor => descriptor.matches(key))
      expect(owners).toHaveLength(1)
      expect(owners[0]).toMatchObject({ family: 'catalog', refreshPolicy: 'sampled', sampleIntervalMs: 30000 })
    }
  })

  it('refreshes the real read hook and pauses sampling while hidden and after unmount', async () => {
    vi.useFakeTimers({ now: 100000 })
    const client = new OwnedQueryClient()
    const snapshot: LiveSnapshot = {
      status: 'current', authorityReady: true, families: ['catalog'],
      authorizationFingerprint: 'a'.repeat(64),
      health: { state: 'current', oldestPendingAgeMs: null },
    }
    const recovery = {
      getSnapshot: () => snapshot,
      subscribe: () => () => undefined,
      subscribeEvents: () => () => undefined,
      completeRecovery: vi.fn(),
      resourceReadFailed: vi.fn(),
    }
    const coordinator = new LiveQueryCoordinator(client, recovery, catalogLiveDescriptors)
    client.setCoordinator(coordinator)
    releases.push(() => client.dispose(), client.attach(), coordinator.start())
    read.mockResolvedValue({ data: { name: 'Before' } })
    const hook = renderHook(() => usePersonShow('person'), {
      wrapper: ({ children }) => <QueryClientProvider client={client}>{children}</QueryClientProvider>,
    })
    releases.push(() => hook.unmount())
    await act(async () => { await vi.advanceTimersByTimeAsync(200) })
    expect(hook.result.current.data?.data.name).toBe('Before')
    read.mockResolvedValue({ data: { name: 'After' } })
    await act(async () => { await vi.advanceTimersByTimeAsync(30000) })
    expect(hook.result.current.data?.data.name).toBe('After')
    coordinator.setVisible(false)
    const reads = read.mock.calls.length
    await act(async () => { await vi.advanceTimersByTimeAsync(60000) })
    expect(read).toHaveBeenCalledTimes(reads)
    hook.unmount()
    coordinator.setVisible(true)
    await act(async () => { await vi.advanceTimersByTimeAsync(60000) })
    expect(read).toHaveBeenCalledTimes(reads)
  })
})
