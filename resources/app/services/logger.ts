/**
<<<<<<< HEAD
 * Simple logging service for debugging audio processing
=======
 * Simple logging service
>>>>>>> private/master
 * Set ENABLED to false to disable all logs
 */
const ENABLED = false;

const logLevels = {
  debug: 0,
  info: 1,
  warn: 2,
  error: 3,
} as const;

type LogLevel = keyof typeof logLevels;

let currentLevel: LogLevel = 'debug';

/**
 * Set the minimum log level
 */
export function setLogLevel(level: LogLevel) {
  currentLevel = level;
}

/**
 * Enable or disable logging
 */
export function setLoggingEnabled(enabled: boolean) {
<<<<<<< HEAD
  (globalThis as any).__AUDIO_LOGGING_ENABLED__ = enabled;
=======
  (globalThis as any).__APPLICATION_LOGGING_ENABLED__ = enabled;
>>>>>>> private/master
}

/**
 * Check if logging is enabled
 */
function isLoggingEnabled(): boolean {
<<<<<<< HEAD
  return (globalThis as any).__AUDIO_LOGGING_ENABLED__ ?? ENABLED;
=======
  return (globalThis as any).__APPLICATION_LOGGING_ENABLED__ ?? ENABLED;
>>>>>>> private/master
}

/**
 * Check if a log level should be output
 */
function shouldLog(level: LogLevel): boolean {
  return isLoggingEnabled() && logLevels[level] >= logLevels[currentLevel];
}

/**
 * Format log prefix with context
 */
function formatPrefix(context: string): string {
  return `[${context}]`;
}

/**
 * Logger class for specific contexts
 */
export class Logger {
  constructor(private context: string) {}

  debug(...args: unknown[]) {
    if (shouldLog('debug')) {
      console.log(formatPrefix(this.context), ...args);
    }
  }

  info(...args: unknown[]) {
    if (shouldLog('info')) {
      console.info(formatPrefix(this.context), ...args);
    }
  }

  warn(...args: unknown[]) {
    if (shouldLog('warn')) {
      console.warn(formatPrefix(this.context), ...args);
    }
  }

  error(...args: unknown[]) {
    if (shouldLog('error')) {
      console.error(formatPrefix(this.context), ...args);
    }
  }

  /**
   * Log at most once per specified interval (throttled logging)
   */
  throttle(intervalMs: number) {
    const lastLogTime = { value: 0 };
    return (level: LogLevel = 'info') => {
      return (...args: unknown[]) => {
        const now = performance.now();
        if (now - lastLogTime.value >= intervalMs) {
          lastLogTime.value = now;
          if (shouldLog(level)) {
            const logFn = level === 'error' ? console.error :
                          level === 'warn' ? console.warn :
                          level === 'info' ? console.info :
                          console.log;
            logFn(formatPrefix(this.context), ...args);
          }
        }
      };
    };
  }
}

/**
 * Create a logger for a specific context
 */
export function createLogger(context: string): Logger {
  return new Logger(context);
}
