import { Navigate, Outlet, useLocation } from 'react-router-dom'
import { returnToState } from '../lib/return-to'
import { selectAwaitsVerification } from '../stores/auth-selectors'
import { useAuthStore } from '../stores/auth-store'
import { VerificationGate } from './VerificationGate'

export function ProtectedRoute() {
  const isAuthenticated = useAuthStore((s) => s.isAuthenticated)
  const awaitsVerification = useAuthStore(selectAwaitsVerification)
  const location = useLocation()

  if (!isAuthenticated) {
    // Return here after signing in, so links such as /device?user_code= keep their query.
    return <Navigate to="/login" replace state={returnToState(location)} />
  }

  // The gate replaces the whole page, player and library navigation included; once the address
  // is verified, the page the user asked for opens.
  if (awaitsVerification) {
    return <VerificationGate />
  }

  return <Outlet />
}
