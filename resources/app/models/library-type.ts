export enum LibraryType {
  Music = 'music',
  Movie = 'movie',
  Tv = 'tv_show',
}

export type LibraryMusic = LibraryType.Music;
export type LibraryMovie = LibraryType.Movie;
export type LibraryTv = LibraryType.Tv;

export function isMusicLibrary(v: unknown): v is LibraryMusic {
  return Boolean(v) && typeof v === 'string' && v === LibraryType.Music;
}

export function isMovieLibrary(v: unknown): v is LibraryMovie {
  return Boolean(v) && typeof v === 'string' && v === LibraryType.Movie;
}

export function isTvLibrary(v: unknown): v is LibraryTv {
  return Boolean(v) && typeof v === 'string' && v === LibraryType.Tv;
}
