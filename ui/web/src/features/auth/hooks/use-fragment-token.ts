import { useEffect, useState } from 'react'
import { useLocation, useNavigate } from 'react-router-dom'
import { readFragmentToken } from '../lib/fragment-token'

/**
 * Takes the token from an emailed link's URL fragment, keeps it in memory, and removes the
 * fragment from the address bar and the history entry.
 */
export function useFragmentToken(): string | null {
  const location = useLocation()
  const navigate = useNavigate()
  const [token] = useState(() => readFragmentToken(location.hash))

  useEffect(() => {
    if (location.hash === '') return

    navigate({ pathname: location.pathname, search: location.search }, { replace: true })
  }, [location.hash, location.pathname, location.search, navigate])

  return token
}
