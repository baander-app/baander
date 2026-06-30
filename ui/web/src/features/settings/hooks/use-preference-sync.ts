import { useCallback, useRef, useState } from 'react'
import { AXIOS_INSTANCE } from '@/shared/api-client/axios-instance'

export interface PreferenceVersion {
  version: number
  createdAt: string
}

export interface ConflictState {
  type: 'none' | 'conflict'
  serverVersion: number | null
}

interface UsePreferenceSyncOptions<T> {
  baseUrl: string
  toPayload: (state: T) => Record<string, unknown>
  fromPayload: (payload: Record<string, unknown>) => T
  onRemoteUpdate: (data: T, version: number) => void
}

export function usePreferenceSync<T>({
  baseUrl,
  toPayload,
  fromPayload,
  onRemoteUpdate,
}: UsePreferenceSyncOptions<T>) {
  const versionRef = useRef(0)
  const debounceRef = useRef<ReturnType<typeof setTimeout> | null>(null)
  const [conflict, setConflict] = useState<ConflictState>({ type: 'none', serverVersion: null })
  const [syncing, setSyncing] = useState(false)

  const fetchFromServer = useCallback(async () => {
    try {
      const res = await AXIOS_INSTANCE.get(baseUrl)
      const { payload, version } = res.data?.data ?? res.data ?? {}
      if (payload && version) {
        versionRef.current = version
        onRemoteUpdate(fromPayload(payload as Record<string, unknown>), version)
        return true
      }
    } catch {
      // 404 or network error — first-time user or offline
    }
    return false
  }, [baseUrl, fromPayload, onRemoteUpdate])

  const pushToServer = useCallback(
    (state: T, force = false) => {
      if (debounceRef.current) {
        clearTimeout(debounceRef.current)
      }

      const doPush = async () => {
        const currentVersion = force ? versionRef.current : versionRef.current
        const payload = toPayload(state)

        setSyncing(true)
        try {
          const res = await AXIOS_INSTANCE.put(baseUrl, {
            payload,
            version: currentVersion,
          })
          const data = res.data?.data ?? res.data
          versionRef.current = data.version
          setConflict({ type: 'none', serverVersion: null })
        } catch (err: unknown) {
          const status = (err as { response?: { status?: number } })?.response?.status
          if (status === 409) {
            const currentVersion = (err as { response?: { data?: { data?: { currentVersion?: number } } } })?.response?.data?.data?.currentVersion
              ?? (err as { response?: { data?: { currentVersion?: number } } })?.response?.data?.currentVersion
              ?? null
            setConflict({ type: 'conflict', serverVersion: currentVersion })
          }
          // Other errors: silent fallback, local state preserved
        } finally {
          setSyncing(false)
        }
      }

      debounceRef.current = setTimeout(doPush, 500)
    },
    [baseUrl, toPayload],
  )

  const resolveConflict = useCallback(
    async (resolution: 'mine' | 'theirs', localState?: T) => {
      if (resolution === 'mine' && localState) {
        // Force push with current server version
        const payload = toPayload(localState)
        try {
          const res = await AXIOS_INSTANCE.put(baseUrl, {
            payload,
            version: versionRef.current,
          })
          const data = res.data?.data ?? res.data
          versionRef.current = data.version
        } catch {
          // If still conflicting, version will be wrong — next sync will sort it out
        }
      } else {
        // Refetch server state
        await fetchFromServer()
      }
      setConflict({ type: 'none', serverVersion: null })
    },
    [baseUrl, toPayload, fetchFromServer],
  )

  const fetchHistory = useCallback(async (): Promise<PreferenceVersion[]> => {
    try {
      const res = await AXIOS_INSTANCE.get(`${baseUrl}/history`)
      const data = res.data?.data ?? res.data
      return data.history ?? []
    } catch {
      return []
    }
  }, [baseUrl])

  const rollback = useCallback(
    async (targetVersion: number) => {
      try {
        const res = await AXIOS_INSTANCE.post(`${baseUrl}/rollback`, {
          version: targetVersion,
        })
        const data = res.data?.data ?? res.data
        versionRef.current = data.version
        onRemoteUpdate(fromPayload(data.payload as Record<string, unknown>), data.version)
        return true
      } catch {
        return false
      }
    },
    [baseUrl, fromPayload, onRemoteUpdate],
  )

  return {
    fetchFromServer,
    pushToServer,
    conflict,
    syncing,
    resolveConflict,
    fetchHistory,
    rollback,
    versionRef,
  }
}
