import { AXIOS_INSTANCE } from '@/shared/api-client/axios-instance'

export interface Genre {
  uuid: string
  name: string
  slug: string
  parentId: string | null
  mbid: string | null
}

export const genreAdminApi = {
  list: async (flat = true): Promise<Genre[]> => {
    const { data } = await AXIOS_INSTANCE.get('/api/genres/', {
      params: { flat },
    })
    return data.data
  },

  create: async (payload: {
    name: string
    slug: string
    parentId?: string | null
    mbid?: string | null
  }): Promise<Genre> => {
    const { data } = await AXIOS_INSTANCE.post('/api/genres/', payload)
    return data.data
  },

  update: async (
    slug: string,
    payload: { name?: string; slug?: string },
  ): Promise<Genre> => {
    const { data } = await AXIOS_INSTANCE.patch(`/api/genres/${slug}`, payload)
    return data.data
  },

  delete: async (slug: string): Promise<void> => {
    await AXIOS_INSTANCE.delete(`/api/genres/${slug}`)
  },

  addAlbum: async (slug: string, albumId: string): Promise<void> => {
    await AXIOS_INSTANCE.post(`/api/genres/${slug}/albums`, { albumId })
  },

  removeAlbum: async (slug: string, albumId: string): Promise<void> => {
    await AXIOS_INSTANCE.delete(`/api/genres/${slug}/albums/${albumId}`)
  },

  addSong: async (slug: string, songId: string): Promise<void> => {
    await AXIOS_INSTANCE.post(`/api/genres/${slug}/songs`, { songId })
  },

  removeSong: async (slug: string, songId: string): Promise<void> => {
    await AXIOS_INSTANCE.delete(`/api/genres/${slug}/songs/${songId}`)
  },
}
