import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { loginBlockAdminApi } from '../api/login-block-admin-api'

const BLOCKS_KEY = ['admin-login-blocks']

export function useLoginBlocks(params?: { limit?: number; offset?: number }) {
  return useQuery({
    queryKey: [...BLOCKS_KEY, params],
    queryFn: () => loginBlockAdminApi.list(params),
  })
}

export function useDeleteLoginBlock() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: loginBlockAdminApi.delete,
    onSuccess: () => qc.invalidateQueries({ queryKey: BLOCKS_KEY }),
  })
}

export function useDeleteAllLoginBlocks() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: loginBlockAdminApi.deleteAll,
    onSuccess: () => qc.invalidateQueries({ queryKey: BLOCKS_KEY }),
  })
}
