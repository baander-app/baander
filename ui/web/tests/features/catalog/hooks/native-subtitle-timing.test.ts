import { describe, expect, it, vi } from 'vitest'
import { observeNativeSubtitleTiming } from '@/features/catalog/hooks/native-subtitle-timing'

const trackFixture = (ends = [4], mode: TextTrackMode = 'showing', kind: TextTrackKind = 'subtitles') => {
  const owners = new Map<TextTrackCue, TextTrack | null>()
  let active: TextTrackCue[] = []
  const cues: TextTrackCue[] = []
  const track = {
    mode, kind,
    get activeCues() { return active },
    get cues() { return cues },
    removeCue: vi.fn((cue: TextTrackCue) => {
      cues.splice(cues.indexOf(cue), 1)
      active = active.filter(entry => entry !== cue)
      owners.set(cue, null)
    }),
    addCue: vi.fn((cue: TextTrackCue) => {
      cues.push(cue)
      owners.set(cue, track as unknown as TextTrack)
    }),
  }
  for (const endTime of ends) {
    const cue = { startTime: endTime - 2, endTime, get track() { return owners.get(cue) ?? null } } as TextTrackCue
    cues.push(cue)
    owners.set(cue, track as unknown as TextTrack)
    active.push(cue)
  }
  return { track: track as unknown as TextTrack, cues,
    remove: track.removeCue, add: track.addCue,
    activate: (entries: TextTrackCue[]) => { active = entries },
    move: (cue: TextTrackCue, owner: TextTrack | null) => { owners.set(cue, owner) },
  }
}

const fixture = (...entries: ReturnType<typeof trackFixture>[]) => {
  let listener: (() => void) | undefined
  const tracks = entries.map(entry => entry.track)
  const video = {
    currentTime: 5, seeking: false, textTracks: tracks,
    addEventListener: vi.fn((_event: string, callback: () => void) => { listener = callback }),
    removeEventListener: vi.fn(),
  }
  const dispose = observeNativeSubtitleTiming(video as unknown as HTMLVideoElement)
  return { video, tracks, dispose, update: () => listener?.() }
}

describe('native subtitle timing', () => {
  it.each(['subtitles', 'captions'] as const)('refreshes the same expired %s cue without changing selection or timestamps', kind => {
    const entry = trackFixture([4], 'showing', kind)
    const cue = entry.cues[0]
    const { update } = fixture(entry)
    update()
    expect(entry.remove).toHaveBeenCalledExactlyOnceWith(cue)
    expect(entry.add).toHaveBeenCalledExactlyOnceWith(cue)
    expect(entry.cues).toEqual([cue])
    expect(entry.track.activeCues).toEqual([])
    expect(entry.track.mode).toBe('showing')
    expect([cue.startTime, cue.endTime]).toEqual([2, 4])
  })

  it.each([2, 4, 4.1, NaN, Infinity, -Infinity])('leaves a cue alone at native time %s', time => {
    const entry = trackFixture()
    const { video, update } = fixture(entry)
    video.currentTime = time
    update()
    expect(entry.remove).not.toHaveBeenCalled()
  })

  it.each([
    ['disabled', 'subtitles'], ['hidden', 'subtitles'], ['showing', 'metadata'],
    ['showing', 'chapters'], ['showing', 'descriptions'],
  ] as const)('ignores %s %s tracks', (mode, kind) => {
    const entry = trackFixture([4], mode, kind)
    fixture(entry).update()
    expect(entry.remove).not.toHaveBeenCalled()
    expect(entry.track.mode).toBe(mode)
  })

  it('keeps future and normally inactive cues untouched', () => {
    const entry = trackFixture([7])
    const { video, update } = fixture(entry)
    update()
    expect(entry.remove).not.toHaveBeenCalled()
    entry.activate([])
    video.currentTime = 9
    update()
    expect(entry.remove).not.toHaveBeenCalled()
  })

  it('skips seeking and permits the same cue to reenter after a backward seek', () => {
    const entry = trackFixture()
    const cue = entry.cues[0]
    const { video, update } = fixture(entry)
    video.seeking = true
    update()
    expect(entry.remove).not.toHaveBeenCalled()
    video.seeking = false
    update()
    expect(entry.remove).toHaveBeenCalledOnce()
    video.currentTime = 3
    entry.activate([cue])
    update()
    expect(entry.track.activeCues?.[0]).toBe(cue)
    expect(entry.remove).toHaveBeenCalledOnce()
    video.currentTime = 5
    update()
    expect(entry.remove).toHaveBeenCalledTimes(2)
    expect(entry.cues[0]).toBe(cue)
  })

  it('does not mutate cues owned by another track', () => {
    const entry = trackFixture()
    const other = trackFixture()
    entry.move(entry.cues[0], other.track)
    fixture(entry, other).update()
    expect(entry.remove).not.toHaveBeenCalled()
    expect(other.remove).toHaveBeenCalledOnce()
  })

  it('ignores a stale active entry that no longer belongs to the track cue list', () => {
    const entry = trackFixture()
    const { update } = fixture(entry)
    entry.cues.splice(0, 1)
    update()
    expect(entry.remove).not.toHaveBeenCalled()
  })

  it('checks current track attachment and mode after snapshotting all active cues', () => {
    const first = trackFixture()
    const detached = trackFixture()
    const hidden = trackFixture()
    const { tracks, update } = fixture(first, detached, hidden)
    const remove = first.remove.getMockImplementation()!
    first.remove.mockImplementation(cue => {
      tracks.splice(tracks.indexOf(detached.track), 1)
      hidden.track.mode = 'hidden'
      remove(cue)
    })
    update()
    expect(first.remove).toHaveBeenCalledOnce()
    expect(detached.remove).not.toHaveBeenCalled()
    expect(hidden.remove).not.toHaveBeenCalled()
  })

  it('snapshots live active cues and prevents repeated or reentrant refresh', () => {
    const entry = trackFixture([4, 3])
    const original = [...entry.cues]
    entry.activate(original)
    const { update } = fixture(entry)
    const remove = entry.remove.getMockImplementation()!
    entry.remove.mockImplementation(cue => { remove(cue); update() })
    update()
    expect(entry.remove.mock.calls).toEqual([[original[0]], [original[1]]])
    expect(entry.add.mock.calls).toEqual([[original[0]], [original[1]]])
    update()
    expect(entry.remove).toHaveBeenCalledTimes(2)
  })

  it('stops refreshing when native callbacks move the clock backward', () => {
    const entry = trackFixture([4, 3])
    const { video, update } = fixture(entry)
    const remove = entry.remove.getMockImplementation()!
    entry.remove.mockImplementation(cue => { remove(cue); video.currentTime = 0 })
    update()
    expect(entry.remove).toHaveBeenCalledOnce()
  })

  it('restores a removed cue and reports a failed refresh', () => {
    const entry = trackFixture()
    const cue = entry.cues[0]
    const { update } = fixture(entry)
    entry.add.mockImplementationOnce(() => { throw new Error('Native add failed') })
    expect(update).toThrow('Native add failed')
    expect(entry.cues).toEqual([cue])
    expect(cue.track).toBe(entry.track)
    expect(entry.add).toHaveBeenCalledTimes(2)
  })

  it('reports removal failures without removing the cue or leaving refresh blocked', () => {
    const entry = trackFixture()
    const { update } = fixture(entry)
    entry.remove.mockImplementationOnce(() => { throw new Error('Native remove failed') })
    expect(update).toThrow('Native remove failed')
    expect(entry.cues).toHaveLength(1)
    update()
    expect(entry.add).toHaveBeenCalledOnce()
  })

  it('restores the current cue if disposal occurs during removal and leaves later cues untouched', () => {
    const entry = trackFixture([4, 3])
    const original = [...entry.cues]
    const { dispose, update } = fixture(entry)
    const remove = entry.remove.getMockImplementation()!
    entry.remove.mockImplementation(cue => { remove(cue); dispose() })
    update()
    expect(entry.remove).toHaveBeenCalledExactlyOnceWith(original[0])
    expect(entry.add).toHaveBeenCalledExactlyOnceWith(original[0])
    expect(original[0].track).toBe(entry.track)
    expect(entry.cues).toContain(original[1])
  })

  it('removes its listener once and fences already captured callbacks', () => {
    const entry = trackFixture()
    const { video, dispose, update } = fixture(entry)
    dispose()
    dispose()
    update()
    expect(video.addEventListener).toHaveBeenCalledExactlyOnceWith('timeupdate', expect.any(Function))
    expect(video.removeEventListener).toHaveBeenCalledExactlyOnceWith('timeupdate', video.addEventListener.mock.calls[0][1])
    expect(entry.remove).not.toHaveBeenCalled()
  })
})
