import { AXIOS_INSTANCE } from '@/shared/api-client/axios-instance'

export interface AlbumSummary {
  uuid: string
  publicId: string
  title: string
  type?: string
  year?: number
  label?: string
  barcode?: string
  country?: string
  lockedFields: string[]
  createdAt: string
  coverImage?: {
    publicId: string
    blurhash?: string
  } | null
  artists?: Array<{ name: string; role?: string | null }>
}

export interface DuplicateGroup {
  albumIds: string[]
  confidence: number
  albumCount: number
  albums: AlbumSummary[]
}

export const albumDuplicatesApi = {
  getDuplicates: (libraryId: string) =>
    AXIOS_INSTANCE.get<{ data: DuplicateGroup[] }>('/api/admin/albums/duplicates', {
      params: { libraryId },
    }).then((r) => r.data.data),

  getAlbumDuplicates: (albumPublicId: string) =>
    AXIOS_INSTANCE.get<{ data: DuplicateGroup[] }>(`/api/albums/${albumPublicId}/duplicates`).then(
      (r) => r.data.data,
    ),
}

export function getCoverImageUrl(publicId: string): string {
  return `/api/images/${publicId}/file`
}
