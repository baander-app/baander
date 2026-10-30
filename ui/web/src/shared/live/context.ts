import { createContext, useContext } from 'react'
import type { LiveRecovery } from './recovery'
import type { LiveQueryCoordinator } from './query-coordinator'

export const LiveContext = createContext<LiveRecovery | null>(null)

export function useLiveRecovery(): LiveRecovery | null {
  return useContext(LiveContext)
}

export const LiveCoordinatorContext = createContext<LiveQueryCoordinator | null>(null)

export function useLiveCoordinator(): LiveQueryCoordinator | null {
  return useContext(LiveCoordinatorContext)
}
