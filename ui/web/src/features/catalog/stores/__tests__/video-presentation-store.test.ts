import { beforeEach, describe, expect, it } from 'vitest'
import { useVideoPresentation } from '../video-presentation-store'

describe('video presentation errors', () => {
  beforeEach(() => {
    useVideoPresentation.getState().select('')
  })

  it('dismisses the shown error only while it is still the named message', () => {
    const store = useVideoPresentation.getState()
    store.reportError(new Error('Previous playback is unconfirmed.'))

    store.dismissError('Another message')
    expect(useVideoPresentation.getState().error).toBe(
      'Previous playback is unconfirmed.'
    )

    store.dismissError('Previous playback is unconfirmed.')
    expect(useVideoPresentation.getState().error).toBeNull()
  })

  it('does not notify subscribers when there is nothing to dismiss', () => {
    let notifications = 0
    const unsubscribe = useVideoPresentation.subscribe(() => {
      notifications += 1
    })

    useVideoPresentation
      .getState()
      .dismissError('Previous playback is unconfirmed.')

    unsubscribe()
    expect(notifications).toBe(0)
  })

  it('retains independent timing preferences for the same movie and resets them for another movie', () => {
    const store = useVideoPresentation.getState()
    store.select('video-1')
    store.publish({
      subtitleOffsetMs: 600,
      audioOffsetMs: -300,
      quality: '720p'
    })
    store.select('video-1')
    expect(useVideoPresentation.getState()).toMatchObject({
      subtitleOffsetMs: 600,
      audioOffsetMs: -300,
      quality: '720p'
    })
    store.select('video-2')
    expect(useVideoPresentation.getState()).toMatchObject({
      subtitleOffsetMs: 0,
      audioOffsetMs: 0,
      quality: 'auto'
    })
  })
})
