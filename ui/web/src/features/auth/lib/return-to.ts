/** The router state the sign-in guard passes to the login page. */
export interface ReturnToState {
  from: {
    pathname: string
    search: string
    hash: string
  }
}

interface LocationLike {
  pathname: string
  search: string
  hash: string
}

const FALLBACK = '/'

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null
}

/** Remembers where a signed-out visitor was going, query and fragment included. */
export function returnToState(location: LocationLike): ReturnToState {
  return {
    from: {
      pathname: location.pathname,
      search: location.search,
      hash: location.hash,
    },
  }
}

/**
 * The in-app path to open after signing in, or `/` when the state names none. Only a path on
 * this origin is accepted, so the state can never send the user to another site.
 */
export function returnPathFrom(state: unknown): string {
  if (!isRecord(state) || !isRecord(state.from)) return FALLBACK

  const { pathname, search, hash } = state.from
  if (typeof pathname !== 'string' || typeof search !== 'string' || typeof hash !== 'string') {
    return FALLBACK
  }

  // A path must start with exactly one slash; `//host` and `/\host` are protocol-relative URLs.
  if (!pathname.startsWith('/') || pathname.startsWith('//') || pathname.startsWith('/\\')) {
    return FALLBACK
  }

  if (pathname === '/login') return FALLBACK

  return pathname + search + hash
}
