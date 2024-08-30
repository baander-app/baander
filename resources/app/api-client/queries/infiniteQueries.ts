// generated with @7nohe/openapi-react-query-codegen@1.5.1 

import { InfiniteData, useInfiniteQuery, UseInfiniteQueryOptions } from "@tanstack/react-query";
import { AlbumService, GenreService, LibraryService, SongService } from "../requests/services.gen";
import * as Common from "./common";
/**
* @param data The data for the request.
* @param data.library
* @param data.page
* @param data.perPage
* @param data.fields
* @param data.relations
* @returns unknown Json paginated set of `AlbumResource`
* @throws ApiError
*/
export const useAlbumServiceAlbumsIndexInfinite = <TData = InfiniteData<Common.AlbumServiceAlbumsIndexDefaultResponse>, TError = unknown, TQueryKey extends Array<unknown> = unknown[]>({ fields, library, perPage, relations }: {
  fields?: string;
  library: string;
  perPage?: number;
  relations?: string;
}, queryKey?: TQueryKey, options?: Omit<UseInfiniteQueryOptions<TData, TError>, "queryKey" | "queryFn">) => useInfiniteQuery({ queryKey: Common.UseAlbumServiceAlbumsIndexKeyFn({ fields, library, perPage, relations }, queryKey), queryFn: ({ pageParam }) => AlbumService.albumsIndex({ fields, library, page: pageParam as number, perPage, relations }) as TData, initialPageParam: 1, getNextPageParam: response => (response as { nextPage: number }).nextPage, ...options });
/**
* @param data The data for the request.
* @param data.library
* @param data.page
* @param data.perPage
* @returns unknown Json paginated set of `GenreResource`
* @throws ApiError
*/
export const useGenreServiceGenresIndexInfinite = <TData = InfiniteData<Common.GenreServiceGenresIndexDefaultResponse>, TError = unknown, TQueryKey extends Array<unknown> = unknown[]>({ library, perPage }: {
  library: string;
  perPage?: number;
}, queryKey?: TQueryKey, options?: Omit<UseInfiniteQueryOptions<TData, TError>, "queryKey" | "queryFn">) => useInfiniteQuery({ queryKey: Common.UseGenreServiceGenresIndexKeyFn({ library, perPage }, queryKey), queryFn: ({ pageParam }) => GenreService.genresIndex({ library, page: pageParam as number, perPage }) as TData, initialPageParam: 1, getNextPageParam: response => (response as { nextPage: number }).nextPage, ...options });
/**
* @param data The data for the request.
* @param data.page
* @param data.perPage
* @returns unknown Json paginated set of `LibraryResource`
* @throws ApiError
*/
export const useLibraryServiceLibrariesIndexInfinite = <TData = InfiniteData<Common.LibraryServiceLibrariesIndexDefaultResponse>, TError = unknown, TQueryKey extends Array<unknown> = unknown[]>({ perPage }: {
  perPage?: number;
} = {}, queryKey?: TQueryKey, options?: Omit<UseInfiniteQueryOptions<TData, TError>, "queryKey" | "queryFn">) => useInfiniteQuery({ queryKey: Common.UseLibraryServiceLibrariesIndexKeyFn({ perPage }, queryKey), queryFn: ({ pageParam }) => LibraryService.librariesIndex({ page: pageParam as number, perPage }) as TData, initialPageParam: 1, getNextPageParam: response => (response as { nextPage: number }).nextPage, ...options });
/**
* @param data The data for the request.
* @param data.library The library slug
* @param data.albumArtist
* @param data.genreIds
* @param data.title
* @param data.albumId
* @param data.page
* @param data.perPage
* @returns unknown Json paginated set of `SongResource`
* @throws ApiError
*/
export const useSongServiceSongsIndexInfinite = <TData = InfiniteData<Common.SongServiceSongsIndexDefaultResponse>, TError = unknown, TQueryKey extends Array<unknown> = unknown[]>({ albumArtist, albumId, genreIds, library, perPage, title }: {
  albumArtist?: string;
  albumId?: number;
  genreIds?: string;
  library: string;
  perPage?: number;
  title?: string;
}, queryKey?: TQueryKey, options?: Omit<UseInfiniteQueryOptions<TData, TError>, "queryKey" | "queryFn">) => useInfiniteQuery({ queryKey: Common.UseSongServiceSongsIndexKeyFn({ albumArtist, albumId, genreIds, library, perPage, title }, queryKey), queryFn: ({ pageParam }) => SongService.songsIndex({ albumArtist, albumId, genreIds, library, page: pageParam as number, perPage, title }) as TData, initialPageParam: 1, getNextPageParam: response => (response as { nextPage: number }).nextPage, ...options });
