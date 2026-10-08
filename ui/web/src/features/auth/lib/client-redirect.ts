/** Schemes that would run or embed content instead of returning to an OAuth client. */
const BLOCKED_PROTOCOLS = new Set(['javascript:', 'data:', 'vbscript:', 'blob:', 'file:', 'about:'])

function parseAbsolute(value: string): URL | null {
  try {
    return new URL(value)
  } catch {
    return null
  }
}

/**
 * Checks a redirect URI the authorization endpoint returned before the browser leaves for it.
 * The server has already validated the client's URI; this is a second line of defence so the
 * page never follows a script URL or a target other than the one the client asked for.
 *
 * Native apps register custom schemes (`app.baander.tv:/callback`), so any other scheme is
 * allowed. When the request named a redirect URI, the answer must keep its scheme, host,
 * port, and path; only the query (code, state, iss, or error) may differ.
 *
 * @returns the URL to navigate to, or null when it must not be followed
 */
export function checkedClientRedirect(candidate: string, requested: string | null): string | null {
  const target = parseAbsolute(candidate)
  if (target === null || BLOCKED_PROTOCOLS.has(target.protocol)) return null
  if (requested === null) return target.href

  const expected = parseAbsolute(requested)
  if (expected === null) return null

  const sameTarget = target.protocol === expected.protocol
    && target.host === expected.host
    && target.pathname === expected.pathname

  return sameTarget ? target.href : null
}
