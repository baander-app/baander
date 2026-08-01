import { useCallback } from 'react'
import { useRadioStore } from '@/features/radio/stores/radio-store'
import {
  startRadioSession,
  stopRadioSession,
  type RadioStation,
} from '@/features/radio/api/radio-api'

/**
 * Manages radio playback: start/stop station, handle stream fallback.
 */
export function useRadioPlayback() {
  const startStation = useRadioStore((s) => s.startStation)
  const stopRadio = useRadioStore((s) => s.stopRadio)
  const tryNextStream = useRadioStore((s) => s.tryNextStream)
  const allStreamsFailed = useRadioStore((s) => s.allStreamsFailed)
  const setAllStreamsFailed = useRadioStore((s) => s.setAllStreamsFailed)

  const start = useCallback(async (station: RadioStation) => {
    if (station.streams.length === 0) {
      setAllStreamsFailed(true)
      return
    }

    // Sort streams by reliability descending, pick the best
    const sorted = [...station.streams].sort((a, b) => b.reliability - a.reliability)
    const bestStream = sorted[0]

    try {
      await startRadioSession(station.id, bestStream.url)
    } catch {
      // API call failed — still play locally, sync later
    }

    startStation(station, bestStream.url)
  }, [startStation, setAllStreamsFailed])

  const stop = useCallback(async () => {
    try {
      await stopRadioSession()
    } catch {
      // API call failed — still stop locally
    }

    stopRadio()
  }, [stopRadio])

  const handleStreamError = useCallback(() => {
    const nextUrl = tryNextStream()
    if (nextUrl === null) {
      // All streams exhausted
      return
    }
    // tryNextStream already updated the audio element
  }, [tryNextStream])

  return {
    start,
    stop,
    handleStreamError,
    allStreamsFailed,
  }
}
