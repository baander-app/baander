import { useQueryClient, type QueryKey } from '@tanstack/react-query'
import { useLiveCoordinator } from '@/shared/live/context'
import type { LiveFamily } from '@/shared/live/protocol'

/** Keep refreshes behind the write, including when its owner loses access. */
export function useAdminMutationTools(families: readonly LiveFamily[]) {
  const coordinator = useLiveCoordinator()
  const queryClient = useQueryClient()
  return {
    run: async <T,>(operation: () => Promise<T>): Promise<T> => {
      const release = coordinator?.beginMutation(families)
      try {
        return await operation()
      } finally {
        release?.()
      }
    },
    invalidate: (queryKey: QueryKey) => queryClient.invalidateQueries({
      queryKey,
      ...(coordinator ? { refetchType: 'none' as const } : {}),
    }),
  }
}
