import { useEffect, useState, useSyncExternalStore, type ReactNode } from 'react'
import { StatusDot } from '@/shared/components/status-dot'
import { QueryClientProvider } from '@tanstack/react-query'
import { useAuthStore } from '@/features/auth/stores/auth-store'
import { useNotificationStore } from '@/features/notification/stores/notification-store'
import { useSidebarStore } from '@/features/layout/stores/sidebar-store'
import { useEqProfilesStore } from '@/features/equalizer/stores/eq-profiles-store'
import { OwnedQueryClient } from './query-client'
import { LiveRecovery } from './recovery'
import { LiveContext, useLiveRecovery } from './context'
import { authenticatedTransport } from './transport'

function resetMirrors(): void {
  useNotificationStore.getState().resetForPrincipal()
  useSidebarStore.getState().resetForPrincipal()
  useEqProfilesStore.getState().resetForPrincipal()
}

export function LiveConnectionStatus() {
  const recovery = useLiveRecovery()
  if (!recovery) return null
  return <ConnectionStatus recovery={recovery} />
}

function ConnectionStatus({ recovery }: { recovery: LiveRecovery }) {
  const snapshot = useSyncExternalStore(recovery.subscribe, recovery.getSnapshot)
  // Until resource freshness is activated, a healthy authority handshake is sufficient.
  if (snapshot.authorityReady && snapshot.health.state === 'current') return null
  return (
    <span role="status">
      <StatusDot
        color={snapshot.status === 'degraded' ? 'amber' : 'gray'}
        label={snapshot.status === 'connecting' ? 'Connecting…' : snapshot.status === 'degraded' ? 'Updates unavailable' : 'Reconnecting…'}
      />
    </span>
  )
}

/** The key replaces observers as well as cache ownership when a principal session changes. */
export function AuthenticatedClientProvider({ children }: { children: ReactNode }) {
  const principal = useAuthStore(state => state.principalSessionId)
  return <PrincipalClient key={principal ?? 'anonymous'} principal={principal}>{children}</PrincipalClient>
}

function PrincipalClient({ principal, children }: { principal: string | null; children: ReactNode }) {
  const [client] = useState(() => new OwnedQueryClient())
  const [recovery] = useState(() => new LiveRecovery(authenticatedTransport, resetMirrors))
  useEffect(() => {
    resetMirrors()
    const detach = client.attach()
    const stop = principal ? recovery.start() : () => undefined
    return () => {
      stop()
      detach()
      client.dispose()
      resetMirrors()
    }
  }, [client, principal, recovery])
  return (
    <QueryClientProvider client={client}>
      <LiveContext.Provider value={principal ? recovery : null}>{children}</LiveContext.Provider>
    </QueryClientProvider>
  )
}
