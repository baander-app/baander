import { useState, useCallback } from 'react'
import type { RadioStation, StreamInfo } from '@/features/radio/api/radio-api'

interface StreamFallbackState {
  currentIndex: number
  isExhausted: boolean
  isBuffering: boolean
}

/**
 * Manages stream fallback for a radio station.
 * Returns the current stream to try and a function to advance to the next on error.
 */
export function useStreamFallback(station: RadioStation | null) {
  const sorted = station
    ? [...station.streams].sort((a, b) => b.reliability - a.reliability)
    : []

  const [state, setState] = useState<StreamFallbackState>({
    currentIndex: 0,
    isExhausted: false,
    isBuffering: false,
  })

  const currentStream: StreamInfo | null = sorted[state.currentIndex] ?? null

  const tryNext = useCallback(() => {
    setState((prev) => {
      const nextIndex = prev.currentIndex + 1
      if (nextIndex >= sorted.length) {
        return { ...prev, isExhausted: true, isBuffering: false }
      }
      return { ...prev, currentIndex: nextIndex, isBuffering: true }
    })
  }, [sorted.length])

  const setBuffering = useCallback((buffering: boolean) => {
    setState((prev) => ({ ...prev, isBuffering: buffering }))
  }, [])

  const reset = useCallback(() => {
    setState({ currentIndex: 0, isExhausted: false, isBuffering: false })
  }, [])

  return {
    currentStream,
    tryNext,
    reset,
    setBuffering,
    streamIndex: state.currentIndex,
    isExhausted: state.isExhausted,
    isBuffering: state.isBuffering,
    totalStreams: sorted.length,
  }
}
