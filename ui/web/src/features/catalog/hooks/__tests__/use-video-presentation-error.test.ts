import { act, renderHook } from '@testing-library/react'
import { beforeEach, describe, expect, it } from 'vitest'
import type { PlaybackSnapshotResource } from '@/shared/api-client/gen/endpoints'
import { UNCONFIRMED_PLAYBACK_REFUSAL } from '@/features/session/services/playback-status'
import { useVideoPresentation } from '../../stores/video-presentation-store'
import { useVideoPresentationError } from '../use-video-presentation-error'

function session(held: boolean): PlaybackSnapshotResource {
  return {
    schemaVersion: 1, publicId: 'session', revision: 1, media: { kind: 'video', publicId: 'movie' }, queue: [], currentTrackIndex: 0,
    position: 0, playbackState: 'stopped', desiredState: 'stopped', observedState: 'stopped',
    selectedDestinationId: held ? 'closed' : null, activeDestinationId: held ? 'closed' : null, activeIncarnationId: held ? 'closed-tab' : null,
    transfer: null, commands: [],
    destinations: [{
      destinationId: 'closed', incarnationId: 'closed-tab', name: 'Closed tab', kind: 'browser', epoch: 1, available: false,
      capabilities: ['song', 'video'], observedState: 'stopped', position: 0, observedVolume: null,
    }],
  }
}

describe('the video error shown to the viewer', () => {
  beforeEach(() => {
    useVideoPresentation.getState().select('')
  })

  it('keeps the unconfirmed-playback refusal while the session still holds the gone runner', () => {
    useVideoPresentation.getState().reportError(new Error(UNCONFIRMED_PLAYBACK_REFUSAL))

    const { result } = renderHook(() => useVideoPresentationError(session(true)))

    expect(result.current).toBe(UNCONFIRMED_PLAYBACK_REFUSAL)
    expect(useVideoPresentation.getState().error).toBe(UNCONFIRMED_PLAYBACK_REFUSAL)
  })

  it('clears the refusal, in the store too, once the session has no unconfirmed playback', () => {
    useVideoPresentation.getState().reportError(new Error(UNCONFIRMED_PLAYBACK_REFUSAL))
    const { result, rerender } = renderHook(({ snapshot }) => useVideoPresentationError(snapshot), { initialProps: { snapshot: session(true) } })

    rerender({ snapshot: session(false) })

    expect(result.current).toBeNull()
    expect(useVideoPresentation.getState().error).toBeNull()

    // A runner that goes away later does not bring the old refusal back.
    rerender({ snapshot: session(true) })
    expect(result.current).toBeNull()
  })

  it('leaves every other error in place, whatever the session holds', () => {
    useVideoPresentation.getState().reportError(new Error('The destination rejected the playback request.'))

    const { result, rerender } = renderHook(({ snapshot }) => useVideoPresentationError(snapshot), { initialProps: { snapshot: session(true) } })
    rerender({ snapshot: session(false) })

    expect(result.current).toBe('The destination rejected the playback request.')
    expect(useVideoPresentation.getState().error).toBe('The destination rejected the playback request.')
  })

  it('keeps the refusal while no session snapshot has arrived', () => {
    useVideoPresentation.getState().reportError(new Error(UNCONFIRMED_PLAYBACK_REFUSAL))

    const { result } = renderHook(() => useVideoPresentationError(undefined))

    expect(result.current).toBe(UNCONFIRMED_PLAYBACK_REFUSAL)
  })

  it('shows a refusal reported after an earlier one was cleared', () => {
    const { result, rerender } = renderHook(({ snapshot }) => useVideoPresentationError(snapshot), { initialProps: { snapshot: session(true) } })

    act(() => { useVideoPresentation.getState().reportError(new Error(UNCONFIRMED_PLAYBACK_REFUSAL)) })
    expect(result.current).toBe(UNCONFIRMED_PLAYBACK_REFUSAL)
    rerender({ snapshot: session(false) })
    expect(result.current).toBeNull()

    rerender({ snapshot: session(true) })
    act(() => { useVideoPresentation.getState().reportError(new Error(UNCONFIRMED_PLAYBACK_REFUSAL)) })
    expect(result.current).toBe(UNCONFIRMED_PLAYBACK_REFUSAL)
  })
})
