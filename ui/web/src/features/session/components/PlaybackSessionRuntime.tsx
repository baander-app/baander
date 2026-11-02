import { useEffect, useMemo, useSyncExternalStore } from 'react'
import { useQueryClient } from '@tanstack/react-query'
import { captureOwnership, subscribeOwnership } from '@/shared/live/ownership'
import { useAuthStore } from '@/features/auth/stores/auth-store'
import { PlaybackLocalRecovery } from '../services/playback-local-recovery'
import { PlaybackSessionCoordinator, registerPlaybackCoordinator } from '../services/playback-session-coordinator'

export function PlaybackSessionRuntime() {
  const client = useQueryClient()
  const ownership = useSyncExternalStore(subscribeOwnership, captureOwnership, captureOwnership)
  const principal = useAuthStore(state => state.principalSessionId)
  const account = useAuthStore(state => state.user?.publicId)
  const recovery = useMemo(() => account ? new PlaybackLocalRecovery(new URL(window.__BAANDER_API_URL__ ?? window.location.origin, window.location.href).href, account) : undefined, [account])
  useEffect(() => {
    if (!principal || !account) return
    const coordinator = new PlaybackSessionCoordinator(client, undefined, recovery)
    const unregister = registerPlaybackCoordinator(coordinator)
    const stop = coordinator.start()
    return () => { unregister(); stop() }
  }, [client, principal, account, ownership, recovery])
  useEffect(() => () => recovery?.dispose(), [recovery])
  return null
}
