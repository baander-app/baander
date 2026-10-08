/*
 * Client-side hints that mirror the server's redirect URI rules; the server stays
 * authoritative and answers 422 `invalid_registration` for anything it refuses.
 */

export const MAX_REDIRECT_URIS = 10

const MAX_REDIRECT_URI_LENGTH = 2000

const LOOPBACK_HOSTS = new Set(['localhost', '127.0.0.1', '[::1]'])

/** One URI per line; blank lines are ignored. */
export function parseRedirectUris(text: string): string[] {
  return text
    .split('\n')
    .map((line) => line.trim())
    .filter((line) => line !== '')
}

/** Why the server would refuse this redirect URI, or null when it looks acceptable. */
export function redirectUriProblem(uri: string): string | null {
  if (uri.length > MAX_REDIRECT_URI_LENGTH) return `Use at most ${MAX_REDIRECT_URI_LENGTH} characters: ${uri.slice(0, 40)}…`

  let url: URL
  try {
    url = new URL(uri)
  } catch {
    return `Not an absolute URI: ${uri}`
  }

  if (uri.includes('#')) return `Remove the fragment: ${uri}`
  if (url.username !== '' || url.password !== '') return `Remove the credentials: ${uri}`

  const scheme = url.protocol.slice(0, -1)
  if (scheme === 'https') return null
  if (scheme === 'http') {
    return LOOPBACK_HOSTS.has(url.hostname) ? null : `Use https, or http only on a loopback host: ${uri}`
  }

  // A native app's private-use scheme must be in reverse domain form (RFC 8252 section 7.1).
  return scheme.includes('.')
    ? null
    : `Use https, or a private-use scheme in reverse domain form such as app.baander.tv: ${uri}`
}

/** The first problem with a list of redirect URIs, or null when all look acceptable. */
export function redirectUrisProblem(uris: string[]): string | null {
  if (uris.length === 0) return 'Add at least one redirect URI.'
  if (uris.length > MAX_REDIRECT_URIS) return `Use at most ${MAX_REDIRECT_URIS} redirect URIs.`

  for (const uri of uris) {
    const problem = redirectUriProblem(uri)
    if (problem !== null) return problem
  }

  return null
}
