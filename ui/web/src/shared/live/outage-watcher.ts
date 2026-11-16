import { toast } from 'sonner'
import { isLiveCurrent, type LiveRecovery } from './recovery'
import { useLiveOutageStore } from './outage-store'

export const LIVE_OUTAGE_TOAST_ID = 'live-updates-outage'

const TOAST_AFTER_MS = 15_000
const ENTRY_AFTER_MS = 2 * 60_000
const TICK_MS = 1_000

function documentVisible(): boolean {
  return typeof document === 'undefined' || document.visibilityState !== 'hidden'
}

/**
 * Turns the recovery snapshot into outage notices. An outage starts when live updates become
 * degraded and ends only when live data is current again; reconnecting and refreshing in between
 * continue it. Only time the tab is visible and the device awake counts towards the toast and
 * bell thresholds: each tick credits at most one interval, so a hidden tab, a sleep or a frozen
 * page cannot fire a notice the moment it wakes.
 */
export function startLiveOutageWatcher(recovery: LiveRecovery, freshnessEnabled: boolean): () => void {
  const outage = useLiveOutageStore.getState()
  let counted = 0
  let lastTick = 0
  let ticker: ReturnType<typeof setInterval> | null = null

  const tick = () => {
    const now = Date.now()
    const elapsed = Math.min(Math.max(now - lastTick, 0), TICK_MS)
    lastTick = now
    if (!documentVisible()) return
    counted += elapsed
    const state = useLiveOutageStore.getState()
    if (counted >= TOAST_AFTER_MS && !state.toastRaised) {
      outage.raiseToast()
      toast.warning('Live updates unavailable', {
        id: LIVE_OUTAGE_TOAST_ID,
        testId: LIVE_OUTAGE_TOAST_ID,
        description: 'What you see may be out of date until the connection recovers.',
      })
    }
    if (counted >= ENTRY_AFTER_MS && !state.entry) outage.listEntry()
  }

  const begin = () => {
    counted = 0
    lastTick = Date.now()
    outage.begin(lastTick)
    ticker = setInterval(tick, TICK_MS)
  }

  const end = () => {
    if (ticker) clearInterval(ticker)
    ticker = null
    if (useLiveOutageStore.getState().toastRaised) toast.dismiss(LIVE_OUTAGE_TOAST_ID)
    outage.end()
  }

  const observe = () => {
    const snapshot = recovery.getSnapshot()
    if (ticker && isLiveCurrent(snapshot, freshnessEnabled)) end()
    else if (!ticker && snapshot.status === 'degraded') begin()
  }

  const unsubscribe = recovery.subscribe(observe)
  observe()
  return () => {
    unsubscribe()
    end()
  }
}
