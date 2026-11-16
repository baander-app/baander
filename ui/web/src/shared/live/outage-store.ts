import { create } from 'zustand'
import { withStoreDebug } from '@/shared/stores/debug'
import { withNoopGuard } from '@/shared/stores/with-noop-guard'

/** The bell entry for an outage that has lasted long; it exists only in this tab. */
export interface LiveOutageEntry {
  /** Wall-clock time the outage started, in epoch milliseconds. */
  readonly since: number
  readonly isRead: boolean
}

interface LiveOutageState {
  /** Wall-clock start of the current outage, or null while live updates are current. */
  since: number | null
  toastRaised: boolean
  entry: LiveOutageEntry | null

  begin: (since: number) => void
  raiseToast: () => void
  listEntry: () => void
  markEntryRead: () => void
  end: () => void
}

/**
 * What the outage watcher publishes for the toast and the bells. It is cleared only when an
 * outage ends or the signed-in session's watcher stops, never by access resets, so a long
 * outage keeps its bell entry while the browser re-establishes access.
 */
export const useLiveOutageStore = create<LiveOutageState>()(
  withStoreDebug('live.outage',
    withNoopGuard((set) => ({
      since: null,
      toastRaised: false,
      entry: null,

      begin: (since) => set({ since, toastRaised: false, entry: null }),

      raiseToast: () => set({ toastRaised: true }),

      listEntry: () => set((state) => state.since === null || state.entry
        ? state
        : { entry: { since: state.since, isRead: false } }),

      markEntryRead: () => set((state) => !state.entry || state.entry.isRead
        ? state
        : { entry: { ...state.entry, isRead: true } }),

      end: () => set({ since: null, toastRaised: false, entry: null }),
    })),
  ),
)
