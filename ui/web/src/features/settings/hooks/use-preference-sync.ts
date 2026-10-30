import { isAxiosError, isCancel } from 'axios'
import { useCallback, useEffect, useRef, useState } from 'react'
import { useLiveCoordinator } from '@/shared/live/context'
import { captureOwnership, isCurrentOwnership, subscribeOwnership } from '@/shared/live/ownership'
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

function retryableSaveError(error: unknown): boolean {
  if (!isAxiosError(error) || isCancel(error)) return false
  if (error.response) return error.response.status >= 500 && error.response.status <= 599
  return ['ERR_NETWORK', 'ECONNABORTED', 'ETIMEDOUT', 'ECONNRESET'].includes(error.code ?? '')
}

type PreferenceReadResult = 'applied' | 'deferred' | 'failed'

const alwaysActive = () => true

export function usePreferenceSync<T>({
  baseUrl, toPayload, fromPayload, onRemoteUpdate, isActive = alwaysActive,
}: UsePreferenceSyncOptions<T>) {
  const coordinator = useLiveCoordinator()
  const versionRef = useRef(0)
  const remoteVersionRef = useRef(0)
  const acknowledgedRevision = useRef(0)
  const saving = useRef(false)
  const conflicted = useRef(false)
  const latestState = useRef<T | undefined>(undefined)
  const debounceRef = useRef<ReturnType<typeof setTimeout> | null>(null)
  const retryTimer = useRef<ReturnType<typeof setTimeout> | null>(null)
  const retryAttempt = useRef(0)
  const retrySave = useRef<((state: T, version: number) => Promise<boolean>) | null>(null)
  const cancelRetry = useCallback(() => {
    if (retryTimer.current !== null) clearTimeout(retryTimer.current)
    retryTimer.current = null
    retryAttempt.current = 0
  }, [])
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
    const unsubscribeOwnership = subscribeOwnership(cancelRetry)
    return () => {
      unsubscribeOwnership()
      cancelRetry()
      mounted.current = false
      epoch.current = generation + 1
      if (debounceRef.current !== null) clearTimeout(debounceRef.current)
      for (const controller of pending) controller.abort()
      pending.clear()
      resolving.current = null
    }
  }, [baseUrl, cancelRetry])

  const beginRequest = useCallback(() => {
    if (!mounted.current || !isActive()) return null
    const controller = new AbortController()
    const startedAt = epoch.current
    const owner = captureOwnership()
    requests.current.add(controller)
    return {
      signal: controller.signal,
      current: () => mounted.current && startedAt === epoch.current && isActive() && isCurrentOwnership(owner),
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

  const readFromServer = useCallback(async (signal?: AbortSignal, force = false): Promise<PreferenceReadResult> => {
    const request = beginRequest()
    if (!request) return 'failed'
    const revision = localRevision.current
    try {
      const response = await AXIOS_INSTANCE.get<unknown>(baseUrl, { signal: signal ?? request.signal })
      const data = readData(response.data)
      if (signal?.aborted || !request.current() || !isRecord(data.payload) || !isVersion(data.version) || data.version < Math.max(versionRef.current, remoteVersionRef.current)) return 'failed'
      remoteVersionRef.current = data.version
      const dirty = acknowledgedRevision.current !== localRevision.current
      if (!force && !saving.current && dirty && data.version > versionRef.current) {
        cancelRetry()
        conflicted.current = true
        if (debounceRef.current !== null) clearTimeout(debounceRef.current)
        debounceRef.current = null
        setConflict({ type: 'conflict', serverVersion: data.version })
      }
      if (revision !== localRevision.current || (!force && (saving.current || conflicted.current || dirty))) return 'deferred'
      applyRemote(data.payload, data.version)
      acknowledgedRevision.current = revision
      return 'applied'
    } catch {
      return 'failed'
    } finally {
      request.finish()
    }
  }, [baseUrl, beginRequest, applyRemote, cancelRetry])

  const fetchFromServer = useCallback(async (signal?: AbortSignal) =>
    await readFromServer(signal) === 'applied', [readFromServer])

  const save = useCallback(async (state: T, expectedVersion: number) => {
    if (saving.current) return false
    const request = beginRequest()
    if (!request) return false
    const revision = localRevision.current
    const release = coordinator?.beginMutation(['preferences'])
    saving.current = true
    setSyncing(true)
    try {
      const response = await AXIOS_INSTANCE.put<unknown>(baseUrl, {
        payload: toPayload(state), version: expectedVersion,
      }, { signal: request.signal })
      const data = readData(response.data)
      if (!request.current() || !isVersion(data.version) || data.version < versionRef.current) return false
      versionRef.current = data.version
      acknowledgedRevision.current = revision
      cancelRetry()
      conflicted.current = false
      setConflict({ type: 'none', serverVersion: null })
      return true
    } catch (error: unknown) {
      const serverVersion = conflictVersion(error)
      if (request.current() && serverVersion !== undefined && (serverVersion === null || serverVersion >= Math.max(versionRef.current, remoteVersionRef.current))) {
        if (serverVersion !== null) remoteVersionRef.current = serverVersion
        cancelRetry()
        conflicted.current = true
        if (debounceRef.current !== null) clearTimeout(debounceRef.current)
        debounceRef.current = null
        setConflict({ type: 'conflict', serverVersion })
      } else if (request.current() && !conflicted.current && revision === localRevision.current && retryableSaveError(error)) {
        const owner = captureOwnership()
        const delay = Math.max(1000, Math.min(30000,
          1000 * 2 ** Math.min(retryAttempt.current++, 5) * (0.8 + Math.random() * 0.4)))
        retryTimer.current = setTimeout(() => {
          retryTimer.current = null
          if (!request.current() || !isCurrentOwnership(owner) || conflicted.current
            || revision !== localRevision.current || acknowledgedRevision.current === localRevision.current) return
          const latest = latestState.current
          if (latest !== undefined) retrySave.current?.(latest, versionRef.current).catch(() => undefined)
        }, delay)
      }
      return false
    } finally {
      request.finish()
      saving.current = false
      release?.()
      if (request.current()) setSyncing(false)
    }
  }, [baseUrl, beginRequest, toPayload, coordinator, cancelRetry])
  useEffect(() => { retrySave.current = save }, [save])

  const pushToServer = useCallback((state: T) => {
    if (!mounted.current || !isActive() || applyingRemote.current) return
    cancelRetry()
    const owner = captureOwnership()
    latestState.current = state
    localRevision.current++
    if (debounceRef.current !== null) clearTimeout(debounceRef.current)
    if (conflicted.current) return
    const attempt = () => {
      debounceRef.current = null
      if (conflicted.current || !isActive() || !isCurrentOwnership(owner)) return
      if (saving.current) {
        debounceRef.current = setTimeout(attempt, 100)
        return
      }
      save(latestState.current as T, versionRef.current).catch(() => undefined)
    }
    debounceRef.current = setTimeout(attempt, 500)
  }, [isActive, save, cancelRetry])

  const resolveConflict = useCallback((resolution: 'mine' | 'theirs', localState?: T): Promise<boolean> => {
    if (resolving.current) return resolving.current
    cancelRetry()
    if (debounceRef.current !== null) clearTimeout(debounceRef.current)
    debounceRef.current = null
    const pending = (async () => {
      if (resolution === 'mine') {
        if (localState === undefined || conflict.serverVersion === null) return false
        return save(localState, conflict.serverVersion)
      }
      const success = await readFromServer(undefined, true) === 'applied'
      if (success) conflicted.current = false
      if (success) setConflict({ type: 'none', serverVersion: null })
      return success
    })().finally(() => {
      if (resolving.current === pending) resolving.current = null
    })
    resolving.current = pending
    return pending
  }, [conflict.serverVersion, save, readFromServer, cancelRetry])

  const fetchHistory = useCallback(async (signal?: AbortSignal): Promise<PreferenceVersion[]> => {
    const request = beginRequest()
    if (!request) return []
    try {
      const response = await AXIOS_INSTANCE.get<unknown>(`${baseUrl.replace(/\/$/, '')}/history`, { signal: signal ?? request.signal })
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
    const revision = localRevision.current
    const request = beginRequest()
    if (!request) return false
    const release = coordinator?.beginMutation(['preferences'])
    try {
      const response = await AXIOS_INSTANCE.post<unknown>(`${baseUrl.replace(/\/$/, '')}/rollback`, {
        version: targetVersion,
      }, { signal: request.signal })
      const data = readData(response.data)
      if (!request.current() || revision !== localRevision.current || !isRecord(data.payload) || !isVersion(data.version)) return false
      applyRemote(data.payload, data.version)
      acknowledgedRevision.current = revision
      return true
    } catch {
      return false
    } finally {
      request.finish()
      release?.()
    }
  }, [baseUrl, beginRequest, applyRemote, coordinator])

  const reader = useRef(readFromServer)
  useEffect(() => { reader.current = readFromServer })
  useEffect(() => coordinator?.registerAdapter({
    id: `preference:${baseUrl}`, family: 'preferences', isActive,
    read: async signal => {
      if (await reader.current(signal) === 'failed') throw new Error('Preference snapshot unavailable')
    },
  }), [coordinator, baseUrl, isActive])

  return { fetchFromServer, pushToServer, conflict, syncing, resolveConflict, fetchHistory, rollback, versionRef }
}
