import { Navigate, Outlet } from 'react-router-dom'
import { useAuthStore } from '../stores/auth-store'

export function ProtectedRoute() {
  const isAuthenticated = useAuthStore((s) => s.isAuthenticated)

  if (!isAuthenticated) {
    return <Navigate to="/login" replace />
  }

  return <Outlet />
}
