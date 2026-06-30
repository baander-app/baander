import { useState, useEffect, useRef, useCallback } from 'react'
import { getLibraries } from '../api/library-api'
import type { Library } from '../api/library-api'

export function useScanPolling(intervalMs = 3000) {
  const [scanningIds, setScanningIds] = useState<Set<string>>(new Set())
  const librariesRef = useRef<Library[]>([])
  const timerRef = useRef<ReturnType<typeof setInterval> | null>(null)

  const checkForScanning = useCallback(async () => {
    try {
      const libraries = await getLibraries()
      librariesRef.current = libraries
      const ids = new Set(
        libraries
          .filter((l) => l.scanStatus === 'scanning')
          .map((l) => l.id),
      )
      setScanningIds(ids)
    } catch {
      // Silently ignore — will retry next interval
    }
  }, [])

  const startPolling = useCallback(() => {
    if (timerRef.current) return
    checkForScanning()
    timerRef.current = setInterval(checkForScanning, intervalMs)
  }, [checkForScanning, intervalMs])

  const stopPolling = useCallback(() => {
    if (timerRef.current) {
      clearInterval(timerRef.current)
      timerRef.current = null
    }
  }, [])

  useEffect(() => {
    return () => stopPolling()
  }, [stopPolling])

  return { scanningIds, startPolling, stopPolling, refresh: checkForScanning }
}
