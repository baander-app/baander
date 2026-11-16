import { createContext, useContext } from 'react'
import type { LiveQueryCoordinator } from './query-coordinator'

export const LiveCoordinatorContext = createContext<LiveQueryCoordinator | null>(null)

export function useLiveCoordinator(): LiveQueryCoordinator | null {
  return useContext(LiveCoordinatorContext)
}
