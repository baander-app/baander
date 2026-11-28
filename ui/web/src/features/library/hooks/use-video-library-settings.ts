import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import {
  getAdminVideoMatchesProviderOrder,
  getAdminVideoMatchesLibraryRematchPreview,
  getGetAdminVideoMatchesProviderOrderQueryKey,
  getGetAdminVideoMatchesLibraryRematchPreviewQueryKey,
  putAdminVideoMatchesProviderOrderUpdate,
  postAdminVideoMatchesLibraryRematch,
  type RematchVideoLibraryRequest,
} from '@/shared/api-client/gen/endpoints'
import { useAdminMutationTools } from '@/features/admin/hooks/use-admin-mutation'
import { videoMatchKeys } from '@/features/admin/hooks/use-video-match-admin'
import { libraryKeys } from '../query-keys'

function responseData<T>(response: { data?: T }): T {
  if (response.data === undefined) {
    throw new Error('The server returned no library match settings.')
  }
  return response.data
}

export function useVideoLibrarySettings(library: string, previewOpen: boolean) {
  const queryClient = useQueryClient()
  const { run, invalidate } = useAdminMutationTools(['admin-metadata', 'catalog', 'libraries', 'admin-jobs'])
  const orderKey = getGetAdminVideoMatchesProviderOrderQueryKey(library)
  const previewKey = getGetAdminVideoMatchesLibraryRematchPreviewQueryKey(library)
  const order = useQuery({
    queryKey: orderKey,
    queryFn: async ({ signal }) => {
      const response = await getAdminVideoMatchesProviderOrder(library, { signal })
      return responseData(response)
    },
    retry: false,
  })
  const preview = useQuery({
    queryKey: previewKey,
    queryFn: async ({ signal }) => {
      const response = await getAdminVideoMatchesLibraryRematchPreview(library, { signal })
      return responseData(response)
    },
    enabled: previewOpen,
    retry: false,
    refetchOnMount: 'always',
  })
  const saveOrder = useMutation({
    mutationFn: (providers: string[]) => run(async () => {
      const response = await putAdminVideoMatchesProviderOrderUpdate(library, { providers })
      return responseData(response)
    }),
    onSuccess: async (data) => {
      await queryClient.cancelQueries({ queryKey: orderKey, exact: true })
      queryClient.setQueryData(orderKey, data)
      await invalidate(libraryKeys.detail(library))
    },
  })
  const rematch = useMutation({
    mutationFn: (options: RematchVideoLibraryRequest) => run(async () => {
      const response = await postAdminVideoMatchesLibraryRematch(library, options)
      return responseData(response)
    }),
    onSuccess: async () => {
      await Promise.all([
        invalidate(previewKey),
        invalidate(libraryKeys.detail(library)),
        invalidate(videoMatchKeys.all),
      ])
    },
  })

  return { order, preview, saveOrder, rematch }
}
