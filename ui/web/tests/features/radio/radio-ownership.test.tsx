import { act, renderHook } from '@testing-library/react'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { useRadioStore } from '@/features/radio/stores/radio-store'
import { useRadioPlayback } from '@/features/radio/hooks/use-radio-playback'
import { startRadioSession, stopRadioSession, type RadioStation } from '@/features/radio/api/radio-api'

vi.mock('@/features/radio/api/radio-api', () => ({ startRadioSession: vi.fn(), stopRadioSession: vi.fn() }))
vi.mock('@/shared/lib/mediator/bus', () => ({ mediator: { dispatch: vi.fn() } }))
function deferred<T>() {
  let resolve!: (value: T) => void, reject!: (reason: Error) => void
  const promise = new Promise<T>((yes, no) => { resolve = yes; reject = no })
  return { promise, resolve, reject }
}
function station(id: string): RadioStation {
  return { id, sourceId: 'source', externalId: id, name: id, country: 'DK', language: null, genres: [], tags: [], logo: null,
    website: null, lastCheckedAt: null, createdAt: '', updatedAt: '', streams: [
      { url: `https://radio.baander.app/${id}/slow`, format: 'mp3', bitrate: 128, reliability: 1 },
      { url: `https://radio.baander.app/${id}/best`, format: 'mp3', bitrate: 128, reliability: 3 },
      { url: `https://radio.baander.app/${id}/next`, format: 'mp3', bitrate: 128, reliability: 2 },
    ] }
}
beforeEach(() => {
  useRadioStore.getState().stopRadio()
  useRadioStore.getState().setAudioElement(null)
  vi.clearAllMocks()
  vi.mocked(startRadioSession).mockResolvedValue(undefined as never)
  vi.mocked(stopRadioSession).mockResolvedValue(undefined as never)
})

describe('radio request ownership', () => {
  it('ignores an old native play rejection after selecting a new station', async () => {
    const pending = deferred<void>()
    const audio = { src: '', play: vi.fn().mockReturnValueOnce(pending.promise).mockResolvedValue(undefined), pause: vi.fn() } as unknown as HTMLAudioElement
    useRadioStore.getState().setAudioElement(audio)
    const first = station('A'), next = station('B')
    useRadioStore.getState().startStation(first, first.streams[1].url)
    useRadioStore.getState().startStation(next, next.streams[1].url)
    pending.reject(new Error('old play failure'))
    await pending.promise.catch(() => {})
    expect(useRadioStore.getState().activeStation).toBe(next)
    expect(useRadioStore.getState().isPlaying).toBe(true)
    expect(audio.src).toBe(next.streams[1].url)
  })

  it('falls back in the same reliability order used for initial stream selection', () => {
    const selected = station('A')
    useRadioStore.getState().startStation(selected, selected.streams[1].url)
    expect(useRadioStore.getState().tryNextStream()).toBe(selected.streams[2].url)
    expect(useRadioStore.getState().tryNextStream()).toBe(selected.streams[0].url)
    expect(useRadioStore.getState().tryNextStream()).toBeNull()
    expect(useRadioStore.getState().allStreamsFailed).toBe(true)
    expect(useRadioStore.getState().isPlaying).toBe(false)
  })

  it('does not restore a station when its delayed session request completes after stop', async () => {
    const pending = deferred<never>()
    vi.mocked(startRadioSession).mockReturnValueOnce(pending.promise)
    const { result } = renderHook(() => useRadioPlayback())
    let start!: Promise<void>
    act(() => { start = result.current.start(station('A')) })
    expect(useRadioStore.getState().isPlaying).toBe(true)
    await act(async () => { await result.current.stop() })
    await act(async () => { pending.resolve(undefined as never); await start })
    expect(useRadioStore.getState().activeStation).toBeNull()
    expect(useRadioStore.getState().isPlaying).toBe(false)
  })

  it('does not stop a newer selection when an old stop request completes in another hook', async () => {
    const pending = deferred<never>()
    vi.mocked(stopRadioSession).mockReturnValueOnce(pending.promise)
    const first = renderHook(() => useRadioPlayback()), second = renderHook(() => useRadioPlayback())
    let stop!: Promise<void>
    act(() => { stop = first.result.current.stop() })
    const next = station('B')
    await act(async () => { await second.result.current.start(next) })
    await act(async () => { pending.resolve(undefined as never); await stop })
    expect(useRadioStore.getState().activeStation).toBe(next)
    expect(useRadioStore.getState().isPlaying).toBe(true)
  })
  it('keeps a manual pause authoritative when a current stream error requests fallback', () => {
    const selected = station('A')
    const audio = { src: '', play: vi.fn().mockResolvedValue(undefined), pause: vi.fn() } as unknown as HTMLAudioElement
    useRadioStore.getState().setAudioElement(audio)
    useRadioStore.getState().startStation(selected, selected.streams[1].url)
    useRadioStore.getState().setIsPlaying(false)
    expect(useRadioStore.getState().tryNextStream()).toBeNull()
    expect(useRadioStore.getState().isPlaying).toBe(false)
    expect(audio.play).toHaveBeenCalledOnce()
    expect(useRadioStore.getState().allStreamsFailed).toBe(false)
  })

  it('still falls back after a native error pause without a manual pause', () => {
    const selected = station('A')
    useRadioStore.getState().startStation(selected, selected.streams[1].url)
    useRadioStore.getState().observePlaying(false)
    expect(useRadioStore.getState().tryNextStream()).toBe(selected.streams[2].url)
    expect(useRadioStore.getState().isPlaying).toBe(true)
  })

})
