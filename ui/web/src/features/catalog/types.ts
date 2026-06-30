/** Shared song entry type used by catalog components and player store. */
export interface SongEntry {
  publicId: string
  title: string
  artistName?: string
  albumName?: string
  albumPublicId?: string
  duration?: number
  year?: number
}
