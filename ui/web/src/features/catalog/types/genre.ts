export interface Genre {
  uuid: string
  name: string
  slug: string
  parentId: string | null
  mbid: string
}

export interface GenreDetail extends Genre {
  children: Genre[]
}
