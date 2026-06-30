import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { getConfigCheck } from '../api/config-check-api'

export function useConfigCheck() {
  const queryClient = useQueryClient()

  const query = useQuery({
    queryKey: ['config-check'],
    queryFn: getConfigCheck,
    retry: false,
  })

  const mutation = useMutation({
    mutationFn: getConfigCheck,
    onSuccess: (data) => {
      queryClient.setQueryData(['config-check'], data)
    },
  })

  return {
    ...query,
    refetch: mutation.mutate,
    isRefetching: mutation.isPending,
  }
}
