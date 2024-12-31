// generated with @7nohe/openapi-react-query-codegen@1.6.1 

import { InfiniteData, useInfiniteQuery, UseInfiniteQueryOptions } from "@tanstack/react-query";
import { AlbumService, ArtistService, GenreService, LibraryService, QueueService, SongService, UserService, UserTokenService } from "../requests/services.gen";
import * as Common from "./common";
/**
* Get a collection of albums
* @param data The data for the request.
* @param data.library The library slug
* @param data.fields Comma seperated string of fields you want to select. If nothing is defined `select *` is default.
* - title
* - slug
* - year
* - directory
* @param data.relations Comma seperated string of relations
* - albumArist
* - cover
* - library
* - songs
* @param data.page Current page
* @param data.limit Items per page
* @param data.genres _Extension_ Comma seperated list of genres
* @returns unknown Json paginated set of `AlbumResource`
* @throws ApiError
*/
export const useAlbumServiceAlbumsIndexInfinite = <TData = InfiniteData<Common.AlbumServiceAlbumsIndexDefaultResponse>, TError = unknown, TQueryKey extends Array<unknown> = unknown[]>({ fields, genres, library, limit, relations }: {
  fields?: string;
  genres?: string;
  library: string;
  limit?: number;
  relations?: string;
}, queryKey?: TQueryKey, options?: Omit<UseInfiniteQueryOptions<TData, TError>, "queryKey" | "queryFn">) => useInfiniteQuery({
  queryKey: Common.UseAlbumServiceAlbumsIndexKeyFn({ fields, genres, library, limit, relations }, queryKey), queryFn: ({ pageParam }) => AlbumService.albumsIndex({ fields, genres, library, limit, page: pageParam as number, relations }) as TData, initialPageParam: "1", getNextPageParam: response => (response as {
    nextPage: number;
  }).nextPage, ...options
});
/**
* Get a collection of artists
* @param data The data for the request.
* @param data.library
* @param data.fields Comma seperated string of fields you want to select. If nothing is defined `select *` is default.
* - title
* - slug
* @param data.relations Comma seperated string of relations
* - portrait
* - songs
* @param data.page Current page
* @param data.limit Items per page
* @param data.genres _Extension_ Comma seperated list of genres
* @returns unknown Json paginated set of `ArtistResource`
* @throws ApiError
*/
export const useArtistServiceArtistsIndexInfinite = <TData = InfiniteData<Common.ArtistServiceArtistsIndexDefaultResponse>, TError = unknown, TQueryKey extends Array<unknown> = unknown[]>({ fields, genres, library, limit, relations }: {
  fields?: string;
  genres?: string;
  library: string;
  limit?: number;
  relations?: string;
}, queryKey?: TQueryKey, options?: Omit<UseInfiniteQueryOptions<TData, TError>, "queryKey" | "queryFn">) => useInfiniteQuery({
  queryKey: Common.UseArtistServiceArtistsIndexKeyFn({ fields, genres, library, limit, relations }, queryKey), queryFn: ({ pageParam }) => ArtistService.artistsIndex({ fields, genres, library, limit, page: pageParam as number, relations }) as TData, initialPageParam: "1", getNextPageParam: response => (response as {
    nextPage: number;
  }).nextPage, ...options
});
/**
* Get a collection of genres
* @param data The data for the request.
* @param data.fields Comma seperated string of fields you want to select. If nothing is defined `select *` is default.
* - name
* - slug
* @param data.relations Comma seperated string of relations
* - songs
* @param data.librarySlug Constrain the query to only fetch genres that are contained within the given library
* @param data.page Current page
* @param data.limit Items per page
* @returns unknown Json paginated set of `GenreResource`
* @throws ApiError
*/
export const useGenreServiceGenresIndexInfinite = <TData = InfiniteData<Common.GenreServiceGenresIndexDefaultResponse>, TError = unknown, TQueryKey extends Array<unknown> = unknown[]>({ fields, librarySlug, limit, relations }: {
  fields?: string;
  librarySlug?: string;
  limit?: number;
  relations?: string;
} = {}, queryKey?: TQueryKey, options?: Omit<UseInfiniteQueryOptions<TData, TError>, "queryKey" | "queryFn">) => useInfiniteQuery({
  queryKey: Common.UseGenreServiceGenresIndexKeyFn({ fields, librarySlug, limit, relations }, queryKey), queryFn: ({ pageParam }) => GenreService.genresIndex({ fields, librarySlug, limit, page: pageParam as number, relations }) as TData, initialPageParam: "1", getNextPageParam: response => (response as {
    nextPage: number;
  }).nextPage, ...options
});
/**
* Get a collection of media libraries
* @param data The data for the request.
* @param data.page Current page
* @param data.limit Items per page
* @returns unknown Json paginated set of `LibraryResource`
* @throws ApiError
*/
export const useLibraryServiceLibrariesIndexInfinite = <TData = InfiniteData<Common.LibraryServiceLibrariesIndexDefaultResponse>, TError = unknown, TQueryKey extends Array<unknown> = unknown[]>({ limit }: {
  limit?: number;
} = {}, queryKey?: TQueryKey, options?: Omit<UseInfiniteQueryOptions<TData, TError>, "queryKey" | "queryFn">) => useInfiniteQuery({
  queryKey: Common.UseLibraryServiceLibrariesIndexKeyFn({ limit }, queryKey), queryFn: ({ pageParam }) => LibraryService.librariesIndex({ limit, page: pageParam as number }) as TData, initialPageParam: "1", getNextPageParam: response => (response as {
    nextPage: number;
  }).nextPage, ...options
});
/**
* Get a collection of monitor entries
* @param data The data for the request.
* @param data.page Current page
* @param data.limit Items per page
* @param data.status MonitorStatus
* - 0=RUNNING
* - 1=SUCCEEDED
* - 2=FAILED
* - 3=STALE
* - 4=QUEUED
* @param data.queue Name of the queue
* @param data.name Name of the job
* @param data.queuedFirst Order queued jobs first
* @returns unknown Json paginated set of `QueueMonitorResource`
* @throws ApiError
*/
export const useQueueServiceQueueMetricsShowInfinite = <TData = InfiniteData<Common.QueueServiceQueueMetricsShowDefaultResponse>, TError = unknown, TQueryKey extends Array<unknown> = unknown[]>({ limit, name, queue, queuedFirst, status }: {
  limit?: number;
  name?: string;
  queue?: string;
  queuedFirst?: boolean;
  status?: "running" | "succeeded" | "failed" | "stale" | "queued";
} = {}, queryKey?: TQueryKey, options?: Omit<UseInfiniteQueryOptions<TData, TError>, "queryKey" | "queryFn">) => useInfiniteQuery({
  queryKey: Common.UseQueueServiceQueueMetricsShowKeyFn({ limit, name, queue, queuedFirst, status }, queryKey), queryFn: ({ pageParam }) => QueueService.queueMetricsShow({ limit, name, page: pageParam as number, queue, queuedFirst, status }) as TData, initialPageParam: "1", getNextPageParam: response => (response as {
    nextPage: number;
  }).nextPage, ...options
});
/**
* Get a collection of songs
* @param data The data for the request.
* @param data.library The library slug
* @param data.page Current page
* @param data.limit Items per page
* @param data.genreNames Comma seperated list of genre names You can only search for names or slugs. Not both.
* @param data.genreSlugs Comma seperated list of genre slugs
* @param data.relations Comma seperated string of relations
* - album
* - artists
* - album.albumArtist
* - genres
* @returns unknown Json paginated set of `SongResource`
* @throws ApiError
*/
export const useSongServiceSongsIndexInfinite = <TData = InfiniteData<Common.SongServiceSongsIndexDefaultResponse>, TError = unknown, TQueryKey extends Array<unknown> = unknown[]>({ genreNames, genreSlugs, library, limit, relations }: {
  genreNames?: string;
  genreSlugs?: string;
  library: string;
  limit?: number;
  relations?: string;
}, queryKey?: TQueryKey, options?: Omit<UseInfiniteQueryOptions<TData, TError>, "queryKey" | "queryFn">) => useInfiniteQuery({
  queryKey: Common.UseSongServiceSongsIndexKeyFn({ genreNames, genreSlugs, library, limit, relations }, queryKey), queryFn: ({ pageParam }) => SongService.songsIndex({ genreNames, genreSlugs, library, limit, page: pageParam as number, relations }) as TData, initialPageParam: "1", getNextPageParam: response => (response as {
    nextPage: number;
  }).nextPage, ...options
});
/**
* Get a collection of users
* @param data The data for the request.
* @param data.page Current page
* @param data.limit Items per page
* @param data.globalFilter
* @param data.filters JSON object
* @param data.filterModes JSON object
* @param data.sorting JSON object
* @returns unknown Json paginated set of `UserResource`
* @throws ApiError
*/
export const useUserServiceUsersIndexInfinite = <TData = InfiniteData<Common.UserServiceUsersIndexDefaultResponse>, TError = unknown, TQueryKey extends Array<unknown> = unknown[]>({ filterModes, filters, globalFilter, limit, sorting }: {
  filterModes?: string;
  filters?: string;
  globalFilter?: string;
  limit?: number;
  sorting?: string;
} = {}, queryKey?: TQueryKey, options?: Omit<UseInfiniteQueryOptions<TData, TError>, "queryKey" | "queryFn">) => useInfiniteQuery({
  queryKey: Common.UseUserServiceUsersIndexKeyFn({ filterModes, filters, globalFilter, limit, sorting }, queryKey), queryFn: ({ pageParam }) => UserService.usersIndex({ filterModes, filters, globalFilter, limit, page: pageParam as number, sorting }) as TData, initialPageParam: "1", getNextPageParam: response => (response as {
    nextPage: number;
  }).nextPage, ...options
});
/**
* Get a collection of tokens
* @param data The data for the request.
* @param data.user
* @param data.page
* @param data.perPage
* @returns unknown Json paginated set of `PersonalAccessTokenViewResource`
* @throws ApiError
*/
export const useUserTokenServiceUserTokenGetUserTokensInfinite = <TData = InfiniteData<Common.UserTokenServiceUserTokenGetUserTokensDefaultResponse>, TError = unknown, TQueryKey extends Array<unknown> = unknown[]>({ perPage, user }: {
  perPage?: number;
  user: string;
}, queryKey?: TQueryKey, options?: Omit<UseInfiniteQueryOptions<TData, TError>, "queryKey" | "queryFn">) => useInfiniteQuery({
  queryKey: Common.UseUserTokenServiceUserTokenGetUserTokensKeyFn({ perPage, user }, queryKey), queryFn: ({ pageParam }) => UserTokenService.userTokenGetUserTokens({ page: pageParam as number, perPage, user }) as TData, initialPageParam: "1", getNextPageParam: response => (response as {
    nextPage: number;
  }).nextPage, ...options
});
