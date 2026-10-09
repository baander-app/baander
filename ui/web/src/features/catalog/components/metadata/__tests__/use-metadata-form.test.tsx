import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { act, renderHook } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import type { ReactNode } from 'react'
import { useMetadataForm } from '../use-metadata-form'

const albumSave = vi.fn().mockResolvedValue({})
const albumLock = vi.fn()

vi.mock('@/shared/api-client/gen/endpoints', () => ({
  usePatchSongUpdate: () => ({ mutateAsync: vi.fn(), mutate: vi.fn() }),
  usePatchAlbumUpdate: () => ({ mutateAsync: albumSave, mutate: albumLock }),
  usePatchArtistUpdate: () => ({ mutateAsync: vi.fn(), mutate: vi.fn() }),
  getGetSongShowQueryKey: (id: string) => ['songs', id],
  getGetAlbumShowQueryKey: (id: string) => ['albums', id],
  getGetArtistShowQueryKey: (id: string) => ['artists', id],
}))

vi.mock('sonner', () => ({ toast: { success: vi.fn(), error: vi.fn() } }))

function renderForm() {
  const client = new QueryClient()
  const wrapper = ({ children }: { children: ReactNode }) => (
    <QueryClientProvider client={client}>{children}</QueryClientProvider>
  )

  return renderHook(
    () => useMetadataForm({ entityType: 'album', publicId: 'AlbumFixture1', initialData: { title: 'Abbey Road' }, lockedFields: [] }),
    { wrapper },
  )
}

describe('useMetadataForm auto-save', () => {
  beforeEach(() => {
    vi.useFakeTimers()
    albumSave.mockClear()
    albumLock.mockClear()
  })

  afterEach(() => {
    vi.useRealTimers()
  })

  it('saves the latest value of a single edit', async () => {
    const { result } = renderForm()

    act(() => result.current.updateField('title', 'Let It Be'))
    await act(async () => {
      await vi.advanceTimersByTimeAsync(800)
    })

    expect(albumSave).toHaveBeenCalledWith({ publicId: 'AlbumFixture1', data: { title: 'Let It Be', lockedFields: [] } })
  })

  it('saves every keystroke typed before the delay ends', async () => {
    const { result } = renderForm()

    act(() => result.current.updateField('title', 'H'))
    act(() => result.current.updateField('title', 'He'))
    act(() => result.current.updateField('title', 'Help'))
    await act(async () => {
      await vi.advanceTimersByTimeAsync(800)
    })

    expect(albumSave).toHaveBeenCalledTimes(1)
    expect(albumSave).toHaveBeenCalledWith({ publicId: 'AlbumFixture1', data: { title: 'Help', lockedFields: [] } })
  })

  it('keeps a lock set while an edit waits to be saved', async () => {
    const { result } = renderForm()

    act(() => result.current.updateField('title', 'Let It Be'))
    act(() => result.current.toggleLock('title'))
    await act(async () => {
      await vi.advanceTimersByTimeAsync(800)
    })

    expect(albumLock).toHaveBeenCalledWith({ publicId: 'AlbumFixture1', data: { lockedFields: ['title'] } })
    expect(albumSave).toHaveBeenCalledWith({ publicId: 'AlbumFixture1', data: { title: 'Let It Be', lockedFields: ['title'] } })
  })
})
