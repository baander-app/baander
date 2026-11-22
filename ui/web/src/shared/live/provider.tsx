import { useEffect, useState, type ReactNode } from 'react'
import { QueryClientProvider } from '@tanstack/react-query'
import { useAuthStore } from '@/features/auth/stores/auth-store'
import { selectAwaitsVerification } from '@/features/auth/stores/auth-selectors'
import { useNotificationStore } from '@/features/notification/stores/notification-store'
import { useSidebarStore } from '@/features/layout/stores/sidebar-store'
import { useEqProfilesStore } from '@/features/equalizer/stores/eq-profiles-store'
import { usePaletteStore } from '@/features/visualizer/stores/palette-store'
import { activityService } from '@/features/player/services/activity-service'
import { OwnedQueryClient } from './query-client'
import { LiveRecovery } from './recovery'
import { startLiveOutageWatcher } from './outage-watcher'
import { LIVE_QUERY_DESCRIPTORS } from './descriptors'
import { LiveQueryCoordinator } from './query-coordinator'
import { LiveCoordinatorContext } from './context'
import { authenticatedTransport } from './transport'

function resetMirrors(): void {
  usePaletteStore.getState().clearAll()
  useNotificationStore.getState().resetForPrincipal()
  useSidebarStore.getState().resetForPrincipal()
  useEqProfilesStore.getState().resetForPrincipal()
}

interface ClientProviderProps {
  children: ReactNode
  freshnessEnabled?: boolean
}

interface PrincipalClientProps {
  /** Whether the client runs live recovery, its coordinator and the activity refresh. */
  live: boolean
  children: ReactNode
  freshnessEnabled: boolean
}

/**
 * An account that has yet to verify its address is refused live recovery and WebSocket tickets, so
 * its client starts no live work, as for a signed-out tab. Verifying the address starts that work
 * on the same client: the pages stay mounted, so the verification page keeps its outcome.
 */
export function AuthenticatedClientProvider({ children, freshnessEnabled = false }: ClientProviderProps) {
  const principal = useAuthStore(state => state.principalSessionId)
  const awaitsVerification = useAuthStore(selectAwaitsVerification)
  return (
    <PrincipalClient key={principal ?? 'anonymous'} live={principal !== null && !awaitsVerification} freshnessEnabled={freshnessEnabled}>
      {children}
    </PrincipalClient>
  )
}

function PrincipalClient({ live, children, freshnessEnabled }: PrincipalClientProps) {
  const [{ client, recovery, coordinator }] = useState(() => {
    const client = new OwnedQueryClient()
    const recovery = new LiveRecovery(authenticatedTransport, resetMirrors, undefined, freshnessEnabled)
    const coordinator = new LiveQueryCoordinator(client, recovery, LIVE_QUERY_DESCRIPTORS)
    if (freshnessEnabled) client.setCoordinator(coordinator)
    return { client, recovery, coordinator }
  })
  useEffect(() => {
    resetMirrors()
    const detach = client.attach()
    return () => {
      detach()
      client.dispose()
      resetMirrors()
    }
  }, [client])
  useEffect(() => {
    if (!live) return undefined
    const stopCoordinator = freshnessEnabled ? coordinator.start() : () => undefined
    const stop = recovery.start()
    const stopOutageWatcher = startLiveOutageWatcher(recovery, freshnessEnabled)
    const detachActivity = activityService.attachRefresh(client, freshnessEnabled ? coordinator : undefined)
    return () => {
      detachActivity()
      stopOutageWatcher()
      stop()
      stopCoordinator()
    }
  }, [client, coordinator, freshnessEnabled, live, recovery])
  return (
    <QueryClientProvider client={client}>
      <LiveCoordinatorContext.Provider value={live && freshnessEnabled ? coordinator : null}>
        {children}
      </LiveCoordinatorContext.Provider>
    </QueryClientProvider>
  )
}
