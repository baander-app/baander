import { useEffect, useState, type ReactNode } from 'react'
import { QueryClientProvider } from '@tanstack/react-query'
import { useAuthStore } from '@/features/auth/stores/auth-store'
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
  principal: string | null
  children: ReactNode
  freshnessEnabled: boolean
}

export function AuthenticatedClientProvider({ children, freshnessEnabled = false }: ClientProviderProps) {
  const principal = useAuthStore(state => state.principalSessionId)
  return (
    <PrincipalClient key={principal ?? 'anonymous'} principal={principal} freshnessEnabled={freshnessEnabled}>
      {children}
    </PrincipalClient>
  )
}

function PrincipalClient({ principal, children, freshnessEnabled }: PrincipalClientProps) {
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
    const stopCoordinator = principal && freshnessEnabled ? coordinator.start() : () => undefined
    const stop = principal ? recovery.start() : () => undefined
    const stopOutageWatcher = principal ? startLiveOutageWatcher(recovery, freshnessEnabled) : () => undefined
    const detachActivity = principal
      ? activityService.attachRefresh(client, freshnessEnabled ? coordinator : undefined)
      : () => undefined
    return () => {
      detachActivity()
      stopOutageWatcher()
      stop()
      stopCoordinator()
      detach()
      client.dispose()
      resetMirrors()
    }
  }, [client, coordinator, freshnessEnabled, principal, recovery])
  return (
    <QueryClientProvider client={client}>
      <LiveCoordinatorContext.Provider value={principal && freshnessEnabled ? coordinator : null}>
        {children}
      </LiveCoordinatorContext.Provider>
    </QueryClientProvider>
  )
}
