/*
 * Client-side hints that mirror the server's key source rules for private_key_jwt
 * clients; the server stays authoritative and answers 422 for anything it refuses.
 */

export type KeySourceKind = 'jwksUri' | 'jwks'

export const KEY_SOURCE_LABELS: Record<KeySourceKind, string> = {
  jwksUri: 'Key set URL',
  jwks: 'Inline key set',
}

export const KEY_SOURCE_KINDS = Object.keys(KEY_SOURCE_LABELS) as KeySourceKind[]

export function isKeySourceKind(value: string): value is KeySourceKind {
  return (KEY_SOURCE_KINDS as readonly string[]).includes(value)
}

/** The key source as the admin API takes it: exactly one of `jwks` and `jwksUri`. */
export type KeySourcePayload = { jwks: Record<string, unknown> } | { jwksUri: string }

export type KeySourceResult = { payload: KeySourcePayload; problem: null } | { payload: null; problem: string }

/** Why the server would refuse this jwks_uri, or null when it looks acceptable. */
export function jwksUriProblem(uri: string): string | null {
  let url: URL
  try {
    url = new URL(uri)
  } catch {
    return `Not an absolute URL: ${uri}`
  }

  if (url.protocol !== 'https:' || url.port !== '') return `Use an https URL on the default port: ${uri}`
  if (url.username !== '' || url.password !== '') return `Remove the credentials: ${uri}`

  return null
}

/** The JWK Set in the text, or null when it is not a JSON object with a `keys` list. */
function parseJwks(text: string): Record<string, unknown> | null {
  let parsed: unknown
  try {
    parsed = JSON.parse(text)
  } catch {
    return null
  }

  if (typeof parsed !== 'object' || parsed === null || Array.isArray(parsed)) return null
  const keySet = parsed as Record<string, unknown>

  return Array.isArray(keySet.keys) && keySet.keys.length > 0 ? keySet : null
}

/** The payload for the chosen key source, or the first problem with the entered value. */
export function keySourcePayload(kind: KeySourceKind, value: string): KeySourceResult {
  const trimmed = value.trim()
  if (kind === 'jwksUri') {
    if (trimmed === '') return { payload: null, problem: 'Enter the URL where the client publishes its keys.' }
    const problem = jwksUriProblem(trimmed)

    return problem === null ? { payload: { jwksUri: trimmed }, problem: null } : { payload: null, problem }
  }

  const jwks = parseJwks(trimmed)

  return jwks === null
    ? { payload: null, problem: 'The key set must be a JSON object with a "keys" list.' }
    : { payload: { jwks }, problem: null }
}
