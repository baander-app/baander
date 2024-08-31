import { keys } from '@mantine/core';

export enum LibraryType {
  AudioBook = 'audiobook',
  Movie = 'movie',
  Music = 'music',
  PostCast = 'podcast',
  TvShow = 'tv_show',
}

let cachedLibraryTypesForSelect: {label: string, value: string}[] | null = null;

export function getLibraryTypesForSelect() {
  if (cachedLibraryTypesForSelect !== null) {
    return cachedLibraryTypesForSelect;
  }

  cachedLibraryTypesForSelect = Object.entries(LibraryType).map(([key, value]) => ({
    label: key,
    value: value
  }));

  return cachedLibraryTypesForSelect;
}