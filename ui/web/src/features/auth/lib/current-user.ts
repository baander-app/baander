import type { GetAuthMe200Data } from '@/shared/api-client/gen/endpoints'
import { useAuthStore } from '../stores/auth-store'

/**
 * Copies the server's profile of the signed-in user into the auth store.
 *
 * A profile for another account is ignored, such as a response that arrives after a sign-out
 * and a sign-in as someone else. So is the narrower profile a delegated token reads, which the
 * web app's first-party session never receives.
 */
export function applyCurrentUser(resource: GetAuthMe200Data): void {
  const { user, updateUser } = useAuthStore.getState()
  if (user === null || user.publicId !== resource.publicId || !('roles' in resource)) return

  updateUser({
    email: resource.email,
    name: resource.name,
    emailVerifiedAt: resource.emailVerifiedAt ?? null,
    totpEnabled: resource.totpEnabled,
    pendingEmail: resource.pendingEmail ?? null,
  })
}
