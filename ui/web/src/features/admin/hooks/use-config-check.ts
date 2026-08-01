import { useQuery } from '@tanstack/react-query'
import { getConfigCheck } from '../api/config-check-api'

export function useConfigCheck() {
  const query = useQuery({
    queryKey: ['config-check'],
    queryFn: getConfigCheck,
    retry: false,
  })

  // Expose the query's own refetch/isFetching/error so refresh failures surface in the UI
  // (previously a separate useMutation swallowed its own error state).
  return {
    ...query,
    refetch: query.refetch,
    isRefetching: query.isFetching,
  }
}
