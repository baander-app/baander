import { act, cleanup, fireEvent, render, screen } from '@testing-library/react'
import { QueryClientProvider } from '@tanstack/react-query'
import { AxiosError } from 'axios'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { ThemeProvider } from 'styled-components'
import { resolveTheme } from '@/shared/theme'
import { InfoPanel } from '../InfoPanel'
import { OwnedQueryClient } from '@/shared/live/query-client'
import { LiveQueryCoordinator } from '@/shared/live/query-coordinator'
import { LiveCoordinatorContext } from '@/shared/live/context'
import { catalogLiveDescriptors } from '@/features/catalog/live-descriptors'
import type { LiveSnapshot } from '@/shared/live/recovery'
import { advanceAuthorization, synchronizeOwnership } from '@/shared/live/ownership'
import { getGetAlbumShowQueryKey } from '@/shared/api-client/gen/endpoints'

const request = vi.hoisted(() => vi.fn())
vi.mock('@/shared/api-client/axios-instance', () => ({ customInstance: request }))
vi.mock('sonner', () => ({ toast: { success: vi.fn(), error: vi.fn() } }))

const album = (title: string) => ({ data: { publicId: 'AlbumFixture1', title, label: 'Server label', year: 1969, lockedFields: [], coverImage: null } })

function setup(coordinated = false, seeded = true) {
  const client = new OwnedQueryClient()
  client.setDefaultOptions({ queries: { retry: false, staleTime: Infinity } })
  const detach = client.attach()
  const snapshot: LiveSnapshot = {
    status: 'degraded', authorityReady: true, families: ['catalog'],
    authorizationFingerprint: 'a'.repeat(64), health: { state: 'current', oldestPendingAgeMs: null },
  }
  const coordinator = new LiveQueryCoordinator(client, {
    getSnapshot: () => snapshot,
    subscribe: () => () => {},
    subscribeEvents: () => () => {},
    completeRecovery: () => {},
    resourceReadFailed: () => {},
  }, catalogLiveDescriptors)
  if (coordinated) client.setCoordinator(coordinator)
  if (seeded) client.setQueryData(getGetAlbumShowQueryKey('AlbumFixture1'), album('Server title'))
  render(<QueryClientProvider client={client}><LiveCoordinatorContext.Provider value={coordinated ? coordinator : null}><ThemeProvider theme={resolveTheme('dark', 'violet')}><InfoPanel entityType="album" publicId="AlbumFixture1" /></ThemeProvider></LiveCoordinatorContext.Provider></QueryClientProvider>)
  const stop = coordinated ? coordinator.start() : () => {}
  return {
    client,
    coordinator,
    dispose: () => {
      stop()
      detach()
      client.dispose()
    },
  }
}

beforeEach(() => {
  vi.useFakeTimers()
  synchronizeOwnership('metadata-dom')
  request.mockReset().mockResolvedValue(album('Server title'))
})

afterEach(() => {
  cleanup()
  synchronizeOwnership(null)
  vi.useRealTimers()
})

describe('metadata panel server refreshes', () => {
  it('waits for the selected entity while its initial read is deferred', async () => {
    const { dispose } = setup(true, false)
    try {
      expect(screen.queryByText('Failed to load album')).toBeNull()
      expect(request).not.toHaveBeenCalled()
      await act(async () => { await vi.advanceTimersByTimeAsync(1000) })
      expect(screen.getByDisplayValue('Server title')).toBeInTheDocument()
    } finally { dispose() }
  })

  it('shows a conflict, retains text, and lets the user explicitly keep their edits', async () => {
    const { client, dispose } = setup()
    fireEvent.change(screen.getByDisplayValue('Server title'), { target: { value: 'My title' } })
    await act(async () => {
      client.setQueryData(getGetAlbumShowQueryKey('AlbumFixture1'), album('Remote title'))
      await vi.advanceTimersByTimeAsync(1000)
    })
    expect(screen.getByDisplayValue('My title')).toBeInTheDocument()
    expect(screen.getByRole('alert')).toHaveTextContent('changed elsewhere')
    expect(request).not.toHaveBeenCalled()
    fireEvent.click(screen.getByRole('button', { name: 'Use server version' }))
    expect(screen.getByDisplayValue('Remote title')).toBeInTheDocument()
    dispose()
  })

  it('preserves dirty text when a coordinator refresh delivers remote metadata', async () => {
    const { coordinator, dispose } = setup(true)
    await act(async () => { await vi.advanceTimersByTimeAsync(1000) })
    request.mockResolvedValue(album('Remote title'))
    fireEvent.change(screen.getByDisplayValue('Server title'), { target: { value: 'My title' } })
    await act(async () => {
      coordinator.hint(['catalog'])
      await vi.advanceTimersByTimeAsync(1000)
    })
    expect(screen.getByDisplayValue('My title')).toBeInTheDocument()
    expect(screen.getByRole('alert')).toHaveTextContent('changed elsewhere')
    expect(request.mock.calls.every(([, options]) => options.method === 'GET')).toBe(true)
    dispose()
  })

  it('holds refreshes during a save, then retains newer text when the remote version arrives', async () => {
    const { coordinator, dispose } = setup(true)
    await act(async () => { await vi.advanceTimersByTimeAsync(1000) })
    let finishSave!: (value: object) => void
    const pending = new Promise<object>(resolve => { finishSave = resolve })
    request.mockReset().mockImplementation((_url, options) => options.method === 'PATCH'
      ? pending
      : Promise.resolve(album('Remote title')))
    fireEvent.change(screen.getByDisplayValue('Server title'), { target: { value: 'Submitted title' } })
    await act(async () => { await vi.advanceTimersByTimeAsync(800) })
    expect(request.mock.calls.map(([, options]) => options.method)).toEqual(['PATCH'])
    fireEvent.change(screen.getByDisplayValue('Submitted title'), { target: { value: 'Newer title' } })
    await act(async () => {
      coordinator.hint(['catalog'])
      await vi.advanceTimersByTimeAsync(500)
    })
    expect(request.mock.calls.map(([, options]) => options.method)).toEqual(['PATCH'])
    await act(async () => {
      finishSave({})
      await pending
      await vi.advanceTimersByTimeAsync(200)
    })
    expect(screen.getByDisplayValue('Newer title')).toBeInTheDocument()
    expect(screen.getByRole('alert')).toHaveTextContent('changed elsewhere')
    await act(async () => { await vi.advanceTimersByTimeAsync(1000) })
    expect(request.mock.calls.filter(([, options]) => options.method === 'PATCH')).toHaveLength(1)
    dispose()
  })

  it('keeps a single edited field visible after access reset and purges the server fields', async () => {
    const { dispose } = setup()
    request.mockImplementation(() => new Promise(() => {}))
    fireEvent.change(screen.getByDisplayValue('Server title'), { target: { value: 'My title' } })
    await act(async () => { advanceAuthorization(); await vi.advanceTimersByTimeAsync(1000) })
    expect(screen.getByDisplayValue('My title')).toBeDisabled()
    expect(screen.queryByDisplayValue('Server label')).not.toBeInTheDocument()
    expect(screen.getByRole('alert')).toHaveTextContent('unavailable')
    expect(request.mock.calls.every(([, options]) => options.method === 'GET')).toBe(true)
    dispose()
  })

  it.each([401, 403])('handles a %s read without re-render loops or losing local text', async status => {
    const { client, dispose } = setup()
    fireEvent.change(screen.getByDisplayValue('Server title'), { target: { value: 'My title' } })
    request.mockRejectedValue(new AxiosError('Access denied', undefined, undefined, undefined, {
      status, statusText: 'Access denied', headers: {}, config: { headers: {} } as never, data: {},
    }))
    await act(async () => {
      await client.invalidateQueries({ queryKey: getGetAlbumShowQueryKey('AlbumFixture1') })
      await vi.advanceTimersByTimeAsync(1000)
    })
    expect(screen.getByDisplayValue('My title')).toBeDisabled()
    expect(screen.queryByDisplayValue('Server label')).not.toBeInTheDocument()
    expect(screen.getByRole('alert')).toHaveTextContent('unavailable')
    expect(request).toHaveBeenCalledTimes(1)
    dispose()
  })

  it.each([403, 404])('settles a coordinated %s read while retaining dirty text and restoring future access', async status => {
    const { client, coordinator, dispose } = setup(true)
    await act(async () => { await vi.advanceTimersByTimeAsync(1000) })
    fireEvent.change(screen.getByDisplayValue('Server title'), { target: { value: 'My title' } })
    const failure = new AxiosError('Unavailable', undefined, undefined, undefined, {
      status, statusText: 'Unavailable', headers: {}, config: { headers: {} } as never, data: {},
    })
    request.mockReset().mockRejectedValue(failure)
    await act(async () => {
      coordinator.hint(['catalog'])
      await vi.advanceTimersByTimeAsync(40_000)
    })
    expect(screen.getByDisplayValue('My title')).toBeDisabled()
    expect(screen.getByRole('alert')).toHaveTextContent('unavailable')
    expect(request).toHaveBeenCalledTimes(1)
    expect(client.getQueryState(getGetAlbumShowQueryKey('AlbumFixture1'))).toMatchObject({ data: undefined, status: 'error', error: failure })
    request.mockResolvedValue(album('Restored title'))
    await act(async () => {
      coordinator.hint(['catalog'])
      await vi.advanceTimersByTimeAsync(500)
    })
    expect(request).toHaveBeenCalledTimes(2)
    expect(screen.getByDisplayValue('My title')).toBeEnabled()
    expect(screen.getByRole('alert')).toHaveTextContent('changed elsewhere')
    dispose()
  })
})
