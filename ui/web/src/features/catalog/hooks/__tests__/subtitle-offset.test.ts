import { afterEach, describe, expect, it, vi } from 'vitest'
import { observeSubtitleOffset } from '../subtitle-offset'

function fixture() {
  const video = document.createElement('video')
  const track = Object.assign(new EventTarget(), {
    kind: 'subtitles',
    cues: [{ startTime: 4, endTime: 8 }]
  })
  const tracks = Object.assign([track], new EventTarget())
  Object.defineProperty(video, 'textTracks', { value: tracks })
  return { video, track }
}

afterEach(() => vi.useRealTimers())

describe('subtitle source timing correction', () => {
  it('uses the original cue baseline for each positive and negative correction', () => {
    const { video, track } = fixture()
    const observer = observeSubtitleOffset(video)
    observer.setOffset(1000)
    expect(track.cues[0]).toEqual({ startTime: 5, endTime: 9 })
    observer.setOffset(-2000)
    expect(track.cues[0]).toEqual({ startTime: 2, endTime: 6 })
    observer.setOffset(0)
    expect(track.cues[0]).toEqual({ startTime: 4, endTime: 8 })
    observer.dispose()
  })

  it('retimes asynchronously arriving cues and stops observing after disposal', () => {
    vi.useFakeTimers()
    const { video, track } = fixture()
    const observer = observeSubtitleOffset(video, 500)
    track.cues.push({ startTime: 12, endTime: 14 })
    vi.advanceTimersByTime(250)
    expect(track.cues[1]).toEqual({ startTime: 12.5, endTime: 14.5 })
    observer.dispose()
    expect(track.cues[1]).toEqual({ startTime: 12, endTime: 14 })
    track.cues.push({ startTime: 20, endTime: 22 })
    observer.setOffset(1000)
    vi.advanceTimersByTime(1000)
    expect(track.cues[2]).toEqual({ startTime: 20, endTime: 22 })
    expect(vi.getTimerCount()).toBe(0)
  })

  it('rejects invalid timing offsets without changing cue times', () => {
    const { video, track } = fixture()
    const observer = observeSubtitleOffset(video)
    expect(() => observer.setOffset(10001)).toThrow()
    expect(() => observer.setOffset(0.5)).toThrow()
    expect(track.cues[0]).toEqual({ startTime: 4, endTime: 8 })
    observer.dispose()
  })
})
