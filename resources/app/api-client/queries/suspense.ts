// generated with @7nohe/openapi-react-query-codegen@1.6.1 

import { UseQueryOptions, useSuspenseQuery } from "@tanstack/react-query";
import { AlbumService, ArtistService, GenreService, ImageService, LibraryService, OpCacheService, QueueService, SongService, SystemInfoService, UserService, UserTokenService } from "../requests/services.gen";
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
* @returns unknown Json paginated set of `AlbumResourceResource`
* @throws ApiError
*/
export const useAlbumServiceAlbumsIndexSuspense = <TData = Common.AlbumServiceAlbumsIndexDefaultResponse, TError = unknown, TQueryKey extends Array<unknown> = unknown[]>({ fields, genres, library, limit, page, relations }: {
  fields?: string;
  genres?: string;
  library: string;
  limit?: number;
  page?: number;
  relations?: string;
}, queryKey?: TQueryKey, options?: Omit<UseQueryOptions<TData, TError>, "queryKey" | "queryFn">) => useSuspenseQuery<TData, TError>({ queryKey: Common.UseAlbumServiceAlbumsIndexKeyFn({ fields, genres, library, limit, page, relations }, queryKey), queryFn: () => AlbumService.albumsIndex({ fields, genres, library, limit, page, relations }) as TData, ...options });
/**
* Get an album
* @param data The data for the request.
* @param data.library The library slug
* @param data.album The album slug
* @returns AlbumResourceResource `AlbumResourceResource`
* @throws ApiError
*/
export const useAlbumServiceAlbumsShowSuspense = <TData = Common.AlbumServiceAlbumsShowDefaultResponse, TError = unknown, TQueryKey extends Array<unknown> = unknown[]>({ album, library }: {
  album: string;
  library: string;
}, queryKey?: TQueryKey, options?: Omit<UseQueryOptions<TData, TError>, "queryKey" | "queryFn">) => useSuspenseQuery<TData, TError>({ queryKey: Common.UseAlbumServiceAlbumsShowKeyFn({ album, library }, queryKey), queryFn: () => AlbumService.albumsShow({ album, library }) as TData, ...options });
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
export const useArtistServiceArtistsIndexSuspense = <TData = Common.ArtistServiceArtistsIndexDefaultResponse, TError = unknown, TQueryKey extends Array<unknown> = unknown[]>({ fields, genres, library, limit, page, relations }: {
  fields?: string;
  genres?: string;
  library: string;
  limit?: number;
  page?: number;
  relations?: string;
}, queryKey?: TQueryKey, options?: Omit<UseQueryOptions<TData, TError>, "queryKey" | "queryFn">) => useSuspenseQuery<TData, TError>({ queryKey: Common.UseArtistServiceArtistsIndexKeyFn({ fields, genres, library, limit, page, relations }, queryKey), queryFn: () => ArtistService.artistsIndex({ fields, genres, library, limit, page, relations }) as TData, ...options });
/**
* Get an artist
* @param data The data for the request.
* @param data.library
* @param data.artist The artist slug
* @returns ArtistResource `ArtistResource`
* @throws ApiError
*/
export const useArtistServiceArtistsShowSuspense = <TData = Common.ArtistServiceArtistsShowDefaultResponse, TError = unknown, TQueryKey extends Array<unknown> = unknown[]>({ artist, library }: {
  artist: string;
  library: string;
}, queryKey?: TQueryKey, options?: Omit<UseQueryOptions<TData, TError>, "queryKey" | "queryFn">) => useSuspenseQuery<TData, TError>({ queryKey: Common.UseArtistServiceArtistsShowKeyFn({ artist, library }, queryKey), queryFn: () => ArtistService.artistsShow({ artist, library }) as TData, ...options });
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
export const useGenreServiceGenresIndexSuspense = <TData = Common.GenreServiceGenresIndexDefaultResponse, TError = unknown, TQueryKey extends Array<unknown> = unknown[]>({ fields, librarySlug, limit, page, relations }: {
  fields?: string;
  librarySlug?: string;
  limit?: number;
  page?: number;
  relations?: string;
} = {}, queryKey?: TQueryKey, options?: Omit<UseQueryOptions<TData, TError>, "queryKey" | "queryFn">) => useSuspenseQuery<TData, TError>({ queryKey: Common.UseGenreServiceGenresIndexKeyFn({ fields, librarySlug, limit, page, relations }, queryKey), queryFn: () => GenreService.genresIndex({ fields, librarySlug, limit, page, relations }) as TData, ...options });
/**
* Get a genre
* @param data The data for the request.
* @param data.genre The genre slug
* @returns GenreResource `GenreResource`
* @throws ApiError
*/
export const useGenreServiceGenresShowSuspense = <TData = Common.GenreServiceGenresShowDefaultResponse, TError = unknown, TQueryKey extends Array<unknown> = unknown[]>({ genre }: {
  genre: string;
}, queryKey?: TQueryKey, options?: Omit<UseQueryOptions<TData, TError>, "queryKey" | "queryFn">) => useSuspenseQuery<TData, TError>({ queryKey: Common.UseGenreServiceGenresShowKeyFn({ genre }, queryKey), queryFn: () => GenreService.genresShow({ genre }) as TData, ...options });
/**
* Get an image asset
* @param data The data for the request.
* @param data.image The image public id
* @returns string
* @throws ApiError
*/
export const useImageServiceImageServeSuspense = <TData = Common.ImageServiceImageServeDefaultResponse, TError = unknown, TQueryKey extends Array<unknown> = unknown[]>({ image }: {
  image: string;
}, queryKey?: TQueryKey, options?: Omit<UseQueryOptions<TData, TError>, "queryKey" | "queryFn">) => useSuspenseQuery<TData, TError>({ queryKey: Common.UseImageServiceImageServeKeyFn({ image }, queryKey), queryFn: () => ImageService.imageServe({ image }) as TData, ...options });
/**
* Get a collection of media libraries
* @param data The data for the request.
* @param data.page Current page
* @param data.limit Items per page
* @returns unknown Json paginated set of `LibraryResource`
* @throws ApiError
*/
export const useLibraryServiceLibrariesIndexSuspense = <TData = Common.LibraryServiceLibrariesIndexDefaultResponse, TError = unknown, TQueryKey extends Array<unknown> = unknown[]>({ limit, page }: {
  limit?: number;
  page?: number;
} = {}, queryKey?: TQueryKey, options?: Omit<UseQueryOptions<TData, TError>, "queryKey" | "queryFn">) => useSuspenseQuery<TData, TError>({ queryKey: Common.UseLibraryServiceLibrariesIndexKeyFn({ limit, page }, queryKey), queryFn: () => LibraryService.librariesIndex({ limit, page }) as TData, ...options });
/**
* Get status
* @returns unknown
* @throws ApiError
*/
export const useOpCacheServiceOpCacheGetStatusSuspense = <TData = Common.OpCacheServiceOpCacheGetStatusDefaultResponse, TError = unknown, TQueryKey extends Array<unknown> = unknown[]>(queryKey?: TQueryKey, options?: Omit<UseQueryOptions<TData, TError>, "queryKey" | "queryFn">) => useSuspenseQuery<TData, TError>({ queryKey: Common.UseOpCacheServiceOpCacheGetStatusKeyFn(queryKey), queryFn: () => OpCacheService.opCacheGetStatus() as TData, ...options });
/**
* Get config
* @returns unknown
* @throws ApiError
*/
export const useOpCacheServiceOpcacheGetConfigSuspense = <TData = Common.OpCacheServiceOpcacheGetConfigDefaultResponse, TError = unknown, TQueryKey extends Array<unknown> = unknown[]>(queryKey?: TQueryKey, options?: Omit<UseQueryOptions<TData, TError>, "queryKey" | "queryFn">) => useSuspenseQuery<TData, TError>({ queryKey: Common.UseOpCacheServiceOpcacheGetConfigKeyFn(queryKey), queryFn: () => OpCacheService.opcacheGetConfig() as TData, ...options });
/**
* Get a collection of monitor entries
* ⚠️Cannot generate request documentation: Class "romanzipp\QueueMonitor\Enums\MonitorStatus" not found
* @returns unknown Json paginated set of `QueueMonitorResource`
* @throws ApiError
*/
export const useQueueServiceQueueMetricsShowSuspense = <TData = Common.QueueServiceQueueMetricsShowDefaultResponse, TError = unknown, TQueryKey extends Array<unknown> = unknown[]>(queryKey?: TQueryKey, options?: Omit<UseQueryOptions<TData, TError>, "queryKey" | "queryFn">) => useSuspenseQuery<TData, TError>({ queryKey: Common.UseQueueServiceQueueMetricsShowKeyFn(queryKey), queryFn: () => QueueService.queueMetricsShow() as TData, ...options });
/**
* Get a list of queue names
* @returns unknown
* @throws ApiError
*/
export const useQueueServiceQueueMetricsQueuesSuspense = <TData = Common.QueueServiceQueueMetricsQueuesDefaultResponse, TError = unknown, TQueryKey extends Array<unknown> = unknown[]>(queryKey?: TQueryKey, options?: Omit<UseQueryOptions<TData, TError>, "queryKey" | "queryFn">) => useSuspenseQuery<TData, TError>({ queryKey: Common.UseQueueServiceQueueMetricsQueuesKeyFn(queryKey), queryFn: () => QueueService.queueMetricsQueues() as TData, ...options });
/**
* Get a metrics collection
* @param data The data for the request.
* @param data.aggregateDays Days to aggregate
* @returns unknown
* @throws ApiError
*/
export const useQueueServiceQueueMetricsMetricsSuspense = <TData = Common.QueueServiceQueueMetricsMetricsDefaultResponse, TError = unknown, TQueryKey extends Array<unknown> = unknown[]>({ aggregateDays }: {
  aggregateDays?: number;
} = {}, queryKey?: TQueryKey, options?: Omit<UseQueryOptions<TData, TError>, "queryKey" | "queryFn">) => useSuspenseQuery<TData, TError>({ queryKey: Common.UseQueueServiceQueueMetricsMetricsKeyFn({ aggregateDays }, queryKey), queryFn: () => QueueService.queueMetricsMetrics({ aggregateDays }) as TData, ...options });
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
export const useSongServiceSongsIndexSuspense = <TData = Common.SongServiceSongsIndexDefaultResponse, TError = unknown, TQueryKey extends Array<unknown> = unknown[]>({ genreNames, genreSlugs, library, limit, page, relations }: {
  genreNames?: string;
  genreSlugs?: string;
  library: string;
  limit?: number;
  page?: number;
  relations?: string;
}, queryKey?: TQueryKey, options?: Omit<UseQueryOptions<TData, TError>, "queryKey" | "queryFn">) => useSuspenseQuery<TData, TError>({ queryKey: Common.UseSongServiceSongsIndexKeyFn({ genreNames, genreSlugs, library, limit, page, relations }, queryKey), queryFn: () => SongService.songsIndex({ genreNames, genreSlugs, library, limit, page, relations }) as TData, ...options });
/**
* Get a song by public id
* @param data The data for the request.
* @param data.library The library slug
* @param data.publicId
* @param data.relations Comma seperated string of relations
* - album
* - artists
* - albumArtist
* - genres
* @returns SongResource `SongResource`
* @throws ApiError
*/
export const useSongServiceSongsShowSuspense = <TData = Common.SongServiceSongsShowDefaultResponse, TError = unknown, TQueryKey extends Array<unknown> = unknown[]>({ library, publicId, relations }: {
  library: string;
  publicId: string;
  relations?: string;
}, queryKey?: TQueryKey, options?: Omit<UseQueryOptions<TData, TError>, "queryKey" | "queryFn">) => useSuspenseQuery<TData, TError>({ queryKey: Common.UseSongServiceSongsShowKeyFn({ library, publicId, relations }, queryKey), queryFn: () => SongService.songsShow({ library, publicId, relations }) as TData, ...options });
/**
* Direct stream the song
* Requires token with "access-stream"
* @param data The data for the request.
* @param data.library The library slug
* @param data.song The song public id
* @returns unknown
* @throws ApiError
*/
export const useSongServiceSongsStreamSuspense = <TData = Common.SongServiceSongsStreamDefaultResponse, TError = unknown, TQueryKey extends Array<unknown> = unknown[]>({ library, song }: {
  library: string;
  song: string;
}, queryKey?: TQueryKey, options?: Omit<UseQueryOptions<TData, TError>, "queryKey" | "queryFn">) => useSuspenseQuery<TData, TError>({ queryKey: Common.UseSongServiceSongsStreamKeyFn({ library, song }, queryKey), queryFn: () => SongService.songsStream({ library, song }) as TData, ...options });
/**
* @returns unknown
* @throws ApiError
*/
export const useSystemInfoServiceSystemInfoShowSuspense = <TData = Common.SystemInfoServiceSystemInfoShowDefaultResponse, TError = unknown, TQueryKey extends Array<unknown> = unknown[]>(queryKey?: TQueryKey, options?: Omit<UseQueryOptions<TData, TError>, "queryKey" | "queryFn">) => useSuspenseQuery<TData, TError>({ queryKey: Common.UseSystemInfoServiceSystemInfoShowKeyFn(queryKey), queryFn: () => SystemInfoService.systemInfoShow() as TData, ...options });
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
export const useUserServiceUsersIndexSuspense = <TData = Common.UserServiceUsersIndexDefaultResponse, TError = unknown, TQueryKey extends Array<unknown> = unknown[]>({ filterModes, filters, globalFilter, limit, page, sorting }: {
  filterModes?: string;
  filters?: string;
  globalFilter?: string;
  limit?: number;
  page?: number;
  sorting?: string;
} = {}, queryKey?: TQueryKey, options?: Omit<UseQueryOptions<TData, TError>, "queryKey" | "queryFn">) => useSuspenseQuery<TData, TError>({ queryKey: Common.UseUserServiceUsersIndexKeyFn({ filterModes, filters, globalFilter, limit, page, sorting }, queryKey), queryFn: () => UserService.usersIndex({ filterModes, filters, globalFilter, limit, page, sorting }) as TData, ...options });
/**
* Get small user detail info
* @param data The data for the request.
* @param data.user The user ID
* @returns UserResource `UserResource`
* @throws ApiError
*/
export const useUserServiceUsersShowSuspense = <TData = Common.UserServiceUsersShowDefaultResponse, TError = unknown, TQueryKey extends Array<unknown> = unknown[]>({ user }: {
  user: number;
}, queryKey?: TQueryKey, options?: Omit<UseQueryOptions<TData, TError>, "queryKey" | "queryFn">) => useSuspenseQuery<TData, TError>({ queryKey: Common.UseUserServiceUsersShowKeyFn({ user }, queryKey), queryFn: () => UserService.usersShow({ user }) as TData, ...options });
/**
* Get the authenticated user
* @returns UserResource `UserResource`
* @throws ApiError
*/
export const useUserServiceUsersMeSuspense = <TData = Common.UserServiceUsersMeDefaultResponse, TError = unknown, TQueryKey extends Array<unknown> = unknown[]>(queryKey?: TQueryKey, options?: Omit<UseQueryOptions<TData, TError>, "queryKey" | "queryFn">) => useSuspenseQuery<TData, TError>({ queryKey: Common.UseUserServiceUsersMeKeyFn(queryKey), queryFn: () => UserService.usersMe() as TData, ...options });
/**
* Get a collection of tokens
* @param data The data for the request.
* @param data.user
* @param data.page
* @param data.perPage
* @returns unknown Json paginated set of `PersonalAccessTokenViewResource`
* @throws ApiError
*/
export const useUserTokenServiceUserTokenGetUserTokensSuspense = <TData = Common.UserTokenServiceUserTokenGetUserTokensDefaultResponse, TError = unknown, TQueryKey extends Array<unknown> = unknown[]>({ page, perPage, user }: {
  page?: number;
  perPage?: number;
  user: string;
}, queryKey?: TQueryKey, options?: Omit<UseQueryOptions<TData, TError>, "queryKey" | "queryFn">) => useSuspenseQuery<TData, TError>({ queryKey: Common.UseUserTokenServiceUserTokenGetUserTokensKeyFn({ page, perPage, user }, queryKey), queryFn: () => UserTokenService.userTokenGetUserTokens({ page, perPage, user }) as TData, ...options });
