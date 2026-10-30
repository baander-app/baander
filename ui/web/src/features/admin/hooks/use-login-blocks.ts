import { useAdminMutationTools } from './use-admin-mutation'
import { useQuery, useMutation } from '@tanstack/react-query'
import { loginBlockAdminApi } from '../api/login-block-admin-api'

const BLOCKS_KEY = ['admin-login-blocks']

export function useLoginBlocks(params?: { limit?: number; offset?: number }) {
  return useQuery({
    queryKey: [...BLOCKS_KEY, params],
    queryFn: ({ signal }) => loginBlockAdminApi.list(params, signal),
  })
}

export function useDeleteLoginBlock() {
  const { run, invalidate } = useAdminMutationTools(['admin-security'])

  return useMutation({
    mutationFn: (variables: Parameters<typeof loginBlockAdminApi.delete>[0]) => run(() => loginBlockAdminApi.delete(variables)),
    onSuccess: () => invalidate(BLOCKS_KEY),
  })
}

export function useDeleteAllLoginBlocks() {
  const { run, invalidate } = useAdminMutationTools(['admin-security'])

  return useMutation({
    mutationFn: () => run(() => loginBlockAdminApi.deleteAll()),
    onSuccess: () => invalidate(BLOCKS_KEY),
  })
}
