import type { PersistStorage, StorageValue } from 'zustand/middleware'
import { shallow } from 'zustand/shallow'

/** Persist immutable projections only when their fields change, before serialization. */
export function createSelectiveJSONStorage<S>(
  getStorage: () => Pick<Storage, 'getItem' | 'setItem' | 'removeItem'> = () => localStorage,
): PersistStorage<S> | undefined {
  let storage: Pick<Storage, 'getItem' | 'setItem' | 'removeItem'>
  try { storage = getStorage() } catch { return undefined }
  const previous = new Map<string, StorageValue<S>>()
  return {
    getItem(name) {
      const decode = (value: string | null): StorageValue<S> | null => {
        if (value === null) { previous.delete(name); return null }
        const parsed = JSON.parse(value) as StorageValue<S>
        previous.set(name, parsed)
        return parsed
      }
      return decode(storage.getItem(name))
    },
    setItem(name, value) {
      const last = previous.get(name)
      if (last && last.version === value.version && shallow(last.state, value.state)) return
      storage.setItem(name, JSON.stringify(value))
      previous.set(name, value)
    },
    removeItem(name) {
      storage.removeItem(name)
      previous.delete(name)
    },
  }
}
