import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { genreAdminApi } from '../api/genre-admin-api'

const GENRES_KEY = ['admin-genres']

export function useGenres(flat = true) {
  return useQuery({
    queryKey: [...GENRES_KEY, { flat }],
    queryFn: () => genreAdminApi.list(flat),
  })
}

export function useCreateGenre() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: genreAdminApi.create,
    onSuccess: () => qc.invalidateQueries({ queryKey: GENRES_KEY }),
  })
}

export function useUpdateGenre() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: (params: {
      slug: string
      name?: string
      newSlug?: string
    }) => {
      const { slug, newSlug, ...payload } = params
      return genreAdminApi.update(slug, newSlug ? { ...payload, slug: newSlug } : payload)
    },
    onSuccess: () => qc.invalidateQueries({ queryKey: GENRES_KEY }),
  })
}

export function useDeleteGenre() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: genreAdminApi.delete,
    onSuccess: () => qc.invalidateQueries({ queryKey: GENRES_KEY }),
  })
}

export function useAddAlbumToGenre() {
  return useMutation({
    mutationFn: ({
      slug,
      albumId,
    }: {
      slug: string
      albumId: string
    }) => genreAdminApi.addAlbum(slug, albumId),
  })
}

export function useRemoveAlbumFromGenre() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: ({
      slug,
      albumId,
    }: {
      slug: string
      albumId: string
    }) => genreAdminApi.removeAlbum(slug, albumId),
    onSuccess: () => qc.invalidateQueries({ queryKey: GENRES_KEY }),
  })
}

export function useAddSongToGenre() {
  return useMutation({
    mutationFn: ({
      slug,
      songId,
    }: {
      slug: string
      songId: string
    }) => genreAdminApi.addSong(slug, songId),
  })
}

export function useRemoveSongFromGenre() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: ({
      slug,
      songId,
    }: {
      slug: string
      songId: string
    }) => genreAdminApi.removeSong(slug, songId),
    onSuccess: () => qc.invalidateQueries({ queryKey: GENRES_KEY }),
  })
}
