import type { UserResource } from '@/shared/api-client/gen/endpoints'
import { useAuthStore } from '../stores/auth-store'

/**
 * Copies the server's profile of the signed-in user into the auth store.
 *
 * A profile for another account is ignored, such as a response that arrives after a sign-out
 * and a sign-in as someone else.
 */
export function applyCurrentUser(resource: UserResource): void {
  const { user, updateUser } = useAuthStore.getState()
  if (user === null || user.uuid !== resource.uuid) return

  updateUser({
    email: resource.email,
    name: resource.name,
    emailVerifiedAt: resource.emailVerifiedAt ?? null,
  })
}
