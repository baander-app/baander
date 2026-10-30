import { useEffect, useState, useSyncExternalStore, type ReactNode } from 'react'
import { StatusDot } from '@/shared/components/status-dot'
import { QueryClientProvider } from '@tanstack/react-query'
import { useAuthStore } from '@/features/auth/stores/auth-store'
import { useNotificationStore } from '@/features/notification/stores/notification-store'
import { useSidebarStore } from '@/features/layout/stores/sidebar-store'
import { useEqProfilesStore } from '@/features/equalizer/stores/eq-profiles-store'
import { usePaletteStore } from '@/features/visualizer/stores/palette-store'
import { activityService } from '@/features/player/services/activity-service'
import { OwnedQueryClient } from './query-client'
import { LiveRecovery } from './recovery'
import { LIVE_QUERY_DESCRIPTORS } from './descriptors'
import { LiveQueryCoordinator } from './query-coordinator'
import { LiveCoordinatorContext, LiveContext, useLiveCoordinator, useLiveRecovery } from './context'
import { authenticatedTransport } from './transport'

function resetMirrors(): void {
  usePaletteStore.getState().clearAll()
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
  const coordinator = useLiveCoordinator()
  const snapshot = useSyncExternalStore(recovery.subscribe, recovery.getSnapshot)
  if ((!coordinator && snapshot.authorityReady && snapshot.health.state === 'current') || snapshot.status === 'current') return null
  return (
    <span role="status">
      <StatusDot
        color={snapshot.status === 'degraded' ? 'amber' : 'gray'}
        label={snapshot.status === 'connecting' ? 'Connecting…' : snapshot.status === 'degraded' ? 'Updates unavailable' : 'Updating…'}
      />
    </span>
  )
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
    const detachActivity = principal
      ? activityService.attachRefresh(client, freshnessEnabled ? coordinator : undefined)
      : () => undefined
    return () => {
      detachActivity()
      stop()
      stopCoordinator()
      detach()
      client.dispose()
      resetMirrors()
    }
  }, [client, coordinator, freshnessEnabled, principal, recovery])
  return (
    <QueryClientProvider client={client}>
      <LiveContext.Provider value={principal ? recovery : null}>
        <LiveCoordinatorContext.Provider value={principal && freshnessEnabled ? coordinator : null}>
          {children}
        </LiveCoordinatorContext.Provider>
      </LiveContext.Provider>
    </QueryClientProvider>
  )
}
