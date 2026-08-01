/**
 * Movie shapes returned by the catalog movie endpoints.
 *
 * The generated `MovieResource` only documents the core fields (uuid, publicId,
 * title, year, summary, createdAt). The real API additionally returns enriched
 * metadata (poster/backdrop URLs, rating, runtime, TMDB/IMDB ids, videos) that
 * the movie UI depends on. These fields are modelled here as optional rather
 * than casting to `any`.
 */

export interface MovieVideo {
  uuid: string
  publicId?: string
  width?: number
  height?: number
  duration?: number
}

export interface MovieSummary {
  publicId: string
  title: string
  year?: number | null
  posterUrl?: string | null
  rating?: number | null
  runtime?: number | null
  videos?: MovieVideo[]
}

export interface MovieDetail extends MovieSummary {
  uuid?: string
  tagline?: string | null
  backdropUrl?: string | null
  overview?: string | null
  originalLanguage?: string | null
  tmdbId?: number | null
  imdbId?: string | null
}
