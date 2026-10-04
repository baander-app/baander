import { useCallback, useEffect, useRef, useState } from 'react'
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
  isActive?: () => boolean
}

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value)
}

function isVersion(value: unknown): value is number {
  return typeof value === 'number' && Number.isSafeInteger(value) && value >= 0
}

function readData(body: unknown): Record<string, unknown> {
  if (!isRecord(body) || !isRecord(body.data)) throw new Error('Invalid preference response')
  return body.data
}

function conflictVersion(error: unknown): number | null | undefined {
  if (!isRecord(error) || !isRecord(error.response) || error.response.status !== 409) return undefined
  const body: unknown = error.response.data
  if (!isRecord(body) || !isRecord(body.error) || !isRecord(body.error.details)) return null
  return isVersion(body.error.details.currentVersion) ? body.error.details.currentVersion : null
}

const alwaysActive = () => true

export function usePreferenceSync<T>({
  baseUrl, toPayload, fromPayload, onRemoteUpdate, isActive = alwaysActive,
}: UsePreferenceSyncOptions<T>) {
  const versionRef = useRef(0)
  const debounceRef = useRef<ReturnType<typeof setTimeout> | null>(null)
  const epoch = useRef(0)
  const mounted = useRef(true)
  const applyingRemote = useRef(false)
  const localRevision = useRef(0)
  const resolving = useRef<Promise<boolean> | null>(null)
  const requests = useRef(new Set<AbortController>())
  const [conflict, setConflict] = useState<ConflictState>({ type: 'none', serverVersion: null })
  const [syncing, setSyncing] = useState(false)

  useEffect(() => {
    mounted.current = true
    const pending = requests.current
    const generation = epoch.current
    return () => {
      mounted.current = false
      epoch.current = generation + 1
      if (debounceRef.current !== null) clearTimeout(debounceRef.current)
      for (const controller of pending) controller.abort()
      pending.clear()
      resolving.current = null
    }
  }, [baseUrl])

  const beginRequest = useCallback(() => {
    if (!mounted.current || !isActive()) return null
    const controller = new AbortController()
    const startedAt = epoch.current
    requests.current.add(controller)
    return {
      signal: controller.signal,
      current: () => mounted.current && startedAt === epoch.current && isActive(),
      finish: () => { requests.current.delete(controller) },
    }
  }, [isActive])

  const applyRemote = useCallback((payload: Record<string, unknown>, version: number) => {
    applyingRemote.current = true
    try {
      onRemoteUpdate(fromPayload(payload), version)
      versionRef.current = version
    } finally {
      applyingRemote.current = false
    }
  }, [fromPayload, onRemoteUpdate])

  const fetchFromServer = useCallback(async () => {
    const request = beginRequest()
    if (!request) return false
    const revision = localRevision.current
    try {
      const response = await AXIOS_INSTANCE.get<unknown>(baseUrl, { signal: request.signal })
      const data = readData(response.data)
      if (!request.current() || revision !== localRevision.current || !isRecord(data.payload) || !isVersion(data.version) || data.version < versionRef.current) return false
      applyRemote(data.payload, data.version)
      return true
    } catch {
      return false
    } finally {
      request.finish()
    }
  }, [baseUrl, beginRequest, applyRemote])

  const save = useCallback(async (state: T, expectedVersion: number) => {
    const request = beginRequest()
    if (!request) return false
    setSyncing(true)
    try {
      const response = await AXIOS_INSTANCE.put<unknown>(baseUrl, {
        payload: toPayload(state), version: expectedVersion,
      }, { signal: request.signal })
      const data = readData(response.data)
      if (!request.current() || !isVersion(data.version) || data.version < versionRef.current) return false
      versionRef.current = data.version
      setConflict({ type: 'none', serverVersion: null })
      return true
    } catch (error: unknown) {
      const serverVersion = conflictVersion(error)
      if (request.current() && serverVersion !== undefined && (serverVersion === null || serverVersion >= versionRef.current)) {
        setConflict({ type: 'conflict', serverVersion })
      }
      return false
    } finally {
      request.finish()
      if (request.current()) setSyncing(false)
    }
  }, [baseUrl, beginRequest, toPayload])

  const pushToServer = useCallback((state: T) => {
    if (!mounted.current || !isActive() || applyingRemote.current) return
    localRevision.current++
    if (debounceRef.current !== null) clearTimeout(debounceRef.current)
    debounceRef.current = setTimeout(() => {
      debounceRef.current = null
      void save(state, versionRef.current)
    }, 500)
  }, [isActive, save])

  const resolveConflict = useCallback((resolution: 'mine' | 'theirs', localState?: T): Promise<boolean> => {
    if (resolving.current) return resolving.current
    if (debounceRef.current !== null) clearTimeout(debounceRef.current)
    debounceRef.current = null
    const pending = (async () => {
      if (resolution === 'mine') {
        if (localState === undefined || conflict.serverVersion === null) return false
        return save(localState, conflict.serverVersion)
      }
      const success = await fetchFromServer()
      if (success) setConflict({ type: 'none', serverVersion: null })
      return success
    })().finally(() => {
      if (resolving.current === pending) resolving.current = null
    })
    resolving.current = pending
    return pending
  }, [conflict.serverVersion, save, fetchFromServer])

  const fetchHistory = useCallback(async (): Promise<PreferenceVersion[]> => {
    const request = beginRequest()
    if (!request) return []
    try {
      const response = await AXIOS_INSTANCE.get<unknown>(`${baseUrl.replace(/\/$/, '')}/history`, { signal: request.signal })
      const data = readData(response.data)
      if (!request.current() || !Array.isArray(data.history)) return []
      return data.history.flatMap((item: unknown) =>
        isRecord(item) && isVersion(item.version) && typeof item.created_at === 'string'
          ? [{ version: item.version, createdAt: item.created_at }] : [])
    } catch {
      return []
    } finally {
      request.finish()
    }
  }, [baseUrl, beginRequest])

  const rollback = useCallback(async (targetVersion: number) => {
    const request = beginRequest()
    if (!request) return false
    try {
      const response = await AXIOS_INSTANCE.post<unknown>(`${baseUrl.replace(/\/$/, '')}/rollback`, {
        version: targetVersion,
      }, { signal: request.signal })
      const data = readData(response.data)
      if (!request.current() || !isRecord(data.payload) || !isVersion(data.version)) return false
      applyRemote(data.payload, data.version)
      return true
    } catch {
      return false
    } finally {
      request.finish()
    }
  }, [baseUrl, beginRequest, applyRemote])

  return { fetchFromServer, pushToServer, conflict, syncing, resolveConflict, fetchHistory, rollback, versionRef }
}
