import { Navigate, Outlet, useLocation } from 'react-router-dom'
import { returnToState } from '../lib/return-to'
import { useAuthStore } from '../stores/auth-store'

export function ProtectedRoute() {
  const isAuthenticated = useAuthStore((s) => s.isAuthenticated)
  const location = useLocation()

  if (!isAuthenticated) {
    // Return here after signing in, so links such as /device?user_code= keep their query.
    return <Navigate to="/login" replace state={returnToState(location)} />
  }

  return <Outlet />
}
