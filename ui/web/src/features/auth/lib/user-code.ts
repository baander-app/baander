/** A device user code has eight characters, shown as two groups of four: `BCDF-GHJK`. */
export const USER_CODE_LENGTH = 8

const GROUP_LENGTH = 4

/**
 * Reduces what the user typed or pasted to the code's characters: case, spaces, and dashes
 * do not matter, as on the server.
 */
export function normalizeUserCode(input: string): string {
  return input
    .toUpperCase()
    .replace(/[^A-Z0-9]/g, '')
    .slice(0, USER_CODE_LENGTH)
}

/** Formats a normalized code as `XXXX-XXXX`, adding the dash once the first group is full. */
export function formatUserCode(normalized: string): string {
  if (normalized.length <= GROUP_LENGTH) return normalized

  return `${normalized.slice(0, GROUP_LENGTH)}-${normalized.slice(GROUP_LENGTH)}`
}

export function isCompleteUserCode(normalized: string): boolean {
  return normalized.length === USER_CODE_LENGTH
}
