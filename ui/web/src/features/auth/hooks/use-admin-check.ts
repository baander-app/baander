import { useAuthStore } from '../stores/auth-store'

export function useAdminCheck() {
  const user = useAuthStore((s) => s.user)
  const roles = user?.roles ?? []
  const isAdmin = roles.includes('ROLE_ADMIN') || roles.includes('ROLE_SUPER_ADMIN')
  const isSuperAdmin = roles.includes('ROLE_SUPER_ADMIN')

  return { isAdmin, isSuperAdmin, roles }
}
