import type { User } from './auth-store'

interface SignedInState {
  isAuthenticated: boolean
  user: User | null
}

/**
 * Whether the signed-in account has yet to verify its email address. The server confines such an
 * account to its profile and the routes that confirm the address, so the app shows it the
 * verification gate and runs no live updates or preference sync for it. An address whose state is
 * not known yet, as after a mock login, does not hold the account at the gate.
 */
export function selectAwaitsVerification(state: SignedInState): boolean {
  return state.isAuthenticated && state.user?.emailVerifiedAt === null
}
