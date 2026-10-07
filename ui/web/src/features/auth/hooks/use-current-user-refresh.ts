import { useEffect } from 'react'
import { useGetAuthMe } from '@/shared/api-client/gen/endpoints'
import { applyCurrentUser } from '../lib/current-user'
import { useAuthStore } from '../stores/auth-store'

/**
 * Reads the signed-in user's profile from the server on mount and copies it into the auth
 * store, so account details changed elsewhere, such as an address verified on another device,
 * show up. Only a profile fetched after mount is applied: a cached one may be older than the
 * store.
 */
export function useCurrentUserRefresh(): void {
  const isAuthenticated = useAuthStore((s) => s.isAuthenticated)
  const me = useGetAuthMe({
    query: {
      enabled: isAuthenticated,
      staleTime: 0,
      refetchOnMount: 'always',
    },
  })
  const profile = me.isFetchedAfterMount ? me.data?.data : undefined

  useEffect(() => {
    if (profile) applyCurrentUser(profile)
  }, [profile])
}
