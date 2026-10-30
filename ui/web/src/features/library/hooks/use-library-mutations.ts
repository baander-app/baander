import { useMutation, useQueryClient, type UseMutateFunction } from '@tanstack/react-query'
import { useLiveCoordinator } from '@/shared/live/context'
import { assertCurrentOwnership, captureOwnership, guardMutationCallbacks } from '@/shared/live/ownership'
import { createLibrary, updateLibrary, deleteLibrary, scanLibrary, scanAllLibraries } from '../api/library-api'
import type { CreateLibraryPayload, UpdateLibraryPayload } from '../api/library-api'
import { libraryKeys } from '../query-keys'

function useLibraryMutation<TData, TVariables>(write: (variables: TVariables) => Promise<TData>) {
  const queryClient = useQueryClient()
  const coordinator = useLiveCoordinator()
  const mutation = useMutation({
    mutationFn: async (variables: TVariables) => {
      const owner = captureOwnership()
      const release = coordinator?.beginMutation(['libraries'])
      try {
        const result = await write(variables)
        assertCurrentOwnership(owner)
        return result
      } finally {
        release?.()
      }
    },
    onSuccess: () => {
      if (!coordinator) {
        queryClient.invalidateQueries({ queryKey: libraryKeys.all }).catch(() => undefined)
      }
    },
  })
  const mutate: UseMutateFunction<TData, Error, TVariables> = (variables, callbacks) => {
    mutation.mutate(variables, callbacks ? guardMutationCallbacks(callbacks) : undefined)
  }
  return { ...mutation, mutate }
}

export function useCreateLibrary() {
  return useLibraryMutation((payload: CreateLibraryPayload) => createLibrary(payload))
}

export function useUpdateLibrary() {
  return useLibraryMutation(({ id, payload }: { id: string; payload: UpdateLibraryPayload }) =>
    updateLibrary(id, payload))
}

export function useDeleteLibrary() {
  return useLibraryMutation((id: string) => deleteLibrary(id))
}

export function useScanLibrary() {
  return useLibraryMutation(({ id, rescan }: { id: string; rescan?: boolean }) =>
    scanLibrary(id, { rescan }))
}

export function useScanAllLibraries() {
  return useLibraryMutation(() => scanAllLibraries())
}
