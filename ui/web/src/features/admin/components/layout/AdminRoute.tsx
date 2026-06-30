import { Navigate, Outlet } from 'react-router-dom'
import { useAuthStore } from '@/features/auth/stores/auth-store'

export function AdminRoute() {
  const user = useAuthStore((s) => s.user)
  const roles = user?.roles ?? []
  const isAdmin = roles.includes('ROLE_ADMIN') || roles.includes('ROLE_SUPER_ADMIN')

  if (!isAdmin) {
    return <Navigate to="/" replace />
  }

  return <Outlet />
}
