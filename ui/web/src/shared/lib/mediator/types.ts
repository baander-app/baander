/**
 * Core types for the cross-context action mediator.
 */

/** A function that handles a dispatched action. */
export type ActionHandler<P = unknown> = (payload: P) => void

/** A listener that receives every dispatched action (for dev tools, logging). */
export type ActionListener = (entry: ActionLogEntry) => void

/** A single entry in the action log. */
export interface ActionLogEntry {
  /** Monotonically increasing ID. */
  id: number
  /** ISO timestamp of dispatch. */
  timestamp: string
  /** The action type. */
  type: string
  /** The context that dispatched the action (e.g., 'radio', 'session'). */
  source: string
  /** The action payload. */
  payload: unknown
  /** Number of handlers that executed. */
  handlerCount: number
  /** Any errors from handlers. */
  errors: ActionHandlerError[]
}

/** An error captured from a handler during dispatch. */
export interface ActionHandlerError {
  /** Handler name or index. */
  handler: string
  /** Error message. */
  message: string
}

/** Options for the ActionBus constructor. */
export interface ActionBusOptions {
  /** Maximum action log entries before oldest-first eviction. Default: 500. */
  maxLogSize?: number
  /** Maximum recursion depth for nested dispatches. Default: 5. */
  maxRecursionDepth?: number
  /** Whether to warn on dispatches with no registered handlers. Default: true. */
  warnOnNoHandlers?: boolean
}
