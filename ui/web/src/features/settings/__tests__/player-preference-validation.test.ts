import { act, renderHook } from '@testing-library/react'
import { beforeAll, beforeEach, describe, expect, it, vi } from 'vitest'
import { AXIOS_INSTANCE } from '@/shared/api-client/axios-instance'
import { mediator } from '@/shared/lib/mediator/bus'
import { registerPlayerHandlers } from '@/features/player/stores/player-handlers'
import { usePlayerStore } from '@/features/player/stores/player-store'
import { usePlayerPreferences } from '../hooks/use-player-preferences'

vi.mock('@/shared/api-client/axios-instance', () => ({
  AXIOS_INSTANCE: { get: vi.fn(), put: vi.fn(), post: vi.fn() },
}))

const validPayload = {
  shuffle: true, repeat: 'one', volume: 0.37, muted: true,
  crossfadeEnabled: true, crossfadeDuration: 8,
}

const invalidCases = [
  ...(['shuffle', 'muted', 'crossfadeEnabled'] as const).flatMap((field) =>
    [undefined, null, 0, 1, 'true'].map((value) => ({ field, value, name: `${field}=${String(value)}` }))),
  ...[undefined, null, 0, true, 'none'].map((value) => ({ field: 'repeat', value, name: `repeat=${String(value)}` })),
  ...(['volume', 'crossfadeDuration'] as const).flatMap((field) =>
    [undefined, null, '0.5', true, NaN, Infinity, -Infinity, -0.01, field === 'volume' ? 1.01 : 12.01]
      .map((value) => ({ field, value, name: `${field}=${String(value)}` }))),
]

beforeAll(() => { registerPlayerHandlers() })

describe.each(['fetch', 'rollback'] as const)('player preference validation during %s', (operation) => {
  beforeEach(() => {
    vi.clearAllMocks()
    mediator.clearLog()
    usePlayerStore.setState(usePlayerStore.getInitialState(), true)
  })

  it.each(invalidCases)('rejects $name atomically', async ({ field, value }) => {
    const { result } = renderHook(() => usePlayerPreferences())
    vi.mocked(AXIOS_INSTANCE.get).mockResolvedValueOnce({
      data: { data: { payload: validPayload, version: 7 } },
    })
    await act(async () => { expect(await result.current.fetchFromServer()).toBe(true) })
    const previousState = usePlayerStore.getState()
    mediator.clearLog()

    const payload: Record<string, unknown> = { ...validPayload, [field]: value }
    if (value === undefined) delete payload[field]
    const response = { data: { data: { payload, version: 8 } } }
    if (operation === 'fetch') vi.mocked(AXIOS_INSTANCE.get).mockResolvedValueOnce(response)
    else vi.mocked(AXIOS_INSTANCE.post).mockResolvedValueOnce(response)

    await act(async () => {
      const restored = operation === 'fetch'
        ? await result.current.fetchFromServer()
        : await result.current.rollback(3)
      expect(restored).toBe(false)
    })
    expect(usePlayerStore.getState()).toBe(previousState)
    expect(result.current.versionRef.current).toBe(7)
    expect(mediator.getActionLog()).toEqual([])
  })

  it.each([
    { volume: 0, crossfadeDuration: 0, repeat: 'off', shuffle: false, muted: false, crossfadeEnabled: false },
    { volume: 1, crossfadeDuration: 12, repeat: 'all', shuffle: true, muted: true, crossfadeEnabled: true },
  ])('accepts volume=$volume and crossfadeDuration=$crossfadeDuration boundaries', async (payload) => {
    const response = { data: { data: {
      payload: { ...payload, replayGainEnabled: null, replayGainMode: 'unused', replayGainPreAmp: 'unused' },
      version: 9,
    } } }
    if (operation === 'fetch') vi.mocked(AXIOS_INSTANCE.get).mockResolvedValueOnce(response)
    else vi.mocked(AXIOS_INSTANCE.post).mockResolvedValueOnce(response)
    const { result } = renderHook(() => usePlayerPreferences())
    await act(async () => {
      expect(operation === 'fetch'
        ? await result.current.fetchFromServer()
        : await result.current.rollback(3)).toBe(true)
    })
    expect(usePlayerStore.getState()).toMatchObject({ ...payload, volume: payload.volume * 100 })
    expect(result.current.versionRef.current).toBe(9)
    expect(mediator.getActionLog().at(-1)?.errors).toEqual([])
  })
})
