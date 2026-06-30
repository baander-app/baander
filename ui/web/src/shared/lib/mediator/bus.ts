import type { ActionHandler, ActionListener, ActionLogEntry, ActionBusOptions, ActionHandlerError } from './types'

type HandlerEntry = {
  handler: ActionHandler
  name: string
}

/**
 * Action bus for cross-context coordination.
 *
 * - Stores register handlers for action types they care about.
 * - Any context can dispatch actions through the bus.
 * - All dispatched actions are logged for debugging.
 * - Global subscribers receive every dispatched action.
 *
 * The bus is untyped at the class level — each context owns its
 * action types and provides typed constants. Type safety comes
 * from the per-context action definitions, not from a central map.
 */
export class ActionBus {
  private handlers = new Map<string, HandlerEntry[]>()
  private listeners: ActionListener[] = []
  private log: ActionLogEntry[] = []
  private nextId = 1
  private recursionDepth = 0
  private readonly maxLogSize: number
  private readonly maxRecursionDepth: number
  private readonly warnOnNoHandlers: boolean

  constructor(options: ActionBusOptions = {}) {
    this.maxLogSize = options.maxLogSize ?? 500
    this.maxRecursionDepth = options.maxRecursionDepth ?? 5
    this.warnOnNoHandlers = options.warnOnNoHandlers ?? true
  }

  /**
   * Register a handler for an action type.
   * Returns an unsubscribe function.
   */
  on(
    action: string,
    handler: ActionHandler,
  ): () => void {
    const entries = this.handlers.get(action) ?? []
    const name = handler.name || '<anonymous>'
    entries.push({ handler, name })
    this.handlers.set(action, entries)

    return () => {
      const current = this.handlers.get(action) ?? []
      const idx = current.findIndex((e) => e.handler === handler)
      if (idx >= 0) current.splice(idx, 1)
    }
  }

  /**
   * Dispatch an action. All registered handlers run synchronously.
   * The action is logged.
   */
  dispatch(
    action: string,
    payload: unknown,
    source: string,
  ): void {
    this.recursionDepth++
    if (this.recursionDepth > this.maxRecursionDepth) {
      console.warn(
        `[Mediator] Max recursion depth (${this.maxRecursionDepth}) exceeded for action "${action}". Skipping dispatch.`,
      )
      this.recursionDepth--
      return
    }

    const entries = this.handlers.get(action) ?? []

    if (entries.length === 0 && this.warnOnNoHandlers) {
      console.warn(
        `[Mediator] No handlers registered for action "${action}" (dispatched by "${source}")`,
      )
    }

    const errors: ActionHandlerError[] = []

    for (const entry of entries) {
      try {
        entry.handler(payload)
      } catch (err) {
        errors.push({
          handler: entry.name,
          message: err instanceof Error ? err.message : String(err),
        })
      }
    }

    const logEntry: ActionLogEntry = {
      id: this.nextId++,
      timestamp: new Date().toISOString(),
      type: action,
      source,
      payload,
      handlerCount: entries.length,
      errors,
    }

    this.log.push(logEntry)

    // Evict oldest if over max
    if (this.log.length > this.maxLogSize) {
      this.log.splice(0, this.log.length - this.maxLogSize)
    }

    // Notify global subscribers
    for (const listener of this.listeners) {
      try {
        listener(logEntry)
      } catch {
        // Subscriber errors are silently ignored to avoid cascading failures
      }
    }

    this.recursionDepth--
  }

  /**
   * Subscribe to all dispatched actions.
   * Returns an unsubscribe function.
   */
  subscribe(listener: ActionListener): () => void {
    this.listeners.push(listener)
    return () => {
      const idx = this.listeners.indexOf(listener)
      if (idx >= 0) this.listeners.splice(idx, 1)
    }
  }

  /**
   * Get the chronological action log.
   */
  getActionLog(): ActionLogEntry[] {
    return [...this.log]
  }

  /**
   * Get a map of action types to their registered handler names.
   */
  getHandlerMap(): Record<string, string[]> {
    const map: Record<string, string[]> = {}
    for (const [action, entries] of this.handlers) {
      map[action] = entries.map((e) => e.name)
    }
    return map
  }

  /**
   * Clear the action log.
   */
  clearLog(): void {
    this.log = []
  }
}

/**
 * Singleton mediator instance.
 * Import this to dispatch actions or register handlers.
 */
export const mediator = new ActionBus()
