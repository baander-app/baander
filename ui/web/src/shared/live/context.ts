import { createContext, useContext } from 'react'
import type { LiveRecovery } from './recovery'

export const LiveContext = createContext<LiveRecovery | null>(null)

export function useLiveRecovery(): LiveRecovery | null {
  return useContext(LiveContext)
}
