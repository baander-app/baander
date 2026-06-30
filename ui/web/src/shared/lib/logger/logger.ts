/** Log levels matching native console methods. */
export type LogLevel = 'trace' | 'debug' | 'info' | 'warn' | 'error'

/**
 * A scoped logger instance. Each method delegates to the
 * corresponding console method with a `[scope]` prefix.
 */
export type Logger = {
  readonly trace: (...args: unknown[]) => void
  readonly debug: (...args: unknown[]) => void
  readonly info: (...args: unknown[]) => void
  readonly warn: (...args: unknown[]) => void
  readonly error: (...args: unknown[]) => void
}

const METHODS: Record<LogLevel, (...args: unknown[]) => void> = {
  trace: console.trace,
  debug: console.debug,
  info: console.info,
  warn: console.warn,
  error: console.error,
}

/**
 * Create a scoped logger that prefixes every message with `[scope]`.
 *
 * @example
 * const logger = createLogger('AudioService')
 * logger.error('Failed to init:', error)
 * // → console.error('[AudioService] Failed to init:', error)
 */
export function createLogger(scope: string): Logger {
  const prefix = `[${scope}]`

  const logger: Record<LogLevel, (...args: unknown[]) => void> = {
    trace: METHODS.trace.bind(console, prefix),
    debug: METHODS.debug.bind(console, prefix),
    info: METHODS.info.bind(console, prefix),
    warn: METHODS.warn.bind(console, prefix),
    error: METHODS.error.bind(console, prefix),
  }

  return Object.freeze(logger) as Logger
}
