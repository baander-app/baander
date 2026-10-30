import { AxiosError } from 'axios'
import { act, cleanup, renderHook } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { AXIOS_INSTANCE } from '@/shared/api-client/axios-instance'
import { usePreferenceSync } from '../hooks/use-preference-sync'

vi.mock('@/shared/api-client/axios-instance', () => ({ AXIOS_INSTANCE: { get: vi.fn(), put: vi.fn(), post: vi.fn() } }))
const api = vi.mocked(AXIOS_INSTANCE)
const conflict = (version: number) => ({ response: { status: 409, data: { error: { code: 409, details: { currentVersion: version } } } } })
const response = (version: number) => ({ data: { data: { version, payload: { volume: 70 } } } })

function setup(isActive = () => true) {
  const apply = vi.fn()
  const hook = renderHook(() => usePreferenceSync({
    baseUrl: '/api/user/player-preferences/',
    toPayload: (state: { volume: number }) => ({ volume: state.volume }),
    fromPayload: (payload) => ({ volume: typeof payload.volume === 'number' ? payload.volume : 0 }),
    onRemoteUpdate: apply, isActive,
  }))
  return { ...hook, apply }
}

function pendingResponse() {
  let resolve!: (value: ReturnType<typeof response>) => void
  const promise = new Promise<ReturnType<typeof response>>(done => { resolve = done })
  return { resolve, promise }
}

beforeEach(() => { vi.resetAllMocks(); vi.useFakeTimers() })
afterEach(() => { cleanup(); vi.useRealTimers() })

describe('preference conflict resolution', () => {
  it('uses the reported server version for mine and keeps a newer conflict on another 409', async () => {
    api.put.mockRejectedValueOnce(conflict(5)).mockRejectedValueOnce(conflict(6)).mockResolvedValueOnce(response(7))
    const { result } = setup()
    act(() => result.current.pushToServer({ volume: 70 }))
    await act(async () => { await vi.advanceTimersByTimeAsync(500) })
    expect(result.current.conflict.serverVersion).toBe(5)
    await act(async () => { expect(await result.current.resolveConflict('mine', { volume: 80 })).toBe(false) })
    expect(api.put.mock.calls[1][1]).toEqual({ payload: { volume: 80 }, version: 5 })
    expect(result.current.conflict).toEqual({ type: 'conflict', serverVersion: 6 })
    await act(async () => { expect(await result.current.resolveConflict('mine', { volume: 90 })).toBe(true) })
    expect(api.put.mock.calls[2][1]).toEqual({ payload: { volume: 90 }, version: 6 })
    expect(result.current.conflict.type).toBe('none')
  })

  it.each(['mine', 'theirs'] as const)('keeps conflict visible when %s fails offline', async (choice) => {
    api.put.mockRejectedValueOnce(conflict(5)).mockRejectedValueOnce(new Error('Offline'))
    api.get.mockRejectedValueOnce(new Error('Offline'))
    const { result } = setup()
    act(() => result.current.pushToServer({ volume: 70 }))
    await act(async () => { await vi.advanceTimersByTimeAsync(500) })
    await act(async () => { expect(await result.current.resolveConflict(choice, { volume: 80 })).toBe(false) })
    expect(result.current.conflict).toEqual({ type: 'conflict', serverVersion: 5 })
  })

  it('serializes writes and uses the acknowledged version for the latest queued edit', async () => {
    const first = pendingResponse()
    api.put.mockReturnValueOnce(first.promise).mockResolvedValueOnce(response(6))
    const { result } = setup()
    act(() => result.current.pushToServer({ volume: 70 }))
    await act(async () => { await vi.advanceTimersByTimeAsync(500) })
    act(() => result.current.pushToServer({ volume: 80 }))
    await act(async () => { await vi.advanceTimersByTimeAsync(500) })
    expect(api.put).toHaveBeenCalledOnce()
    expect(result.current.syncing).toBe(true)
    await act(async () => { first.resolve(response(5)) })
    await act(async () => { await vi.advanceTimersByTimeAsync(100) })
    expect(api.put).toHaveBeenCalledTimes(2)
    expect(api.put.mock.calls[1][1]).toEqual({ payload: { volume: 80 }, version: 5 })
    expect(result.current.versionRef.current).toBe(6)
  })

  it('coalesces repeated conflict choices while the request is pending', async () => {
    const pending = pendingResponse()
    api.put.mockRejectedValueOnce(conflict(5)).mockReturnValueOnce(pending.promise)
    const { result } = setup()
    act(() => result.current.pushToServer({ volume: 70 }))
    await act(async () => { await vi.advanceTimersByTimeAsync(500) })
    await act(async () => {
      const first = result.current.resolveConflict('mine', { volume: 80 })
      const second = result.current.resolveConflict('mine', { volume: 80 })
      expect(api.put).toHaveBeenCalledTimes(2)
      pending.resolve(response(6))
      expect(await first).toBe(true)
      expect(await second).toBe(true)
    })
  })

  it('cancels a queued local save when choosing the server version', async () => {
    api.put.mockRejectedValueOnce(conflict(5))
    api.get.mockResolvedValueOnce(response(5))
    const { result, apply } = setup()
    act(() => result.current.pushToServer({ volume: 70 }))
    await act(async () => { await vi.advanceTimersByTimeAsync(500) })
    act(() => result.current.pushToServer({ volume: 80 }))
    await act(async () => { expect(await result.current.resolveConflict('theirs')).toBe(true); await vi.advanceTimersByTimeAsync(500) })
    expect(api.put).toHaveBeenCalledOnce()
    expect(apply).toHaveBeenCalledWith({ volume: 70 }, 5)
    expect(result.current.conflict.type).toBe('none')
  })
})

describe('preference pending edit protection', () => {
  it('defers remote application while a save is pending, including reads started afterward', async () => {
    const pending = pendingResponse()
    api.put.mockReturnValueOnce(pending.promise)
    api.get.mockResolvedValue(response(5))
    const { result, apply } = setup()
    act(() => result.current.pushToServer({ volume: 80 }))
    await act(async () => { await vi.advanceTimersByTimeAsync(500) })
    await act(async () => { expect(await result.current.fetchFromServer()).toBe(false) })
    expect(apply).not.toHaveBeenCalled()
    await act(async () => { pending.resolve(response(6)) })
  })

  it('does not acknowledge newer local edits when the previous save finishes', async () => {
    const pending = pendingResponse()
    api.put.mockReturnValueOnce(pending.promise)
    api.get.mockResolvedValue(response(5))
    const { result, apply } = setup()
    act(() => result.current.pushToServer({ volume: 80 }))
    await act(async () => { await vi.advanceTimersByTimeAsync(500) })
    act(() => result.current.pushToServer({ volume: 90 }))
    await act(async () => { pending.resolve(response(5)) })
    await act(async () => { expect(await result.current.fetchFromServer()).toBe(false) })
    expect(apply).not.toHaveBeenCalled()
  })

  it('pauses autosave when a refreshed baseline conflicts and uses the current version for mine', async () => {
    api.get.mockResolvedValue(response(5))
    api.put.mockResolvedValue(response(6))
    const { result, apply } = setup()
    act(() => result.current.pushToServer({ volume: 80 }))
    await act(async () => { await result.current.fetchFromServer() })
    expect(result.current.conflict.serverVersion).toBe(5)
    act(() => result.current.pushToServer({ volume: 90 }))
    await act(async () => { await vi.advanceTimersByTimeAsync(1500) })
    expect(api.put).not.toHaveBeenCalled()
    expect(apply).not.toHaveBeenCalled()
    await act(async () => { expect(await result.current.resolveConflict('mine', { volume: 90 })).toBe(true) })
    expect(api.put.mock.calls[0][1]).toEqual({ payload: { volume: 90 }, version: 5 })
  })

  it('preserves edits made while an explicit server conflict choice is loading', async () => {
    const pending = pendingResponse()
    api.get.mockReturnValueOnce(pending.promise)
    const { result, apply } = setup()
    const request = result.current.resolveConflict('theirs')
    act(() => result.current.pushToServer({ volume: 90 }))
    await act(async () => { pending.resolve(response(5)); expect(await request).toBe(false) })
    expect(apply).not.toHaveBeenCalled()
  })

  it('does not apply rollback over a newer local edit', async () => {
    const pending = pendingResponse()
    api.post.mockReturnValueOnce(pending.promise)
    const { result, apply } = setup()
    const request = result.current.rollback(1)
    act(() => result.current.pushToServer({ volume: 90 }))
    await act(async () => { pending.resolve(response(5)); expect(await request).toBe(false) })
    expect(apply).not.toHaveBeenCalled()
  })
})

describe('preference session ownership', () => {
  it('does not echo a remote update back through synchronous store subscriptions', async () => {
    api.get.mockResolvedValueOnce(response(5))
    const { result, apply } = setup()
    apply.mockImplementation((state: { volume: number }) => result.current.pushToServer(state))
    await act(async () => { await result.current.fetchFromServer(); await vi.advanceTimersByTimeAsync(500) })
    expect(apply).toHaveBeenCalledOnce()
    expect(api.put).not.toHaveBeenCalled()
  })

  it('does not overwrite local edits with a delayed bootstrap response', async () => {
    const pending = pendingResponse()
    api.get.mockReturnValueOnce(pending.promise)
    const { result, apply } = setup()
    const request = result.current.fetchFromServer()
    act(() => result.current.pushToServer({ volume: 90 }))
    pending.resolve(response(5))
    expect(await request).toBe(false)
    expect(apply).not.toHaveBeenCalled()
  })

  it('cancels queued writes on unmount', async () => {
    const { result, unmount } = setup()
    act(() => result.current.pushToServer({ volume: 70 }))
    unmount()
    await act(async () => { await vi.advanceTimersByTimeAsync(500) })
    expect(api.put).not.toHaveBeenCalled()
  })

  it('does not dispatch a queued write after identity changes before cleanup', async () => {
    let active = true
    const { result } = setup(() => active)
    act(() => result.current.pushToServer({ volume: 70 }))
    active = false
    await act(async () => { await vi.advanceTimersByTimeAsync(500) })
    expect(api.put).not.toHaveBeenCalled()
  })

  it('aborts unmounted reads and ignores a late response even if transport ignores cancellation', async () => {
    const pending = pendingResponse()
    api.get.mockReturnValueOnce(pending.promise)
    const { result, unmount, apply } = setup()
    const request = result.current.fetchFromServer()
    const signal = api.get.mock.calls[0][1]?.signal
    unmount()
    expect(signal?.aborted).toBe(true)
    pending.resolve(response(5))
    expect(await request).toBe(false)
    expect(apply).not.toHaveBeenCalled()
  })

  it('ignores reads and writes completed after an identity switch', async () => {
    let active = true
    const read = pendingResponse()
    const write = pendingResponse()
    api.get.mockReturnValueOnce(read.promise)
    api.put.mockReturnValueOnce(write.promise)
    const { result, apply } = setup(() => active)
    const request = result.current.fetchFromServer()
    act(() => result.current.pushToServer({ volume: 70 }))
    await act(async () => { await vi.advanceTimersByTimeAsync(500) })
    active = false
    await act(async () => { read.resolve(response(5)); write.resolve(response(6)); expect(await request).toBe(false) })
    expect(apply).not.toHaveBeenCalled()
    expect(result.current.versionRef.current).toBe(0)
  })
})


describe('preference save retry lifecycle', () => {
  it.each([400, 403, 422])('does not retry an HTTP %s rejection', async status => {
    api.put.mockRejectedValue(Object.assign(new AxiosError('Rejected'), { response: { status } }))
    const { result } = setup()
    act(() => result.current.pushToServer({ volume: 70 }))
    await act(async () => { await vi.advanceTimersByTimeAsync(30000) })
    expect(api.put).toHaveBeenCalledOnce()
    expect(vi.getTimerCount()).toBe(0)
  })

  it('uses bounded jittered backoff without overlapping saves and cancels the timer on unmount', async () => {
    const random = vi.spyOn(Math, 'random').mockReturnValue(1)
    api.put.mockRejectedValue(new AxiosError('Offline', 'ERR_NETWORK'))
    const { result, unmount } = setup()
    act(() => result.current.pushToServer({ volume: 70 }))
    await act(async () => { await vi.advanceTimersByTimeAsync(500) })
    expect(api.put).toHaveBeenCalledTimes(1)
    await act(async () => { await vi.advanceTimersByTimeAsync(1199) })
    expect(api.put).toHaveBeenCalledTimes(1)
    await act(async () => { await vi.advanceTimersByTimeAsync(1) })
    expect(api.put).toHaveBeenCalledTimes(2)
    await act(async () => { await vi.advanceTimersByTimeAsync(2400 + 4800 + 9600 + 19200 + 30000) })
    expect(api.put).toHaveBeenCalledTimes(7)
    expect(vi.getTimerCount()).toBe(1)
    unmount()
    expect(vi.getTimerCount()).toBe(0)
    await act(async () => { await vi.advanceTimersByTimeAsync(30000) })
    expect(api.put).toHaveBeenCalledTimes(7)
    random.mockRestore()
  })

  it('cancels the failed-save retry when explicitly choosing the server snapshot', async () => {
    api.put.mockRejectedValue(new AxiosError('Offline', 'ERR_NETWORK'))
    api.get.mockResolvedValue(response(1))
    const { result, apply } = setup()
    act(() => result.current.pushToServer({ volume: 70 }))
    await act(async () => { await vi.advanceTimersByTimeAsync(500) })
    expect(vi.getTimerCount()).toBe(1)
    await act(async () => { expect(await result.current.resolveConflict('theirs')).toBe(true) })
    expect(apply).toHaveBeenCalledOnce()
    expect(vi.getTimerCount()).toBe(0)
    await act(async () => { await vi.advanceTimersByTimeAsync(30000) })
    expect(api.put).toHaveBeenCalledOnce()
  })

  it('leaves a failed explicit mine resolution paused rather than retrying with an obsolete version', async () => {
    api.put.mockRejectedValueOnce(conflict(5)).mockRejectedValue(new AxiosError('Offline', 'ERR_NETWORK'))
    const { result } = setup()
    act(() => result.current.pushToServer({ volume: 70 }))
    await act(async () => { await vi.advanceTimersByTimeAsync(500) })
    await act(async () => { expect(await result.current.resolveConflict('mine', { volume: 80 })).toBe(false) })
    expect(api.put.mock.calls[1][1]).toEqual({ payload: { volume: 80 }, version: 5 })
    expect(result.current.conflict.serverVersion).toBe(5)
    await act(async () => { await vi.advanceTimersByTimeAsync(30000) })
    expect(api.put).toHaveBeenCalledTimes(2)
  })

  it('does not retry a malformed successful response as a network failure', async () => {
    api.put.mockResolvedValue({ data: { data: {} } })
    const { result } = setup()
    act(() => result.current.pushToServer({ volume: 70 }))
    await act(async () => { await vi.advanceTimersByTimeAsync(30000) })
    expect(api.put).toHaveBeenCalledOnce()
  })
})
