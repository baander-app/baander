// generated with @7nohe/openapi-react-query-codegen@1.6.2 

import { InfiniteData, useInfiniteQuery, UseInfiniteQueryOptions } from "@tanstack/react-query";
import { AlbumService, ArtistService, GenreService, LibraryService, QueueService, SongService, UserService, UserTokenService } from "../requests/services.gen";
import * as Common from "./common";
/**
* Get a collection of albums
* @param data The data for the request.
* @param data.library The library slug
* @param data.fields
* @param data.relations
* @param data.page
* @param data.limit
* @param data.genres
* @returns unknown Paginated set of `AlbumResource`
* @throws ApiError
*/
export const useAlbumServiceGetApiLibrariesByLibraryAlbumsInfinite = <TData = InfiniteData<Common.AlbumServiceGetApiLibrariesByLibraryAlbumsDefaultResponse>, TError = unknown, TQueryKey extends Array<unknown> = unknown[]>({ fields, genres, library, limit, relations }: {
  fields?: string;
  genres?: string;
  library: string;
  limit?: number;
  relations?: string;
}, queryKey?: TQueryKey, options?: Omit<UseInfiniteQueryOptions<TData, TError>, "queryKey" | "queryFn">) => useInfiniteQuery({
  queryKey: Common.UseAlbumServiceGetApiLibrariesByLibraryAlbumsKeyFn({ fields, genres, library, limit, relations }, queryKey), queryFn: ({ pageParam }) => AlbumService.getApiLibrariesByLibraryAlbums({ fields, genres, library, limit, page: pageParam as number, relations }) as TData, initialPageParam: "1", getNextPageParam: response => (response as {
    nextPage: number;
  }).nextPage, ...options
});
/**
* Get a collection of artists
* @param data The data for the request.
* @param data.library
* @param data.fields
* @param data.relations
* @param data.page
* @param data.limit
* @param data.genres
* @returns unknown Paginated set of `ArtistResource`
* @throws ApiError
*/
export const useArtistServiceGetApiLibrariesByLibraryArtistsInfinite = <TData = InfiniteData<Common.ArtistServiceGetApiLibrariesByLibraryArtistsDefaultResponse>, TError = unknown, TQueryKey extends Array<unknown> = unknown[]>({ fields, genres, library, limit, relations }: {
  fields?: string;
  genres?: string;
  library: string;
  limit?: number;
  relations?: string;
}, queryKey?: TQueryKey, options?: Omit<UseInfiniteQueryOptions<TData, TError>, "queryKey" | "queryFn">) => useInfiniteQuery({
  queryKey: Common.UseArtistServiceGetApiLibrariesByLibraryArtistsKeyFn({ fields, genres, library, limit, relations }, queryKey), queryFn: ({ pageParam }) => ArtistService.getApiLibrariesByLibraryArtists({ fields, genres, library, limit, page: pageParam as number, relations }) as TData, initialPageParam: "1", getNextPageParam: response => (response as {
    nextPage: number;
  }).nextPage, ...options
});
/**
* Get a collection of genres
* @param data The data for the request.
* @param data.fields
* @param data.relations
* @param data.librarySlug
* @param data.page
* @param data.limit
* @returns unknown Paginated set of `GenreResource`
* @throws ApiError
*/
export const useGenreServiceGetApiGenresInfinite = <TData = InfiniteData<Common.GenreServiceGetApiGenresDefaultResponse>, TError = unknown, TQueryKey extends Array<unknown> = unknown[]>({ fields, librarySlug, limit, relations }: {
  fields?: string;
  librarySlug?: string;
  limit?: number;
  relations?: string;
} = {}, queryKey?: TQueryKey, options?: Omit<UseInfiniteQueryOptions<TData, TError>, "queryKey" | "queryFn">) => useInfiniteQuery({
  queryKey: Common.UseGenreServiceGetApiGenresKeyFn({ fields, librarySlug, limit, relations }, queryKey), queryFn: ({ pageParam }) => GenreService.getApiGenres({ fields, librarySlug, limit, page: pageParam as number, relations }) as TData, initialPageParam: "1", getNextPageParam: response => (response as {
    nextPage: number;
  }).nextPage, ...options
});
/**
* Get a collection of media libraries
* @param data The data for the request.
* @param data.page
* @param data.limit
* @returns unknown Paginated set of `LibraryResource`
* @throws ApiError
*/
export const useLibraryServiceGetApiLibrariesInfinite = <TData = InfiniteData<Common.LibraryServiceGetApiLibrariesDefaultResponse>, TError = unknown, TQueryKey extends Array<unknown> = unknown[]>({ limit }: {
  limit?: number;
} = {}, queryKey?: TQueryKey, options?: Omit<UseInfiniteQueryOptions<TData, TError>, "queryKey" | "queryFn">) => useInfiniteQuery({
  queryKey: Common.UseLibraryServiceGetApiLibrariesKeyFn({ limit }, queryKey), queryFn: ({ pageParam }) => LibraryService.getApiLibraries({ limit, page: pageParam as number }) as TData, initialPageParam: "1", getNextPageParam: response => (response as {
    nextPage: number;
  }).nextPage, ...options
});
/**
* Get a collection of monitor entries
* @param data The data for the request.
* @param data.page
* @param data.limit
* @param data.status
* @param data.queue
* @param data.name
* @param data.queuedFirst
* @returns unknown Paginated set of `QueueMonitorResource`
* @throws ApiError
*/
export const useQueueServiceGetApiQueueMetricsInfinite = <TData = InfiniteData<Common.QueueServiceGetApiQueueMetricsDefaultResponse>, TError = unknown, TQueryKey extends Array<unknown> = unknown[]>({ limit, name, queue, queuedFirst, status }: {
  limit?: number;
  name?: string;
  queue?: string;
  queuedFirst?: boolean;
  status?: "running" | "succeeded" | "failed" | "stale" | "queued";
} = {}, queryKey?: TQueryKey, options?: Omit<UseInfiniteQueryOptions<TData, TError>, "queryKey" | "queryFn">) => useInfiniteQuery({
  queryKey: Common.UseQueueServiceGetApiQueueMetricsKeyFn({ limit, name, queue, queuedFirst, status }, queryKey), queryFn: ({ pageParam }) => QueueService.getApiQueueMetrics({ limit, name, page: pageParam as number, queue, queuedFirst, status }) as TData, initialPageParam: "1", getNextPageParam: response => (response as {
    nextPage: number;
  }).nextPage, ...options
});
/**
* Get a collection of songs
* @param data The data for the request.
* @param data.library The library slug
* @param data.page
* @param data.limit
* @param data.genreNames
* @param data.genreSlugs
* @param data.relations
* @returns unknown Paginated set of `SongResource`
* @throws ApiError
*/
export const useSongServiceGetApiLibrariesByLibrarySongsInfinite = <TData = InfiniteData<Common.SongServiceGetApiLibrariesByLibrarySongsDefaultResponse>, TError = unknown, TQueryKey extends Array<unknown> = unknown[]>({ genreNames, genreSlugs, library, limit, relations }: {
  genreNames?: string;
  genreSlugs?: string;
  library: string;
  limit?: number;
  relations?: string;
}, queryKey?: TQueryKey, options?: Omit<UseInfiniteQueryOptions<TData, TError>, "queryKey" | "queryFn">) => useInfiniteQuery({
  queryKey: Common.UseSongServiceGetApiLibrariesByLibrarySongsKeyFn({ genreNames, genreSlugs, library, limit, relations }, queryKey), queryFn: ({ pageParam }) => SongService.getApiLibrariesByLibrarySongs({ genreNames, genreSlugs, library, limit, page: pageParam as number, relations }) as TData, initialPageParam: "1", getNextPageParam: response => (response as {
    nextPage: number;
  }).nextPage, ...options
});
/**
* Get a collection of users
* @param data The data for the request.
* @param data.page
* @param data.limit
* @param data.globalFilter
* @param data.filters
* @param data.filterModes
* @param data.sorting
* @returns unknown Paginated set of `UserResource`
* @throws ApiError
*/
export const useUserServiceGetApiUsersInfinite = <TData = InfiniteData<Common.UserServiceGetApiUsersDefaultResponse>, TError = unknown, TQueryKey extends Array<unknown> = unknown[]>({ filterModes, filters, globalFilter, limit, sorting }: {
  filterModes?: string;
  filters?: string;
  globalFilter?: string;
  limit?: number;
  sorting?: string;
} = {}, queryKey?: TQueryKey, options?: Omit<UseInfiniteQueryOptions<TData, TError>, "queryKey" | "queryFn">) => useInfiniteQuery({
  queryKey: Common.UseUserServiceGetApiUsersKeyFn({ filterModes, filters, globalFilter, limit, sorting }, queryKey), queryFn: ({ pageParam }) => UserService.getApiUsers({ filterModes, filters, globalFilter, limit, page: pageParam as number, sorting }) as TData, initialPageParam: "1", getNextPageParam: response => (response as {
    nextPage: number;
  }).nextPage, ...options
});
/**
* Get a collection of tokens
* @param data The data for the request.
* @param data.user
* @param data.page
* @param data.perPage
* @returns unknown Paginated set of `PersonalAccessTokenViewResource`
* @throws ApiError
*/
export const useUserTokenServiceGetApiUsersTokensByUserInfinite = <TData = InfiniteData<Common.UserTokenServiceGetApiUsersTokensByUserDefaultResponse>, TError = unknown, TQueryKey extends Array<unknown> = unknown[]>({ perPage, user }: {
  perPage?: number;
  user: string;
}, queryKey?: TQueryKey, options?: Omit<UseInfiniteQueryOptions<TData, TError>, "queryKey" | "queryFn">) => useInfiniteQuery({
  queryKey: Common.UseUserTokenServiceGetApiUsersTokensByUserKeyFn({ perPage, user }, queryKey), queryFn: ({ pageParam }) => UserTokenService.getApiUsersTokensByUser({ page: pageParam as number, perPage, user }) as TData, initialPageParam: "1", getNextPageParam: response => (response as {
    nextPage: number;
  }).nextPage, ...options
});
