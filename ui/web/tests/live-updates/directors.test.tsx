import { act, renderHook } from '@testing-library/react'
import { QueryClientProvider } from '@tanstack/react-query'
import { afterEach, describe, expect, it, vi } from 'vitest'
import {
  getGetMovieDirectorIndexQueryKey,
  getGetMovieDirectorMoviesQueryKey,
  getGetMovieDirectorShowQueryKey,
  useGetMovieDirectorIndex,
  useGetMovieDirectorMovies,
  useGetMovieDirectorShow,
} from '@/shared/api-client/gen/endpoints'
import { catalogLiveDescriptors } from '@/features/catalog/live-descriptors'
import { OwnedQueryClient } from '@/shared/live/query-client'
import { LiveQueryCoordinator } from '@/shared/live/query-coordinator'
import type { LiveSnapshot } from '@/shared/live/recovery'

const { read } = vi.hoisted(() => ({ read: vi.fn() }))
vi.mock('@/shared/api-client/axios-instance', () => ({ customInstance: read }))

const resources: (() => void)[] = []
afterEach(() => {
  for (const release of resources.splice(0).reverse()) release()
  vi.useRealTimers()
  read.mockReset()
})

function setup() {
  vi.useFakeTimers({ now: 100_000 })
  const client = new OwnedQueryClient()
  const snapshot: LiveSnapshot = {
    status: 'current',
    authorityReady: true,
    families: ['catalog'],
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
  resources.push(() => client.dispose(), client.attach(), coordinator.start())
  return { client, coordinator }
}

describe('director live query ownership', () => {
  it('assigns each generated key, including filtered pages, to one sampled owner', () => {
    const keys = [
      getGetMovieDirectorIndexQueryKey({ q: 'Varda', page: 2 }),
      getGetMovieDirectorShowQueryKey('director-public-id'),
      getGetMovieDirectorMoviesQueryKey('director-public-id', { q: 'Cléo', page: 3 }),
    ]
    for (const key of keys) {
      const owners = catalogLiveDescriptors.filter(descriptor => descriptor.matches(key))
      expect(owners).toHaveLength(1)
      expect(owners[0]).toMatchObject({
        family: 'catalog',
        refreshPolicy: 'sampled',
        sampleIntervalMs: 30_000,
      })
    }
    const movieOwners = catalogLiveDescriptors.filter(descriptor => (
      descriptor.matches(['/api/movies/movie-public-id'])
    ))
    expect(movieOwners).toHaveLength(1)
    expect(movieOwners[0].refreshPolicy).toBeUndefined()
    expect(catalogLiveDescriptors.some(descriptor => (
      descriptor.matches(['/api/movies/directors/director-public-id/unknown'])
    ))).toBe(false)
  })

  it('samples real directory, detail and movie hooks, pauses hidden views and stops after unmount', async () => {
    const { client, coordinator } = setup()
    let revision = 'Before'
    const meta = { current_page: 2, last_page: 2, per_page: 50, total: 51 }
    read.mockImplementation(async (url: string) => {
      const director = {
        publicId: 'director-public-id',
        name: revision,
        tmdbId: 101,
        profileUrl: null,
        movieCount: revision === 'Before' ? 1 : 2,
      }
      if (url.includes('/movies?')) {
        return { data: [{ publicId: 'movie-public-id', title: revision }], meta }
      }
      if (url.includes('/directors?')) return { data: [director], meta }
      return { data: director }
    })
    const hook = renderHook(() => ({
      directory: useGetMovieDirectorIndex({ q: 'mounted', page: 2 }),
      director: useGetMovieDirectorShow('director-public-id'),
      movies: useGetMovieDirectorMovies('director-public-id', { page: 2 }),
    }), {
      wrapper: ({ children }) => <QueryClientProvider client={client}>{children}</QueryClientProvider>,
    })
    resources.push(() => hook.unmount())
    await act(async () => { await vi.advanceTimersByTimeAsync(200) })
    expect(hook.result.current.directory.data?.data).toEqual([
      expect.objectContaining({ name: 'Before', movieCount: 1 }),
    ])
    expect(hook.result.current.director.data?.data.name).toBe('Before')
    expect(hook.result.current.movies.data?.data).toEqual([
      expect.objectContaining({ title: 'Before' }),
    ])
    revision = 'After'
    await act(async () => { await vi.advanceTimersByTimeAsync(30_000) })
    expect(hook.result.current.directory.data?.data).toEqual([
      expect.objectContaining({ name: 'After', movieCount: 2 }),
    ])
    expect(hook.result.current.director.data?.data.name).toBe('After')
    expect(hook.result.current.movies.data?.data).toEqual([
      expect.objectContaining({ title: 'After' }),
    ])
    const readsBeforeHiding = read.mock.calls.length
    coordinator.setVisible(false)
    revision = 'While hidden'
    await act(async () => { await vi.advanceTimersByTimeAsync(60_000) })
    expect(read).toHaveBeenCalledTimes(readsBeforeHiding)
    coordinator.setVisible(true)
    await act(async () => { await vi.advanceTimersByTimeAsync(200) })
    expect(hook.result.current.director.data?.data.name).toBe('While hidden')
    hook.unmount()
    const readsAfterClose = read.mock.calls.length
    await act(async () => { await vi.advanceTimersByTimeAsync(60_000) })
    expect(read).toHaveBeenCalledTimes(readsAfterClose)
  })
})
