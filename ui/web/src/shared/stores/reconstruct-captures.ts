import type { DebugValue, StoreCaptures, StoreDebugSnapshot, StoreTraceEvent } from './debug'

/** Apply only lifecycle and state provenance; action records leave captures unchanged. */
export function foldStoreEvent(stores: StoreCaptures, event: StoreTraceEvent): StoreCaptures {
  if (event.kind === 'retire') {
    const next = { ...stores }
    delete next[event.instanceId]
    return next
  }
  if (event.kind !== 'register' && event.kind !== 'update') return stores
  return { ...stores, [event.instanceId]: {
    name: event.store,
    capture: event.after ?? { value: '[unavailable]', complete: false },
    sourceEventId: event.id,
  } }
}

function detachedValue(value: DebugValue): DebugValue {
  if (value === null || typeof value !== 'object') return value
  if (Array.isArray(value)) return Object.freeze(value.map(detachedValue)) as DebugValue[]
  return Object.freeze(Object.fromEntries(Object.entries(value).map(([key, child]) => [key, detachedValue(child)])))
}

export type ReconstructedCaptures =
  | { status: 'unavailable' }
  | { status: 'available'; stores: StoreCaptures; partial: boolean }

/** Read only the supplied history. Complete captures are still diagnostic values. */
export function reconstructCaptures(snapshot: StoreDebugSnapshot, selectedId: number): ReconstructedCaptures {
  if (snapshot.schemaVersion !== 2 || !snapshot.events.some(event => event.id === selectedId)) {
    return Object.freeze({ status: 'unavailable' })
  }
  let stores = snapshot.baseline.stores
  for (const event of snapshot.events) {
    stores = foldStoreEvent(stores, event)
    if (event.id === selectedId) break
  }
  const detached: StoreCaptures = Object.freeze(Object.fromEntries(Object.entries(stores).map(([id, entry]) => [id,
    Object.freeze({ ...entry, capture: Object.freeze({ ...entry.capture, value: detachedValue(entry.capture.value) }) }),
  ])))
  return Object.freeze({ status: 'available', stores: detached,
    partial: snapshot.omittedRegistrations > 0 || Object.values(detached).some(entry => !entry.capture.complete) })
}
