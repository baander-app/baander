// generated with @7nohe/openapi-react-query-codegen@1.6.0 

import { UseQueryOptions, useSuspenseQuery } from "@tanstack/react-query";
import { AlbumService, ArtistService, GenreService, ImageService, LibraryService, ModelSchemaService, OpCacheService, SongService, UserService, UserTokenService, WidgetSchemaService, WidgetService } from "../requests/services.gen";
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
* @param data.perPage Items per page
* @param data.genres _Extension_ Comma seperated list of genres
* @returns unknown Json paginated set of `AlbumResourceResource`
* @throws ApiError
*/
export const useAlbumServiceAlbumsIndexSuspense = <TData = Common.AlbumServiceAlbumsIndexDefaultResponse, TError = unknown, TQueryKey extends Array<unknown> = unknown[]>({ fields, genres, library, page, perPage, relations }: {
  fields?: string;
  genres?: string;
  library: string;
  page?: number;
  perPage?: number;
  relations?: string;
}, queryKey?: TQueryKey, options?: Omit<UseQueryOptions<TData, TError>, "queryKey" | "queryFn">) => useSuspenseQuery<TData, TError>({ queryKey: Common.UseAlbumServiceAlbumsIndexKeyFn({ fields, genres, library, page, perPage, relations }, queryKey), queryFn: () => AlbumService.albumsIndex({ fields, genres, library, page, perPage, relations }) as TData, ...options });
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
* @param data.perPage Items per page
* @param data.genres _Extension_ Comma seperated list of genres
* @returns unknown Json paginated set of `ArtistResource`
* @throws ApiError
*/
export const useArtistServiceArtistsIndexSuspense = <TData = Common.ArtistServiceArtistsIndexDefaultResponse, TError = unknown, TQueryKey extends Array<unknown> = unknown[]>({ fields, genres, library, page, perPage, relations }: {
  fields?: string;
  genres?: string;
  library: string;
  page?: number;
  perPage?: number;
  relations?: string;
}, queryKey?: TQueryKey, options?: Omit<UseQueryOptions<TData, TError>, "queryKey" | "queryFn">) => useSuspenseQuery<TData, TError>({ queryKey: Common.UseArtistServiceArtistsIndexKeyFn({ fields, genres, library, page, perPage, relations }, queryKey), queryFn: () => ArtistService.artistsIndex({ fields, genres, library, page, perPage, relations }) as TData, ...options });
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
* @param data.page
* @param data.perPage
* @returns GenreResource Array of `GenreResource`
* @throws ApiError
*/
export const useGenreServiceGenresIndexSuspense = <TData = Common.GenreServiceGenresIndexDefaultResponse, TError = unknown, TQueryKey extends Array<unknown> = unknown[]>({ fields, page, perPage, relations }: {
  fields?: string;
  page?: number;
  perPage?: number;
  relations?: string;
} = {}, queryKey?: TQueryKey, options?: Omit<UseQueryOptions<TData, TError>, "queryKey" | "queryFn">) => useSuspenseQuery<TData, TError>({ queryKey: Common.UseGenreServiceGenresIndexKeyFn({ fields, page, perPage, relations }, queryKey), queryFn: () => GenreService.genresIndex({ fields, page, perPage, relations }) as TData, ...options });
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
* @param data.page
* @param data.perPage
* @returns unknown Json paginated set of `LibraryResource`
* @throws ApiError
*/
export const useLibraryServiceLibrariesIndexSuspense = <TData = Common.LibraryServiceLibrariesIndexDefaultResponse, TError = unknown, TQueryKey extends Array<unknown> = unknown[]>({ page, perPage }: {
  page?: number;
  perPage?: number;
} = {}, queryKey?: TQueryKey, options?: Omit<UseQueryOptions<TData, TError>, "queryKey" | "queryFn">) => useSuspenseQuery<TData, TError>({ queryKey: Common.UseLibraryServiceLibrariesIndexKeyFn({ page, perPage }, queryKey), queryFn: () => LibraryService.librariesIndex({ page, perPage }) as TData, ...options });
/**
* @returns string
* @throws ApiError
*/
export const useModelSchemaServiceSchemasModelSuspense = <TData = Common.ModelSchemaServiceSchemasModelDefaultResponse, TError = unknown, TQueryKey extends Array<unknown> = unknown[]>(queryKey?: TQueryKey, options?: Omit<UseQueryOptions<TData, TError>, "queryKey" | "queryFn">) => useSuspenseQuery<TData, TError>({ queryKey: Common.UseModelSchemaServiceSchemasModelKeyFn(queryKey), queryFn: () => ModelSchemaService.schemasModel() as TData, ...options });
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
* Get a collection of songs
* @param data The data for the request.
* @param data.library The library slug
* @param data.albumArtist
* @param data.genreIds
* @param data.title
* @param data.albumId
* @param data.page
* @param data.perPage
* @returns unknown Json paginated set of `SongWithAlbumResource`
* @throws ApiError
*/
export const useSongServiceSongsIndexSuspense = <TData = Common.SongServiceSongsIndexDefaultResponse, TError = unknown, TQueryKey extends Array<unknown> = unknown[]>({ albumArtist, albumId, genreIds, library, page, perPage, title }: {
  albumArtist?: string;
  albumId?: number;
  genreIds?: string;
  library: string;
  page?: number;
  perPage?: number;
  title?: string;
}, queryKey?: TQueryKey, options?: Omit<UseQueryOptions<TData, TError>, "queryKey" | "queryFn">) => useSuspenseQuery<TData, TError>({ queryKey: Common.UseSongServiceSongsIndexKeyFn({ albumArtist, albumId, genreIds, library, page, perPage, title }, queryKey), queryFn: () => SongService.songsIndex({ albumArtist, albumId, genreIds, library, page, perPage, title }) as TData, ...options });
/**
* Get a song
* @param data The data for the request.
* @param data.library The library slug
* @param data.song The song public id
* @returns SongWithAlbumResource `SongWithAlbumResource`
* @throws ApiError
*/
export const useSongServiceSongsShowSuspense = <TData = Common.SongServiceSongsShowDefaultResponse, TError = unknown, TQueryKey extends Array<unknown> = unknown[]>({ library, song }: {
  library: string;
  song: string;
}, queryKey?: TQueryKey, options?: Omit<UseQueryOptions<TData, TError>, "queryKey" | "queryFn">) => useSuspenseQuery<TData, TError>({ queryKey: Common.UseSongServiceSongsShowKeyFn({ library, song }, queryKey), queryFn: () => SongService.songsShow({ library, song }) as TData, ...options });
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
* Get a collection of users
* @returns unknown Json paginated set of `UserResource`
* @throws ApiError
*/
export const useUserServiceUsersIndexSuspense = <TData = Common.UserServiceUsersIndexDefaultResponse, TError = unknown, TQueryKey extends Array<unknown> = unknown[]>(queryKey?: TQueryKey, options?: Omit<UseQueryOptions<TData, TError>, "queryKey" | "queryFn">) => useSuspenseQuery<TData, TError>({ queryKey: Common.UseUserServiceUsersIndexKeyFn(queryKey), queryFn: () => UserService.usersIndex() as TData, ...options });
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
/**
* Get a widget for the user
* @param data The data for the request.
* @param data.name
* @returns null
* @throws ApiError
*/
export const useWidgetServiceWidgetsGetWidgetSuspense = <TData = Common.WidgetServiceWidgetsGetWidgetDefaultResponse, TError = unknown, TQueryKey extends Array<unknown> = unknown[]>({ name }: {
  name: string;
}, queryKey?: TQueryKey, options?: Omit<UseQueryOptions<TData, TError>, "queryKey" | "queryFn">) => useSuspenseQuery<TData, TError>({ queryKey: Common.UseWidgetServiceWidgetsGetWidgetKeyFn({ name }, queryKey), queryFn: () => WidgetService.widgetsGetWidget({ name }) as TData, ...options });
/**
* Get a list of widgets
* @returns WidgetListItemResource Array of `WidgetListItemResource`
* @throws ApiError
*/
export const useWidgetSchemaServiceWidgetSchemaGetWidgetsSuspense = <TData = Common.WidgetSchemaServiceWidgetSchemaGetWidgetsDefaultResponse, TError = unknown, TQueryKey extends Array<unknown> = unknown[]>(queryKey?: TQueryKey, options?: Omit<UseQueryOptions<TData, TError>, "queryKey" | "queryFn">) => useSuspenseQuery<TData, TError>({ queryKey: Common.UseWidgetSchemaServiceWidgetSchemaGetWidgetsKeyFn(queryKey), queryFn: () => WidgetSchemaService.widgetSchemaGetWidgets() as TData, ...options });
/**
* Get widget schema
* @param data The data for the request.
* @param data.name Name of the schema
* @param data.id
* @returns string
* @throws ApiError
*/
export const useWidgetSchemaServiceWidgetSchemaGetWidgetSuspense = <TData = Common.WidgetSchemaServiceWidgetSchemaGetWidgetDefaultResponse, TError = unknown, TQueryKey extends Array<unknown> = unknown[]>({ id, name }: {
  id: string;
  name: string;
}, queryKey?: TQueryKey, options?: Omit<UseQueryOptions<TData, TError>, "queryKey" | "queryFn">) => useSuspenseQuery<TData, TError>({ queryKey: Common.UseWidgetSchemaServiceWidgetSchemaGetWidgetKeyFn({ id, name }, queryKey), queryFn: () => WidgetSchemaService.widgetSchemaGetWidget({ id, name }) as TData, ...options });
