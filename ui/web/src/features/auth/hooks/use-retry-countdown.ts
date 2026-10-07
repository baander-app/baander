import { useCallback, useEffect, useState } from 'react'

export interface RetryCountdown {
  /** Seconds left before the next attempt is allowed; 0 when not waiting. */
  secondsLeft: number
  start: (seconds: number) => void
}

/** Counts down a Retry-After delay one second at a time. */
export function useRetryCountdown(): RetryCountdown {
  const [secondsLeft, setSecondsLeft] = useState(0)
  const waiting = secondsLeft > 0

  useEffect(() => {
    if (!waiting) return

    const timer = setInterval(() => {
      setSecondsLeft((seconds) => Math.max(0, seconds - 1))
    }, 1000)

    return () => clearInterval(timer)
  }, [waiting])

  const start = useCallback((seconds: number) => {
    setSecondsLeft(Math.max(0, Math.ceil(seconds)))
  }, [])

  return { secondsLeft, start }
}
