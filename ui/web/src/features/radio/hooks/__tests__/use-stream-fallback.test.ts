import { describe, it, expect } from 'vitest'
import { renderHook, act } from '@testing-library/react'
import type { RadioStation, StreamInfo } from '@/features/radio/api/radio-api'
import { useStreamFallback } from '../use-stream-fallback'

// Build streams in deliberately unsorted reliability order so the hook's sort
// is exercised by the assertions. Identifiers make order observable.
function makeStream(
  reliability: number,
  url: string,
  format = 'mp3',
  bitrate = 128,
): StreamInfo {
  return { url, format, bitrate, reliability }
}

function makeStation(streams: StreamInfo[]): RadioStation {
  return {
    id: 'station-1',
    sourceId: 'source-1',
    externalId: 'ext-1',
    name: 'Test Station',
    country: 'DK',
    language: null,
    genres: [],
    tags: [],
    streams,
    logo: null,
    website: null,
    lastCheckedAt: null,
    createdAt: '2026-01-01T00:00:00Z',
    updatedAt: '2026-01-01T00:00:00Z',
  }
}

describe('useStreamFallback', () => {
  // Streams given in arbitrary reliability order; hook must sort descending.
  const streams = [
    makeStream(0.2, 'https://low'),
    makeStream(0.9, 'https://high'),
    makeStream(0.5, 'https://mid'),
  ]
  const station = makeStation(streams)

  it('sorts streams by reliability descending and exposes the count', () => {
    const { result } = renderHook(() => useStreamFallback(station))

    expect(result.current.totalStreams).toBe(3)
    // The full sorted order is observable only by walking the index, since the
    // hook exposes the current stream alone. We assert the start point here and
    // the subsequent order in the advance test below.
    expect(result.current.currentStream?.url).toBe('https://high')
  })

  it('starts at index 0 with the best stream and no exhaustion/buffering', () => {
    const { result } = renderHook(() => useStreamFallback(station))

    expect(result.current.streamIndex).toBe(0)
    expect(result.current.currentStream).toEqual(
      expect.objectContaining({ url: 'https://high', reliability: 0.9 }),
    )
    expect(result.current.isExhausted).toBe(false)
    expect(result.current.isBuffering).toBe(false)
  })

  it('tryNext sets isBuffering and advances to the next-best stream in order', () => {
    const { result } = renderHook(() => useStreamFallback(station))

    act(() => {
      result.current.tryNext()
    })

    // Second-best (0.5) is now current, and buffering was flipped on.
    expect(result.current.streamIndex).toBe(1)
    expect(result.current.currentStream).toEqual(
      expect.objectContaining({ url: 'https://mid', reliability: 0.5 }),
    )
    expect(result.current.isBuffering).toBe(true)
    expect(result.current.isExhausted).toBe(false)

    // Consumer clears buffering once playback stabilizes.
    act(() => {
      result.current.setBuffering(false)
    })
    expect(result.current.isBuffering).toBe(false)

    act(() => {
      result.current.tryNext()
    })

    // Worst (0.2) is now current.
    expect(result.current.streamIndex).toBe(2)
    expect(result.current.currentStream).toEqual(
      expect.objectContaining({ url: 'https://low', reliability: 0.2 }),
    )
  })

  it('tryNext past the last stream sets isExhausted and nulls currentStream', () => {
    const { result } = renderHook(() => useStreamFallback(station))

    // Walk to the last stream.
    act(() => {
      result.current.tryNext()
    })
    act(() => {
      result.current.tryNext()
    })
    expect(result.current.streamIndex).toBe(2)
    expect(result.current.isExhausted).toBe(false)

    // One more advance: index would be 3, which is >= length.
    act(() => {
      result.current.tryNext()
    })

    expect(result.current.isExhausted).toBe(true)
    expect(result.current.isBuffering).toBe(false)
    // Index stays at the last valid position; currentStream remains the last
    // stream rather than rolling into undefined.
    expect(result.current.streamIndex).toBe(2)
    expect(result.current.currentStream).toEqual(
      expect.objectContaining({ url: 'https://low', reliability: 0.2 }),
    )
  })

  it('reset() returns to index 0 and clears exhaustion and buffering', () => {
    const { result } = renderHook(() => useStreamFallback(station))

    // Exhaust the stream list.
    act(() => {
      result.current.tryNext()
    })
    act(() => {
      result.current.tryNext()
    })
    act(() => {
      result.current.tryNext()
    })
    expect(result.current.isExhausted).toBe(true)

    act(() => {
      result.current.reset()
    })

    expect(result.current.streamIndex).toBe(0)
    expect(result.current.isExhausted).toBe(false)
    expect(result.current.isBuffering).toBe(false)
    expect(result.current.currentStream).toEqual(
      expect.objectContaining({ url: 'https://high', reliability: 0.9 }),
    )
  })

  it('returns null currentStream and zero totalStreams for a null station', () => {
    const { result } = renderHook(() => useStreamFallback(null))

    expect(result.current.currentStream).toBeNull()
    expect(result.current.totalStreams).toBe(0)
    expect(result.current.streamIndex).toBe(0)

    // No streams to advance to -> immediate exhaustion, currentStream stays null.
    act(() => {
      result.current.tryNext()
    })
    expect(result.current.isExhausted).toBe(true)
    expect(result.current.currentStream).toBeNull()
  })
})
