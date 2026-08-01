import { useMutation, useQueryClient } from '@tanstack/react-query'
import { createLibrary, updateLibrary, deleteLibrary, scanLibrary, scanAllLibraries } from '../api/library-api'
import type { CreateLibraryPayload, UpdateLibraryPayload } from '../api/library-api'

export function useCreateLibrary() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (payload: CreateLibraryPayload) => createLibrary(payload),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['libraries'] })
    },
  })
}

export function useUpdateLibrary() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: ({ id, payload }: { id: string; payload: UpdateLibraryPayload }) =>
      updateLibrary(id, payload),
    onSuccess: (_data, variables) => {
      queryClient.invalidateQueries({ queryKey: ['libraries'] })
      queryClient.invalidateQueries({ queryKey: ['libraries', variables.id] })
    },
  })
}

export function useDeleteLibrary() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (id: string) => deleteLibrary(id),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['libraries'] })
    },
  })
}

export function useScanLibrary() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: ({ id, rescan }: { id: string; rescan?: boolean }) =>
      scanLibrary(id, { rescan }),
    onSuccess: (_data, variables) => {
      queryClient.invalidateQueries({ queryKey: ['libraries'] })
      queryClient.invalidateQueries({ queryKey: ['libraries', variables.id] })
      queryClient.invalidateQueries({ queryKey: ['libraries', variables.id, 'stats'] })
    },
  })
}

export function useScanAllLibraries() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: () => scanAllLibraries(),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['libraries'] })
    },
  })
}
