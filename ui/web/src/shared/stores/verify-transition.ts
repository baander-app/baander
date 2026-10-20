import { captureState } from './debug'
import type { DebugValue, StateCapture, StoreDebugSnapshot, StoreTransitionDescriptor } from './debug'

interface VerificationIdentity {
  actionId: number
  instanceId?: number
  epoch: number
}
export type StoreTransitionVerification = VerificationIdentity & (
  | { status: 'match' | 'mismatch'; expected: DebugValue; recorded: DebugValue }
  | { status: 'unavailable'; reason: string }
)

function equalValues(left: DebugValue, right: DebugValue): boolean {
  if (left === right) return true
  if (left === null || right === null || typeof left !== 'object' || typeof right !== 'object') return false
  if (Array.isArray(left) !== Array.isArray(right)) return false
  const leftKeys = Object.keys(left)
  const rightKeys = Object.keys(right)
  return leftKeys.length === rightKeys.length && leftKeys.every(key => Object.hasOwn(right, key)
    && equalValues((left as Record<string, DebugValue>)[key], (right as Record<string, DebugValue>)[key]))
}

function detach(capture: StateCapture | undefined): StateCapture | undefined {
  if (!capture?.complete) return undefined
  const detached = captureState(capture.value)
  return detached.complete ? detached : undefined
}

/** Compare one retained invocation using only the recorder's current trusted implementation. */
export function verifyTransition(
  snapshot: StoreDebugSnapshot,
  actionId: number,
  lookup: (instanceId: number) => StoreTransitionDescriptor | undefined,
): StoreTransitionVerification {
  const identity: VerificationIdentity = { actionId, epoch: snapshot.baseline.epoch }
  const unavailable = (reason: string): StoreTransitionVerification => Object.freeze({ ...identity, status: 'unavailable', reason })
  if (snapshot.schemaVersion !== 2) return unavailable('Unsupported trace schema.')
  const matching = snapshot.events.filter(event => event.id === actionId)
  if (matching.length !== 1) return unavailable('The selected action is no longer uniquely retained.')
  const action = matching[0]
  identity.instanceId = action.instanceId
  if (action.kind !== 'action') return unavailable('Select an action event to verify.')
  if (action.schemaVersion !== 2) return unavailable('Unsupported action schema.')
  if (action.parentId !== null) return unavailable('A nested action cannot be verified independently.')
  if (!action.transition) return unavailable('This action has no trusted transition metadata.')

  try {
    const descriptor = lookup(action.instanceId)
    if (!descriptor || descriptor.id !== action.transition.id) return unavailable('The current transition descriptor is unavailable or differs.')
    if (descriptor.version !== action.transition.version) return unavailable('The transition version differs from the recorded version.')
    if (!descriptor.actions.includes(action.action)) return unavailable('This action is not supported by the current transition.')
    // Calling the detached functions prevents transition code from receiving the descriptor as this.
    const { validateState, validateArgs, calculate } = descriptor
    const before = detach(action.before)
    const args = detach(action.args)
    if (!before || !args) return unavailable('The action requires complete before and arguments captures.')
    if (!validateState(before.value)) return unavailable('The captured starting state is invalid.')
    if (!validateArgs(action.action, args.value)) return unavailable('The captured action arguments are invalid.')

    const terminals = snapshot.events.filter(event => event.parentId === actionId
      && (event.kind === 'resolved' || event.kind === 'rejected'))
    if (terminals.length !== 1) return unavailable('The invocation requires exactly one retained terminal event.')
    const terminal = terminals[0]
    const start = snapshot.events.indexOf(action)
    const end = snapshot.events.indexOf(terminal)
    if (end <= start || terminal.instanceId !== action.instanceId || terminal.action !== action.action
      || terminal.schemaVersion !== 2) return unavailable('The terminal event does not match the invocation boundaries.')
    if (snapshot.events.some((event, index) => event.parentId === actionId
      && (index <= start || index > end))) return unavailable('Invocation activity falls outside its terminal boundaries.')
    if (terminal.kind === 'rejected') return unavailable('The recorded action was rejected.')
    if (terminal.completion !== 'synchronous') return unavailable('The recorded action has no synchronous completion provenance.')
    const recorded = detach(terminal.after)
    if (!recorded) return unavailable('The terminal result requires a complete capture.')
    if (!validateState(recorded.value)) return unavailable('The captured terminal state is invalid.')

    let current = before.value
    for (const event of snapshot.events.slice(start + 1, end)) {
      if (event.kind === 'action') return unavailable('The invocation contains a nested action.')
      if (event.kind === 'resolved' || event.kind === 'rejected') return unavailable('The invocation contains contradictory terminal boundaries.')
      if (event.kind !== 'update') continue
      if (event.schemaVersion !== 2 || event.instanceId !== action.instanceId || event.parentId !== actionId) {
        return unavailable('The invocation contains a foreign or unattributed update.')
      }
      const updateBefore = detach(event.before)
      const updateAfter = detach(event.after)
      if (!updateBefore || !updateAfter) return unavailable('Every update requires complete captures.')
      if (!validateState(updateBefore.value) || !validateState(updateAfter.value)) return unavailable('An update contains invalid state.')
      if (!equalValues(current, updateBefore.value)) return unavailable('The recorded update chain has a broken starting boundary.')
      current = updateAfter.value
    }
    if (!equalValues(current, recorded.value)) return unavailable('The recorded update chain does not reach the terminal result.')

    const expected = captureState(calculate(before.value, action.action, args.value))
    if (!expected.complete) return unavailable('The calculated result exceeds complete capture limits.')
    if (!validateState(expected.value)) return unavailable('The calculated result has invalid state.')
    return Object.freeze({ ...identity, status: equalValues(expected.value, recorded.value) ? 'match' : 'mismatch',
      expected: expected.value, recorded: recorded.value })
  } catch {
    return unavailable('The transition validation or calculation failed.')
  }
}
