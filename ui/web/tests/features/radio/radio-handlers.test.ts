import { beforeEach, describe, expect, it, vi } from 'vitest'
import { mediator } from '@/shared/lib/mediator/bus'
import { PLAYER_ACTIONS } from '@/features/player/player-actions'
import { CATALOG_ACTIONS } from '@/features/catalog/catalog-actions'
import { useRadioStore } from '@/features/radio/stores/radio-store'
import { registerRadioHandlers } from '@/features/radio/stores/radio-handlers'
import type { RadioStation } from '@/features/radio/api/radio-api'

const station: RadioStation = {
  id: 'radio-1', sourceId: 'source-1', externalId: 'radio-1', name: 'Radio', country: 'DK', language: null,
  genres: [], tags: [], logo: null, website: null, lastCheckedAt: null, createdAt: '', updatedAt: '',
  streams: [
    { url: 'https://radio.baander.app/primary', format: 'mp3', bitrate: 128, reliability: 2 },
    { url: 'https://radio.baander.app/fallback', format: 'mp3', bitrate: 128, reliability: 1 },
  ],
}

registerRadioHandlers()
beforeEach(() => {
  useRadioStore.getState().stopRadio()
  useRadioStore.getState().setAudioElement(null)
})

describe('music takes ownership from radio', () => {
  it.each([
    { action: PLAYER_ACTIONS.PLAY, observedPaused: false },
    { action: CATALOG_ACTIONS.PLAY_TRACK, observedPaused: false },
    { action: PLAYER_ACTIONS.PLAY, observedPaused: true },
    { action: CATALOG_ACTIONS.PLAY_TRACK, observedPaused: true },
  ])('$action cancels pending radio with observedPaused=$observedPaused', async ({ action, observedPaused }) => {
    let reject!: (error: Error) => void
    const pending = new Promise<void>((_resolve, no) => { reject = no })
    const play = vi.fn().mockReturnValueOnce(pending).mockResolvedValue(undefined)
    const pause = vi.fn()
    const audio = { src: '', play, pause } as unknown as HTMLAudioElement
    useRadioStore.getState().setAudioElement(audio)
    useRadioStore.getState().startStation(station, station.streams[0].url)
    if (observedPaused) useRadioStore.getState().observePlaying(false)

    mediator.dispatch(action, {}, 'test-music-owner')
    expect(useRadioStore.getState().activeStation).toBeNull()
    expect(useRadioStore.getState().isPlaying).toBe(false)
    expect(audio.src).toBe('')
    expect(pause).toHaveBeenCalledOnce()

    // A late native failure and queued fallback cannot reclaim the music graph.
    reject(new Error('old radio play failed'))
    await pending.catch(() => {})
    expect(useRadioStore.getState().tryNextStream()).toBeNull()
    expect(useRadioStore.getState().activeStation).toBeNull()
    expect(useRadioStore.getState().isPlaying).toBe(false)
    expect(play).toHaveBeenCalledOnce()
  })
})
