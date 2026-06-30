import type { ActionLogEntry } from './types'
import { mediator } from './bus'

interface LogFilter {
  /** Filter by action type prefix (e.g., 'player:') */
  typePrefix?: string
  /** Filter by source context (e.g., 'radio') */
  source?: string
}

/**
 * Filter an action log by type prefix and/or source context.
 */
export function filterActionLog(
  log: ActionLogEntry[],
  filter: LogFilter,
): ActionLogEntry[] {
  return log.filter((entry) => {
    if (filter.typePrefix && !entry.type.startsWith(filter.typePrefix)) {
      return false
    }

    return !(filter.source && entry.source !== filter.source);
  })
}

/**
 * Get the singleton mediator's action log.
 */
export function getActionLog(): ActionLogEntry[] {
  return mediator.getActionLog()
}

/**
 * Get the singleton mediator's handler map.
 */
export function inspectHandlers(): Record<string, string[]> {
  return mediator.getHandlerMap()
}

/**
 * Clear the singleton mediator's action log.
 */
export function clearLog(): void {
  mediator.clearLog()
}

// Expose on window in dev mode for console access
if (typeof window !== 'undefined' && import.meta.env.DEV) {
  ;(window as typeof window & { __MEDIATOR__?: unknown }).__MEDIATOR__ = {
    getActionLog,
    inspectHandlers,
    clearLog,
    filterActionLog,
  }
}
