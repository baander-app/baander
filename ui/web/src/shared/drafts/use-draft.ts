import { useState, useSyncExternalStore } from 'react'

export type DraftStatus = 'clean' | 'dirty' | 'conflict' | 'unavailable'

export interface DraftSubmission<T extends Record<string, unknown>> {
  readonly values: T
  readonly fields: ReadonlySet<keyof T>
  readonly revisions: ReadonlyMap<keyof T, number>
}

interface DraftSnapshot<T extends Record<string, unknown>> {
  values: T
  dirty: ReadonlySet<keyof T>
  status: DraftStatus
  pending: DraftSubmission<T> | null
}

function equal(left: unknown, right: unknown): boolean {
  if (Object.is(left, right)) return true
  if (!left || !right || typeof left !== 'object' || typeof right !== 'object') return false
  if (Array.isArray(left) !== Array.isArray(right)) return false
  const leftRecord = left as Record<string, unknown>
  const rightRecord = right as Record<string, unknown>
  const keys = Object.keys(leftRecord)
  return keys.length === Object.keys(rightRecord).length
    && keys.every(key => Object.hasOwn(rightRecord, key) && equal(leftRecord[key], rightRecord[key]))
}

function createDraft<T extends Record<string, unknown>>(initialData: T) {
  let baseline = { ...initialData }
  let lastReceived = { ...initialData }
  let revision = 0
  const revisions = new Map<keyof T, number>()
  const listeners = new Set<() => void>()
  let snapshot: DraftSnapshot<T> = {
    values: { ...initialData },
    dirty: new Set(),
    status: 'clean',
    pending: null,
  }
  const publish = (next: DraftSnapshot<T>) => {
    snapshot = next
    listeners.forEach(listener => listener())
  }
  const controller = {
    subscribe: (listener: () => void) => {
      listeners.add(listener)
      return () => { listeners.delete(listener) }
    },
    getSnapshot: () => snapshot,
    updateField: (field: keyof T, value: T[keyof T]) => {
      const dirty = new Set(snapshot.dirty)
      revisions.set(field, ++revision)
      const differsFromPending = snapshot.pending?.fields.has(field)
        && !equal(value, snapshot.pending.values[field])
      if (equal(value, baseline[field]) && !differsFromPending) dirty.delete(field)
      else dirty.add(field)
      publish({
        ...snapshot,
        values: { ...snapshot.values, [field]: value },
        dirty,
        status: snapshot.status === 'conflict' || snapshot.status === 'unavailable'
          ? snapshot.status
          : dirty.size ? 'dirty' : 'clean',
      })
    },
    receive: (data: T | null) => {
      if (data === null) {
        if (snapshot.status !== 'unavailable') publish({ ...snapshot, status: 'unavailable' })
        return
      }
      if (equal(data, lastReceived) && snapshot.status !== 'unavailable') return
      lastReceived = { ...data }
      const changed = !equal(data, baseline)
      baseline = { ...data }
      if (snapshot.dirty.size === 0 && !snapshot.pending) {
        publish({ ...snapshot, values: { ...data }, status: 'clean' })
      } else {
        publish({ ...snapshot, status: changed || snapshot.status === 'conflict' ? 'conflict' : 'dirty' })
      }
    },
    revokeAccess: () => {
      if (snapshot.status === 'unavailable' && snapshot.pending === null
        && Object.keys(baseline).length === 0
        && Object.keys(snapshot.values).every(field => snapshot.dirty.has(field))) return
      const values = {} as T
      for (const field of snapshot.dirty) values[field] = snapshot.values[field]
      baseline = {} as T
      publish({ ...snapshot, values, status: 'unavailable', pending: null })
    },
    resetToServer: (data: T) => {
      if (snapshot.pending || snapshot.status === 'unavailable') return
      baseline = { ...data }
      lastReceived = { ...data }
      publish({ values: { ...data }, dirty: new Set(), status: 'clean', pending: null })
    },
    beginSave: (): DraftSubmission<T> | null => {
      if (snapshot.status !== 'dirty' || snapshot.pending || snapshot.dirty.size === 0) return null
      const submission = {
        values: { ...snapshot.values },
        fields: new Set(snapshot.dirty),
        revisions: new Map(revisions),
      }
      publish({ ...snapshot, pending: submission })
      return submission
    },
    completeSave: (submission: DraftSubmission<T>) => {
      if (snapshot.pending !== submission) return
      const dirty = new Set(snapshot.dirty)
      const blocked = snapshot.status === 'conflict' || snapshot.status === 'unavailable'
      if (!blocked) {
        for (const field of submission.fields) {
          baseline[field] = submission.values[field]
          if (revisions.get(field) === submission.revisions.get(field) || equal(snapshot.values[field], baseline[field])) {
            dirty.delete(field)
          } else {
            dirty.add(field)
          }
        }
      }
      publish({ ...snapshot, dirty, pending: null, status: blocked ? snapshot.status : dirty.size ? 'dirty' : 'clean' })
    },
    failSave: (submission: DraftSubmission<T>) => {
      if (snapshot.pending !== submission) return
      const dirty = new Set(snapshot.dirty)
      for (const field of new Set([...dirty, ...submission.fields])) {
        if (equal(snapshot.values[field], baseline[field])) dirty.delete(field)
        else dirty.add(field)
      }
      const blocked = snapshot.status === 'conflict' || snapshot.status === 'unavailable'
      publish({ ...snapshot, dirty, pending: null, status: blocked ? snapshot.status : dirty.size ? 'dirty' : 'clean' })
    },
    resolveConflict: (choice: 'server' | 'local') => {
      if (snapshot.status !== 'conflict' || snapshot.pending) return
      const values = { ...baseline }
      const dirty = new Set<keyof T>()
      if (choice === 'local') {
        for (const field of snapshot.dirty) {
          values[field] = snapshot.values[field]
          if (!equal(values[field], baseline[field])) dirty.add(field)
        }
      }
      publish({ values, dirty, pending: null, status: dirty.size ? 'dirty' : 'clean' })
    },
  }
  return controller
}

/** Server snapshots and local field edits have independent lifetimes. */
export function useDraft<T extends Record<string, unknown>>({ identity, initialData }: {
  identity: string
  initialData: T
}) {
  const [owned, setOwned] = useState(() => ({ identity, controller: createDraft(initialData) }))
  if (owned.identity !== identity) {
    setOwned({ identity, controller: createDraft(initialData) })
  }
  const controller = owned.controller
  const snapshot = useSyncExternalStore(controller.subscribe, controller.getSnapshot, controller.getSnapshot)
  return {
    ...snapshot,
    canSave: snapshot.status === 'dirty' && !snapshot.pending && snapshot.dirty.size > 0,
    updateField: controller.updateField,
    receive: controller.receive,
    revokeAccess: controller.revokeAccess,
    resetToServer: controller.resetToServer,
    beginSave: controller.beginSave,
    completeSave: controller.completeSave,
    failSave: controller.failSave,
    resolveConflict: controller.resolveConflict,
  }
}
