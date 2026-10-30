import { useAdminMutationTools } from './use-admin-mutation'
import { useQuery, useMutation } from '@tanstack/react-query'
import { genreAdminApi } from '../api/genre-admin-api'

const GENRES_KEY = ['admin-genres']

export function useGenres(flat = true) {
  return useQuery({
    queryKey: [...GENRES_KEY, { flat }],
    queryFn: ({ signal }) => genreAdminApi.list(flat, signal),
  })
}

export function useCreateGenre() {
  const { run, invalidate } = useAdminMutationTools(['admin-metadata', 'catalog'])

  return useMutation({
    mutationFn: (variables: Parameters<typeof genreAdminApi.create>[0]) => run(() => genreAdminApi.create(variables)),
    onSuccess: () => invalidate(GENRES_KEY),
  })
}

export function useUpdateGenre() {
  const { run, invalidate } = useAdminMutationTools(['admin-metadata', 'catalog'])

  return useMutation({
    mutationFn: (params: {
      slug: string
      name?: string
      newSlug?: string
    }) => run(() => {
      const { slug, newSlug, ...payload } = params
      return genreAdminApi.update(slug, newSlug ? { ...payload, slug: newSlug } : payload)
    }),
    onSuccess: () => invalidate(GENRES_KEY),
  })
}

export function useDeleteGenre() {
  const { run, invalidate } = useAdminMutationTools(['admin-metadata', 'catalog'])

  return useMutation({
    mutationFn: (variables: Parameters<typeof genreAdminApi.delete>[0]) => run(() => genreAdminApi.delete(variables)),
    onSuccess: () => invalidate(GENRES_KEY),
  })
}

export function useAddAlbumToGenre() {
  const { run, invalidate } = useAdminMutationTools(['admin-metadata', 'catalog'])

  return useMutation({
    mutationFn: ({
      slug,
      albumId,
    }: {
      slug: string
      albumId: string
    }) => run(() => genreAdminApi.addAlbum(slug, albumId)),
    onSuccess: () => invalidate(GENRES_KEY),
  })
}

export function useRemoveAlbumFromGenre() {
  const { run, invalidate } = useAdminMutationTools(['admin-metadata', 'catalog'])

  return useMutation({
    mutationFn: ({
      slug,
      albumId,
    }: {
      slug: string
      albumId: string
    }) => run(() => genreAdminApi.removeAlbum(slug, albumId)),
    onSuccess: () => invalidate(GENRES_KEY),
  })
}

export function useAddSongToGenre() {
  const { run, invalidate } = useAdminMutationTools(['admin-metadata', 'catalog'])

  return useMutation({
    mutationFn: ({
      slug,
      songId,
    }: {
      slug: string
      songId: string
    }) => run(() => genreAdminApi.addSong(slug, songId)),
    onSuccess: () => invalidate(GENRES_KEY),
  })
}

export function useRemoveSongFromGenre() {
  const { run, invalidate } = useAdminMutationTools(['admin-metadata', 'catalog'])

  return useMutation({
    mutationFn: ({
      slug,
      songId,
    }: {
      slug: string
      songId: string
    }) => run(() => genreAdminApi.removeSong(slug, songId)),
    onSuccess: () => invalidate(GENRES_KEY),
  })
}
