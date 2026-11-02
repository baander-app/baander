import { describe, expect, it } from 'vitest'
import { parsePlaybackSnapshot } from '../services/playback-protocol'

const snapshot = {
  schemaVersion: 1, publicId: 'playbackSession0000001', revision: 3,
  media: { kind: 'song', publicId: 'playbackSong000000001' },
  queue: ['playbackSong000000001'], currentTrackIndex: 0, position: 24,
  playbackState: 'paused', desiredState: 'playing', observedState: 'paused',
  selectedDestinationId: 'playbackTarget0000001', activeDestinationId: 'playbackSource0000001',
  activeIncarnationId: '01900000-0000-7000-8000-000000000001',
  transfer: {
    attemptId: '01900000-0000-7000-8000-000000000002', state: 'starting',
    sourceIncarnationId: '01900000-0000-7000-8000-000000000001',
    targetIncarnationId: '01900000-0000-7000-8000-000000000003',
    position: 24, desiredState: 'playing',
  },
  destinations: [],
  commands: [{
    id: '01900000-0000-7000-8000-000000000004',
    attemptId: '01900000-0000-7000-8000-000000000002', revision: 3,
    incarnationId: '01900000-0000-7000-8000-000000000003', epoch: 1,
    type: 'start', expiresAt: '2026-11-01T12:00:20Z',
    payload: {
      media: { kind: 'song', publicId: 'playbackSong000000001' },
      queue: ['playbackSong000000001'], currentTrackIndex: 0, position: 24, desiredState: 'playing',
    },
  }],
}

describe('playback wire snapshots', () => {
  it('retains separate desired and observed state and complete command authority', () => {
    expect(parsePlaybackSnapshot(snapshot)).toEqual(snapshot)
  })

  it.each([
    { ...snapshot, schemaVersion: 2 },
    { ...snapshot, revision: -1 },
    { ...snapshot, observedState: undefined },
    { ...snapshot, currentTrackIndex: 4 },
    { ...snapshot, media: { kind: 'file', publicId: 'arbitrary' } },
    { ...snapshot, position: Infinity },
    { ...snapshot, commands: [{ ...snapshot.commands[0], incarnationId: null }] },
    { ...snapshot, commands: [{ ...snapshot.commands[0], payload: { position: 24 } }] },
    { ...snapshot, endpoint: { incarnationId: 'missing-destination-and-epoch' } },
  ])('ignores incomplete or unsupported wire state %#', (value) => {
    expect(parsePlaybackSnapshot(value)).toBeNull()
  })

  it('accepts an absent control result in a general snapshot', () => {
    const value = { ...snapshot, requestResult: null }
    expect(parsePlaybackSnapshot(value)).toEqual(value)
  })

  it('accepts queue-only command snapshots without changing the retained cursor', () => {
    const value = { ...snapshot, commands: [{ ...snapshot.commands[0], type: 'queue' }] }
    expect(parsePlaybackSnapshot(value)).toEqual(value)
  })

  it('accepts an idle snapshot without an endpoint credential', () => {
    const idle = {
      ...snapshot, media: null, queue: [], currentTrackIndex: 0, position: 0,
      playbackState: 'stopped', desiredState: 'stopped', observedState: 'unknown',
      selectedDestinationId: null, activeDestinationId: null, activeIncarnationId: null,
      transfer: null, commands: [],
    }
    expect(parsePlaybackSnapshot(idle)).toEqual(idle)
  })

  it('accepts an acknowledged volume-capable destination and volume command', () => {
    const value = {
      ...snapshot,
      destinations: [{
        destinationId: 'playbackTarget0000001', name: 'Desktop', kind: 'browser',
        capabilities: ['song', 'video', 'volume'], incarnationId: snapshot.commands[0].incarnationId,
        epoch: 1, observedState: 'paused', position: 24, observedVolume: 0.5, available: true,
      }],
      commands: [{ ...snapshot.commands[0], type: 'volume', payload: { ...snapshot.commands[0].payload, volume: 0.25 } }],
    }
    expect(parsePlaybackSnapshot(value)).toEqual(value)
    expect(parsePlaybackSnapshot({ ...value, commands: [{ ...value.commands[0], payload: { ...value.commands[0].payload, volume: 2 } }] })).toBeNull()
    expect(parsePlaybackSnapshot({ ...value, commands: [{ ...value.commands[0], payload: snapshot.commands[0].payload }] })).toBeNull()
    expect(parsePlaybackSnapshot({ ...value, destinations: [{ ...value.destinations[0], observedVolume: -1 }] })).toBeNull()
  })
})
