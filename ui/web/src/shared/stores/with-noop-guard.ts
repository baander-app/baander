import type { StateCreator, StoreMutatorIdentifier } from 'zustand'

/** Place inside persist so unchanged patches never invoke serialization or storage. */
export function withNoopGuard<T extends object, Mps extends [StoreMutatorIdentifier, unknown][] = [], Mcs extends [StoreMutatorIdentifier, unknown][] = []>(
  creator: StateCreator<T, Mps, Mcs>,
): StateCreator<T, Mps, Mcs> {
  return ((set, get, api) => {
    const wrap = (original: typeof set): typeof set => ((...args: unknown[]) => {
      const state = get()
      const patch = typeof args[0] === 'function' ? args[0](state) : args[0]
      if (Object.is(patch, state)) return
      if (state && patch && typeof patch === 'object') {
        const current = state as Record<string, unknown>
        const proposed = patch as Record<string, unknown>
        const keys = Object.keys(proposed)
        const sameKeys = !args[1] || keys.length === Object.keys(current).length
        if (sameKeys && keys.every(key => Object.hasOwn(current, key) && Object.is(current[key], proposed[key]))) return
      }
      return Reflect.apply(original, undefined, [patch, ...args.slice(1)])
    }) as typeof set
    const state = creator(wrap(set), get, api)
    api.setState = wrap(api.setState as typeof set) as typeof api.setState
    return state
  }) as StateCreator<T, Mps, Mcs>
}
