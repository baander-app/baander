/**
 * Null logger stub — drops all output.
 * Replaces electron-log which is incompatible with Vite 8 / Rolldown ESM bundling.
 *
 * API matches the subset used in the codebase:
 *   mainLog.error(msg, ...args)
 *   mainLog.log(msg, ...args)
 *   mainLog.warn(msg, ...args)
 *   mainLog.info(msg, ...args)
 *   mainLog.debug(msg, ...args)
 *   mainLog.errorHandler.startCatching()
 */

const noop = (..._args: unknown[]) => {};

export const mainLog = {
  log: noop,
  warn: noop,
  error: noop,
  info: noop,
  debug: noop,
  silly: noop,
  verbose: noop,
  errorHandler: { startCatching: noop },
  scope: () => mainLog,
} as const;
