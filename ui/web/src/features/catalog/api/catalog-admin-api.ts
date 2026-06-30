import { AXIOS_INSTANCE } from '@/shared/api-client/axios-instance'

// --- Types ---

export interface GenreCreateRequest {
  name: string
  slug: string
  parentId?: string
  mbid?: string
}

export interface ArtistCreateRequest {
  name: string
  country?: string
  gender?: string
  type?: string
  disambiguation?: string
  sortName?: string
  biography?: string
}

export interface ArtistSongRequest {
  songId: string
  role: string
}

export interface ArtistAlbumRequest {
  albumId: string
  role: string
}

export interface UpdateRoleRequest {
  role: string
}

// --- Genre API ---

export async function createGenre(payload: GenreCreateRequest): Promise<void> {
  await AXIOS_INSTANCE.post('/api/genres/', payload)
}

export async function addSongToGenre(genreSlug: string, songId: string): Promise<void> {
  await AXIOS_INSTANCE.post(`/api/genres/${genreSlug}/songs`, { songId })
}

export async function removeSongFromGenre(genreSlug: string, songId: string): Promise<void> {
  await AXIOS_INSTANCE.delete(`/api/genres/${genreSlug}/songs/${songId}`)
}

export async function addAlbumToGenre(genreSlug: string, albumId: string): Promise<void> {
  await AXIOS_INSTANCE.post(`/api/genres/${genreSlug}/albums`, { albumId })
}

export async function removeAlbumFromGenre(genreSlug: string, albumId: string): Promise<void> {
  await AXIOS_INSTANCE.delete(`/api/genres/${genreSlug}/albums/${albumId}`)
}

// --- Artist API ---

export async function createArtist(payload: ArtistCreateRequest): Promise<void> {
  await AXIOS_INSTANCE.post('/api/artists/', payload)
}

export async function addSongToArtist(artistPublicId: string, songId: string, role: string): Promise<void> {
  await AXIOS_INSTANCE.post(`/api/artists/${artistPublicId}/songs`, { songId, role })
}

export async function removeSongFromArtist(artistPublicId: string, songId: string): Promise<void> {
  await AXIOS_INSTANCE.delete(`/api/artists/${artistPublicId}/songs/${songId}`)
}

export async function updateArtistSongRole(artistPublicId: string, songId: string, role: string): Promise<void> {
  await AXIOS_INSTANCE.patch(`/api/artists/${artistPublicId}/songs/${songId}`, { role })
}

export async function addAlbumToArtist(artistPublicId: string, albumId: string, role: string): Promise<void> {
  await AXIOS_INSTANCE.post(`/api/artists/${artistPublicId}/albums`, { albumId, role })
}

export async function removeAlbumFromArtist(artistPublicId: string, albumId: string): Promise<void> {
  await AXIOS_INSTANCE.delete(`/api/artists/${artistPublicId}/albums/${albumId}`)
}

export async function updateArtistAlbumRole(artistPublicId: string, albumId: string, role: string): Promise<void> {
  await AXIOS_INSTANCE.patch(`/api/artists/${artistPublicId}/albums/${albumId}`, { role })
}
