/* simple logger shim */
export const log = (...args: unknown[]) => console.log('[desktop]', ...args);
export const warn = (...args: unknown[]) => console.warn('[desktop]', ...args);
export const error = (...args: unknown[]) => console.error('[desktop]', ...args);
