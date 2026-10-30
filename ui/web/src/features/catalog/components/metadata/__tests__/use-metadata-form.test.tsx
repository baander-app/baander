import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { act, cleanup, renderHook } from '@testing-library/react'
import { QueryClientProvider } from '@tanstack/react-query'
import type { ReactNode } from 'react'
import { useMetadataForm } from '../use-metadata-form'
import { OwnedQueryClient } from '@/shared/live/query-client'
import { advanceAuthorization, synchronizeOwnership } from '@/shared/live/ownership'

const request = vi.hoisted(() => vi.fn().mockResolvedValue({}))
vi.mock('@/shared/api-client/axios-instance', () => ({ customInstance: request }))
vi.mock('sonner', () => ({ toast: { success: vi.fn(), error: vi.fn() } }))

function deferred() {
  let resolve!: (value: object) => void
  const promise = new Promise<object>(done => { resolve = done })
  return { resolve, promise }
}

function renderForm() {
  const client = new OwnedQueryClient()
  const detach = client.attach()
  const wrapper = ({ children }: { children: ReactNode }) => (
    <QueryClientProvider client={client}>{children}</QueryClientProvider>
  )
  const initial = { publicId: 'AlbumFixture1', initialData: { title: 'Abbey Road', year: 1969 }, lockedFields: [] as string[], unavailable: false, unauthorized: false }
  const hook = renderHook(
    props => useMetadataForm({ entityType: 'album', ...props }),
    { wrapper, initialProps: initial },
  )
  return { ...hook, initial, dispose: () => { detach(); client.dispose() } }
}

function savedPayload(index = 0) {
  expect(request.mock.calls[index][0]).toBe('/api/albums/AlbumFixture1')
  return JSON.parse(request.mock.calls[index][1].body)
}

describe('metadata drafts with generated mutations', () => {
  beforeEach(() => {
    vi.useFakeTimers()
    request.mockReset().mockResolvedValue({})
    synchronizeOwnership('metadata-test')
  })

  afterEach(() => {
    cleanup()
    synchronizeOwnership(null)
    vi.useRealTimers()
  })

  it('saves the latest text once after the debounce', async () => {
    const { result, dispose } = renderForm()
    act(() => result.current.updateField('title', 'H'))
    act(() => result.current.updateField('title', 'Help'))
    await act(async () => { await vi.advanceTimersByTimeAsync(800) })
    expect(request).toHaveBeenCalledTimes(1)
    expect(savedPayload()).toEqual({ title: 'Help', lockedFields: [] })
    expect(result.current.dirty.size).toBe(0)
    dispose()
  })

  it('saves lock changes and pending text together without competing mutations', async () => {
    const { result, dispose } = renderForm()
    act(() => result.current.updateField('title', 'Let It Be'))
    await act(async () => { result.current.toggleLock('title') })
    await act(async () => { await vi.advanceTimersByTimeAsync(800) })
    expect(request).toHaveBeenCalledTimes(1)
    expect(savedPayload()).toEqual({ title: 'Let It Be', lockedFields: ['title'] })
    dispose()
  })

  it('keeps dirty text and pauses autosave until remote conflict is explicitly resolved', async () => {
    const { result, rerender, initial, dispose } = renderForm()
    act(() => result.current.updateField('title', 'My title'))
    rerender({ ...initial, initialData: { title: 'Remote title', year: 1970 } })
    await act(async () => { await vi.advanceTimersByTimeAsync(1000) })
    expect(result.current.formState.title).toBe('My title')
    expect(result.current.status).toBe('conflict')
    expect(request).not.toHaveBeenCalled()
    act(() => result.current.resolveConflict('local'))
    await act(async () => { await vi.advanceTimersByTimeAsync(800) })
    expect(savedPayload()).toEqual({ title: 'My title', lockedFields: [] })
    expect(result.current.formState.year).toBe(1970)
    dispose()
  })

  it('retains newer text during a save and submits it after the first save completes', async () => {
    const first = deferred()
    request.mockReturnValueOnce(first.promise)
    const { result, dispose } = renderForm()
    act(() => result.current.updateField('title', 'Submitted title'))
    await act(async () => { await vi.advanceTimersByTimeAsync(800) })
    expect(result.current.isSaving).toBe(true)
    act(() => result.current.updateField('title', 'Newer title'))
    await act(async () => { first.resolve({}); await first.promise })
    expect(result.current.formState.title).toBe('Newer title')
    expect(result.current.dirty.has('title')).toBe(true)
    await act(async () => { await vi.advanceTimersByTimeAsync(800) })
    expect(savedPayload(1).title).toBe('Newer title')
    dispose()
  })

  it('preserves text when a record disappears and disables all further saves', async () => {
    const { result, rerender, initial, dispose } = renderForm()
    act(() => result.current.updateField('title', 'My title'))
    rerender({ ...initial, unavailable: true })
    await act(async () => { await vi.advanceTimersByTimeAsync(1000); await result.current.save() })
    expect(result.current.formState.title).toBe('My title')
    expect(result.current.status).toBe('unavailable')
    expect(request).not.toHaveBeenCalled()
    dispose()
  })

  it('purges server values on permission loss and ignores a late save completion', async () => {
    const first = deferred()
    request.mockReturnValueOnce(first.promise)
    const { result, dispose } = renderForm()
    act(() => result.current.updateField('title', 'My title'))
    await act(async () => { await vi.advanceTimersByTimeAsync(800) })
    act(() => advanceAuthorization())
    expect(result.current.formState).toEqual({ title: 'My title' })
    await act(async () => { first.resolve({}); await first.promise })
    expect(result.current.status).toBe('unavailable')
    expect(result.current.dirty.has('title')).toBe(true)
    await act(async () => { await vi.advanceTimersByTimeAsync(1000) })
    expect(request).toHaveBeenCalledTimes(1)
    dispose()
  })

  it('does not let a previous entity save postpone the new entity autosave', async () => {
    const first = deferred()
    request.mockReturnValueOnce(first.promise)
    const { result, rerender, initial, dispose } = renderForm()
    act(() => result.current.updateField('title', 'First edited title'))
    await act(async () => { await vi.advanceTimersByTimeAsync(800) })
    rerender({ ...initial, publicId: 'AlbumFixture2', initialData: { title: 'Second title', year: 1970 } })
    act(() => result.current.updateField('title', 'Second edited title'))
    await act(async () => { await vi.advanceTimersByTimeAsync(400) })
    await act(async () => { first.resolve({}); await first.promise })
    await act(async () => { await vi.advanceTimersByTimeAsync(400) })
    expect(request).toHaveBeenCalledTimes(2)
    expect(request.mock.calls[1][0]).toBe('/api/albums/AlbumFixture2')
    expect(JSON.parse(request.mock.calls[1][1].body).title).toBe('Second edited title')
    dispose()
  })

  it('cancels a pending autosave when the form unmounts', async () => {
    const { result, unmount, dispose } = renderForm()
    act(() => result.current.updateField('title', 'Unsubmitted title'))
    unmount()
    await act(async () => { await vi.advanceTimersByTimeAsync(1000) })
    expect(request).not.toHaveBeenCalled()
    dispose()
  })
})
