import { useEffect, useRef } from 'react'
import { usePlayerStore, resolveNextIndex } from '@/features/player/stores/player-store'
import { useRadioStore } from '@/features/radio/stores/radio-store'
import { getCurrentTime, subscribe as subscribeToTime } from '@/features/player/stores/player-time-tracker'

const SEEK_OFFSET = 10 // seconds

type MediaSource = 'player' | 'radio' | null

// Note: artwork URLs pass through auth-stream-worker.ts; first-load 404 race is accepted (no retry)
function buildArtwork(albumPublicId?: string): MediaImage[] {
  if (!albumPublicId) return []
  const base = `/api/albums/${albumPublicId}/cover`
  return [
    { src: `${base}?preset=thumb`, sizes: '96x96', type: 'image/webp' },
    { src: `${base}?preset=small`, sizes: '128x128', type: 'image/webp' },
    { src: `${base}?preset=medium`, sizes: '256x256', type: 'image/webp' },
    { src: `${base}?preset=medium`, sizes: '512x512', type: 'image/webp' },
  ]
}

function buildRadioArtwork(logo: string | null): MediaImage[] {
  if (!logo) return []
  return [{ src: logo, sizes: '512x512', type: 'image/png' }]
}

function setPlayerMetadata(track: {
  title: string
  artistName?: string
  albumName?: string
  albumPublicId?: string
}) {
  navigator.mediaSession!.metadata = new MediaMetadata({
    title: track.title,
    artist: track.artistName ?? '',
    album: track.albumName ?? '',
    artwork: buildArtwork(track.albumPublicId),
  })
}

function setRadioMetadata(station: { name: string; logo: string | null }) {
  navigator.mediaSession!.metadata = new MediaMetadata({
    title: station.name,
    artist: 'Radio',
    artwork: buildRadioArtwork(station.logo),
  })
}

function clearMetadata() {
  navigator.mediaSession!.metadata = null
}

function isEndOfQueue(): boolean {
  const { queue, currentIndex, shuffle, repeat, shuffleBag } = usePlayerStore.getState()
  if (!queue.length || !usePlayerStore.getState().currentTrack) return true
  return resolveNextIndex(queue, currentIndex, shuffle, repeat, shuffleBag) === null
}

export function useMediaSession() {
  const sourceRef = useRef<MediaSource>(null)

  useEffect(() => {
    if (!('mediaSession' in navigator)) return
    const ms = navigator.mediaSession!

    // --- Position state ---
    function updatePositionState() {
      const state = usePlayerStore.getState()
      const duration = state.duration
      if (!isFinite(duration) || duration <= 0) return
      const position = Math.min(getCurrentTime(), duration)
      try {
        ms.setPositionState({ duration, playbackRate: 1, position })
      } catch {
        // setPositionState can throw on some browsers with invalid values
      }
    }

    // --- Dynamic handler swap ---
    function updateHandlerSet(source: MediaSource) {
      if (source === 'radio') {
        // Hide skip/seek controls for radio
        ms.setActionHandler('nexttrack', null)
        ms.setActionHandler('previoustrack', null)
        ms.setActionHandler('seekto', null)
        ms.setActionHandler('seekforward', null)
        ms.setActionHandler('seekbackward', null)
      } else if (source === 'player') {
        // Full handler set for player
        ms.setActionHandler('nexttrack', () => {
          usePlayerStore.getState().playNext()
        })
        ms.setActionHandler('previoustrack', () => {
          usePlayerStore.getState().playPrevious()
        })
        ms.setActionHandler('seekto', (details) => {
          if (details.seekTime != null) {
            usePlayerStore.getState().seekTo(details.seekTime)
            updatePositionState()
          }
        })
        ms.setActionHandler('seekforward', (details) => {
          const offset = details.seekOffset ?? SEEK_OFFSET
          usePlayerStore.getState().seekTo(getCurrentTime() + offset)
          updatePositionState()
        })
        ms.setActionHandler('seekbackward', (details) => {
          const offset = details.seekOffset ?? SEEK_OFFSET
          usePlayerStore.getState().seekTo(Math.max(0, getCurrentTime() - offset))
          updatePositionState()
        })
      }
    }

    // --- Action handlers (source-agnostic) ---
    ms.setActionHandler('play', () => {
      if (sourceRef.current === 'radio') {
        useRadioStore.getState().setIsPlaying(true)
      } else {
        usePlayerStore.getState().setIsPlaying(true)
      }
    })

    ms.setActionHandler('pause', () => {
      if (sourceRef.current === 'radio') {
        useRadioStore.getState().setIsPlaying(false)
      } else {
        usePlayerStore.getState().setIsPlaying(false)
      }
    })

    ms.setActionHandler('stop', () => {
      // Soft stop = pause
      if (sourceRef.current === 'radio') {
        useRadioStore.getState().setIsPlaying(false)
      } else {
        usePlayerStore.getState().setIsPlaying(false)
      }
    })

    // Register initial player handler set (will be swapped by subscriptions)
    updateHandlerSet('player')

    // --- Subscribe to player store (identity comparison on publicId) ---
    let lastTrackPublicId: string | null =
      usePlayerStore.getState().currentTrack?.publicId ?? null

    const unsubPlayer = usePlayerStore.subscribe((state, prevState) => {
      // Radio takes priority — skip player updates when radio is active
      const radioState = useRadioStore.getState()
      if (radioState.activeStation && radioState.isPlaying) return

      const trackId = state.currentTrack?.publicId ?? null
      const trackChanged = trackId !== lastTrackPublicId

      // React 18 auto-batching merges pause→playNext; no debounce required

      if (trackChanged) {
        lastTrackPublicId = trackId
        if (trackId && state.currentTrack) {
          sourceRef.current = 'player'
          setPlayerMetadata(state.currentTrack)
          updateHandlerSet('player')
          updatePositionState()
        } else {
          sourceRef.current = null
          clearMetadata()
        }
      }

      // Playback state
      if (state.isPlaying !== prevState.isPlaying) {
        if (!state.isPlaying && isEndOfQueue()) {
          ms.playbackState = 'none'
        } else {
          ms.playbackState = state.isPlaying ? 'playing' : 'paused'
        }
        // Update position state when playback resumes
        if (state.isPlaying) {
          updatePositionState()
        }
      }
    })

    // --- Subscribe to radio store (identity comparison on stationId) ---
    let lastStationId: string | null =
      useRadioStore.getState().activeStation?.id ?? null

    const unsubRadio = useRadioStore.subscribe((state, prevState) => {
      const stationId = state.activeStation?.id ?? null
      const stationChanged = stationId !== lastStationId

      // Evaluate playback state BEFORE nullifying sourceRef (fixes radio stop race)
      if (state.isPlaying !== prevState.isPlaying) {
        const wasActiveSource = sourceRef.current === 'radio'
        if (wasActiveSource) {
          ms.playbackState = state.isPlaying ? 'playing' : 'paused'
        }
      }

      if (stationChanged) {
        lastStationId = stationId
        if (stationId && state.activeStation) {
          sourceRef.current = 'radio'
          setRadioMetadata(state.activeStation)
          updateHandlerSet('radio')
        } else {
          sourceRef.current = null
          clearMetadata()
          ms.playbackState = 'none'
        }
      }
    })

    // --- Position state subscription (rides existing ~4Hz cadence) ---
    const unsubTime = subscribeToTime(() => {
      if (sourceRef.current === 'player') {
        updatePositionState()
      }
    })

    // --- Initialize from current state on mount ---
    const playerState = usePlayerStore.getState()
    const radioState = useRadioStore.getState()
    if (radioState.activeStation && radioState.isPlaying) {
      sourceRef.current = 'radio'
      setRadioMetadata(radioState.activeStation)
      updateHandlerSet('radio')
      ms.playbackState = 'playing'
    } else if (playerState.currentTrack) {
      sourceRef.current = 'player'
      setPlayerMetadata(playerState.currentTrack)
      updateHandlerSet('player')
      ms.playbackState = playerState.isPlaying ? 'playing' : 'paused'
      if (playerState.isPlaying) {
        updatePositionState()
      }
    }

    return () => {
      unsubPlayer()
      unsubRadio()
      unsubTime()
      ms.setActionHandler('play', null)
      ms.setActionHandler('pause', null)
      ms.setActionHandler('stop', null)
      ms.setActionHandler('nexttrack', null)
      ms.setActionHandler('previoustrack', null)
      ms.setActionHandler('seekto', null)
      ms.setActionHandler('seekforward', null)
      ms.setActionHandler('seekbackward', null)
      ms.metadata = null
    }
  }, [])
}
