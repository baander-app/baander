// generated with @7nohe/openapi-react-query-codegen@1.6.0 

import { useMutation, UseMutationOptions, useQuery, UseQueryOptions } from "@tanstack/react-query";
import { AlbumService, ArtistService, AuthService, GenreService, ImageService, JobService, LibraryService, ModelSchemaService, OpCacheService, SongService, UserService, WidgetSchemaService, WidgetService } from "../requests/services.gen";
import { CreateLibraryRequest, CreateUserRequest, ForgotPasswordRequest, LoginRequest, RegisterRequest, ResetPasswordRequest, UpdateLibraryRequest, UpdateUserRequest } from "../requests/types.gen";
import * as Common from "./common";
/**
* @param data The data for the request.
* @param data.library The library slug
* @param data.page
* @param data.perPage
* @param data.fields
* @param data.relations
* @param data.genres
* @returns unknown Json paginated set of `AlbumResource`
* @throws ApiError
*/
export const useAlbumServiceAlbumsIndex = <TData = Common.AlbumServiceAlbumsIndexDefaultResponse, TError = unknown, TQueryKey extends Array<unknown> = unknown[]>({ fields, genres, library, page, perPage, relations }: {
  fields?: string;
  genres?: string;
  library: string;
  page?: number;
  perPage?: number;
  relations?: string;
}, queryKey?: TQueryKey, options?: Omit<UseQueryOptions<TData, TError>, "queryKey" | "queryFn">) => useQuery<TData, TError>({ queryKey: Common.UseAlbumServiceAlbumsIndexKeyFn({ fields, genres, library, page, perPage, relations }, queryKey), queryFn: () => AlbumService.albumsIndex({ fields, genres, library, page, perPage, relations }) as TData, ...options });
/**
* @param data The data for the request.
* @param data.library The library slug
* @param data.album The album slug
* @returns AlbumResource `AlbumResource`
* @throws ApiError
*/
export const useAlbumServiceAlbumsShow = <TData = Common.AlbumServiceAlbumsShowDefaultResponse, TError = unknown, TQueryKey extends Array<unknown> = unknown[]>({ album, library }: {
  album: string;
  library: string;
}, queryKey?: TQueryKey, options?: Omit<UseQueryOptions<TData, TError>, "queryKey" | "queryFn">) => useQuery<TData, TError>({ queryKey: Common.UseAlbumServiceAlbumsShowKeyFn({ album, library }, queryKey), queryFn: () => AlbumService.albumsShow({ album, library }) as TData, ...options });
/**
* @param data The data for the request.
* @param data.library
* @returns unknown Json paginated set of `ArtistResource`
* @throws ApiError
*/
export const useArtistServiceArtistsIndex = <TData = Common.ArtistServiceArtistsIndexDefaultResponse, TError = unknown, TQueryKey extends Array<unknown> = unknown[]>({ library }: {
  library: string;
}, queryKey?: TQueryKey, options?: Omit<UseQueryOptions<TData, TError>, "queryKey" | "queryFn">) => useQuery<TData, TError>({ queryKey: Common.UseArtistServiceArtistsIndexKeyFn({ library }, queryKey), queryFn: () => ArtistService.artistsIndex({ library }) as TData, ...options });
/**
* @param data The data for the request.
* @param data.library
* @param data.artist The artist slug
* @returns ArtistResource `ArtistResource`
* @throws ApiError
*/
export const useArtistServiceArtistsShow = <TData = Common.ArtistServiceArtistsShowDefaultResponse, TError = unknown, TQueryKey extends Array<unknown> = unknown[]>({ artist, library }: {
  artist: string;
  library: string;
}, queryKey?: TQueryKey, options?: Omit<UseQueryOptions<TData, TError>, "queryKey" | "queryFn">) => useQuery<TData, TError>({ queryKey: Common.UseArtistServiceArtistsShowKeyFn({ artist, library }, queryKey), queryFn: () => ArtistService.artistsShow({ artist, library }) as TData, ...options });
/**
* @param data The data for the request.
* @param data.library
* @param data.page
* @param data.perPage
* @returns unknown Json paginated set of `GenreResource`
* @throws ApiError
*/
export const useGenreServiceGenresIndex = <TData = Common.GenreServiceGenresIndexDefaultResponse, TError = unknown, TQueryKey extends Array<unknown> = unknown[]>({ library, page, perPage }: {
  library: string;
  page?: number;
  perPage?: number;
}, queryKey?: TQueryKey, options?: Omit<UseQueryOptions<TData, TError>, "queryKey" | "queryFn">) => useQuery<TData, TError>({ queryKey: Common.UseGenreServiceGenresIndexKeyFn({ library, page, perPage }, queryKey), queryFn: () => GenreService.genresIndex({ library, page, perPage }) as TData, ...options });
/**
* Get image asset
* @param data The data for the request.
* @param data.image The image public id
* @returns string
* @throws ApiError
*/
export const useImageServiceImageServe = <TData = Common.ImageServiceImageServeDefaultResponse, TError = unknown, TQueryKey extends Array<unknown> = unknown[]>({ image }: {
  image: string;
}, queryKey?: TQueryKey, options?: Omit<UseQueryOptions<TData, TError>, "queryKey" | "queryFn">) => useQuery<TData, TError>({ queryKey: Common.UseImageServiceImageServeKeyFn({ image }, queryKey), queryFn: () => ImageService.imageServe({ image }) as TData, ...options });
/**
* @param data The data for the request.
* @param data.page
* @param data.perPage
* @returns unknown Json paginated set of `LibraryResource`
* @throws ApiError
*/
export const useLibraryServiceLibrariesIndex = <TData = Common.LibraryServiceLibrariesIndexDefaultResponse, TError = unknown, TQueryKey extends Array<unknown> = unknown[]>({ page, perPage }: {
  page?: number;
  perPage?: number;
} = {}, queryKey?: TQueryKey, options?: Omit<UseQueryOptions<TData, TError>, "queryKey" | "queryFn">) => useQuery<TData, TError>({ queryKey: Common.UseLibraryServiceLibrariesIndexKeyFn({ page, perPage }, queryKey), queryFn: () => LibraryService.librariesIndex({ page, perPage }) as TData, ...options });
/**
* @returns string
* @throws ApiError
*/
export const useModelSchemaServiceSchemasModel = <TData = Common.ModelSchemaServiceSchemasModelDefaultResponse, TError = unknown, TQueryKey extends Array<unknown> = unknown[]>(queryKey?: TQueryKey, options?: Omit<UseQueryOptions<TData, TError>, "queryKey" | "queryFn">) => useQuery<TData, TError>({ queryKey: Common.UseModelSchemaServiceSchemasModelKeyFn(queryKey), queryFn: () => ModelSchemaService.schemasModel() as TData, ...options });
/**
* @returns unknown
* @throws ApiError
*/
export const useOpCacheServiceOpCacheGetStatus = <TData = Common.OpCacheServiceOpCacheGetStatusDefaultResponse, TError = unknown, TQueryKey extends Array<unknown> = unknown[]>(queryKey?: TQueryKey, options?: Omit<UseQueryOptions<TData, TError>, "queryKey" | "queryFn">) => useQuery<TData, TError>({ queryKey: Common.UseOpCacheServiceOpCacheGetStatusKeyFn(queryKey), queryFn: () => OpCacheService.opCacheGetStatus() as TData, ...options });
/**
* @returns unknown
* @throws ApiError
*/
export const useOpCacheServiceOpcacheGetConfig = <TData = Common.OpCacheServiceOpcacheGetConfigDefaultResponse, TError = unknown, TQueryKey extends Array<unknown> = unknown[]>(queryKey?: TQueryKey, options?: Omit<UseQueryOptions<TData, TError>, "queryKey" | "queryFn">) => useQuery<TData, TError>({ queryKey: Common.UseOpCacheServiceOpcacheGetConfigKeyFn(queryKey), queryFn: () => OpCacheService.opcacheGetConfig() as TData, ...options });
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
export const useSongServiceSongsIndex = <TData = Common.SongServiceSongsIndexDefaultResponse, TError = unknown, TQueryKey extends Array<unknown> = unknown[]>({ albumArtist, albumId, genreIds, library, page, perPage, title }: {
  albumArtist?: string;
  albumId?: number;
  genreIds?: string;
  library: string;
  page?: number;
  perPage?: number;
  title?: string;
}, queryKey?: TQueryKey, options?: Omit<UseQueryOptions<TData, TError>, "queryKey" | "queryFn">) => useQuery<TData, TError>({ queryKey: Common.UseSongServiceSongsIndexKeyFn({ albumArtist, albumId, genreIds, library, page, perPage, title }, queryKey), queryFn: () => SongService.songsIndex({ albumArtist, albumId, genreIds, library, page, perPage, title }) as TData, ...options });
/**
* @param data The data for the request.
* @param data.library The library slug
* @param data.song The song public id
* @returns SongResource `SongResource`
* @throws ApiError
*/
export const useSongServiceSongsShow = <TData = Common.SongServiceSongsShowDefaultResponse, TError = unknown, TQueryKey extends Array<unknown> = unknown[]>({ library, song }: {
  library: string;
  song: string;
}, queryKey?: TQueryKey, options?: Omit<UseQueryOptions<TData, TError>, "queryKey" | "queryFn">) => useQuery<TData, TError>({ queryKey: Common.UseSongServiceSongsShowKeyFn({ library, song }, queryKey), queryFn: () => SongService.songsShow({ library, song }) as TData, ...options });
/**
* Direct stream the song
* Requires token with "access-stream"
* @param data The data for the request.
* @param data.library The library slug
* @param data.song The song public id
* @returns unknown
* @throws ApiError
*/
export const useSongServiceSongsStream = <TData = Common.SongServiceSongsStreamDefaultResponse, TError = unknown, TQueryKey extends Array<unknown> = unknown[]>({ library, song }: {
  library: string;
  song: string;
}, queryKey?: TQueryKey, options?: Omit<UseQueryOptions<TData, TError>, "queryKey" | "queryFn">) => useQuery<TData, TError>({ queryKey: Common.UseSongServiceSongsStreamKeyFn({ library, song }, queryKey), queryFn: () => SongService.songsStream({ library, song }) as TData, ...options });
/**
* Display a collection of users
* @returns unknown Json paginated set of `UserResource`
* @throws ApiError
*/
export const useUserServiceUsersIndex = <TData = Common.UserServiceUsersIndexDefaultResponse, TError = unknown, TQueryKey extends Array<unknown> = unknown[]>(queryKey?: TQueryKey, options?: Omit<UseQueryOptions<TData, TError>, "queryKey" | "queryFn">) => useQuery<TData, TError>({ queryKey: Common.UseUserServiceUsersIndexKeyFn(queryKey), queryFn: () => UserService.usersIndex() as TData, ...options });
/**
* Display a user
* @param data The data for the request.
* @param data.user The user ID
* @returns UserResource `UserResource`
* @throws ApiError
*/
export const useUserServiceUsersShow = <TData = Common.UserServiceUsersShowDefaultResponse, TError = unknown, TQueryKey extends Array<unknown> = unknown[]>({ user }: {
  user: number;
}, queryKey?: TQueryKey, options?: Omit<UseQueryOptions<TData, TError>, "queryKey" | "queryFn">) => useQuery<TData, TError>({ queryKey: Common.UseUserServiceUsersShowKeyFn({ user }, queryKey), queryFn: () => UserService.usersShow({ user }) as TData, ...options });
/**
* Get the authenticated user
* @returns UserResource `UserResource`
* @throws ApiError
*/
export const useUserServiceUsersMe = <TData = Common.UserServiceUsersMeDefaultResponse, TError = unknown, TQueryKey extends Array<unknown> = unknown[]>(queryKey?: TQueryKey, options?: Omit<UseQueryOptions<TData, TError>, "queryKey" | "queryFn">) => useQuery<TData, TError>({ queryKey: Common.UseUserServiceUsersMeKeyFn(queryKey), queryFn: () => UserService.usersMe() as TData, ...options });
/**
* Get a widget for the user
* @param data The data for the request.
* @param data.name
* @returns null
* @throws ApiError
*/
export const useWidgetServiceWidgetsGetWidget = <TData = Common.WidgetServiceWidgetsGetWidgetDefaultResponse, TError = unknown, TQueryKey extends Array<unknown> = unknown[]>({ name }: {
  name: string;
}, queryKey?: TQueryKey, options?: Omit<UseQueryOptions<TData, TError>, "queryKey" | "queryFn">) => useQuery<TData, TError>({ queryKey: Common.UseWidgetServiceWidgetsGetWidgetKeyFn({ name }, queryKey), queryFn: () => WidgetService.widgetsGetWidget({ name }) as TData, ...options });
/**
* Get a list of widgets
* @returns WidgetListItemResource Array of `WidgetListItemResource`
* @throws ApiError
*/
export const useWidgetSchemaServiceWidgetSchemaGetWidgets = <TData = Common.WidgetSchemaServiceWidgetSchemaGetWidgetsDefaultResponse, TError = unknown, TQueryKey extends Array<unknown> = unknown[]>(queryKey?: TQueryKey, options?: Omit<UseQueryOptions<TData, TError>, "queryKey" | "queryFn">) => useQuery<TData, TError>({ queryKey: Common.UseWidgetSchemaServiceWidgetSchemaGetWidgetsKeyFn(queryKey), queryFn: () => WidgetSchemaService.widgetSchemaGetWidgets() as TData, ...options });
/**
* Get widget schema
* @param data The data for the request.
* @param data.name Name of the schema
* @param data.id
* @returns string
* @throws ApiError
*/
export const useWidgetSchemaServiceWidgetSchemaGetWidget = <TData = Common.WidgetSchemaServiceWidgetSchemaGetWidgetDefaultResponse, TError = unknown, TQueryKey extends Array<unknown> = unknown[]>({ id, name }: {
  id: string;
  name: string;
}, queryKey?: TQueryKey, options?: Omit<UseQueryOptions<TData, TError>, "queryKey" | "queryFn">) => useQuery<TData, TError>({ queryKey: Common.UseWidgetSchemaServiceWidgetSchemaGetWidgetKeyFn({ id, name }, queryKey), queryFn: () => WidgetSchemaService.widgetSchemaGetWidget({ id, name }) as TData, ...options });
/**
* Login
* @param data The data for the request.
* @param data.requestBody
* @returns unknown
* @throws ApiError
*/
export const useAuthServiceAuthLogin = <TData = Common.AuthServiceAuthLoginMutationResult, TError = unknown, TContext = unknown>(options?: Omit<UseMutationOptions<TData, TError, {
  requestBody?: LoginRequest;
}, TContext>, "mutationFn">) => useMutation<TData, TError, {
  requestBody?: LoginRequest;
}, TContext>({ mutationFn: ({ requestBody }) => AuthService.authLogin({ requestBody }) as unknown as Promise<TData>, ...options });
/**
* Refresh token
* Needs refresh token with ability "issue-access-token"
* @param data The data for the request.
* @param data.requestBody
* @returns unknown
* @throws ApiError
*/
export const useAuthServiceAuthRefreshToken = <TData = Common.AuthServiceAuthRefreshTokenMutationResult, TError = unknown, TContext = unknown>(options?: Omit<UseMutationOptions<TData, TError, {
  requestBody?: { [key: string]: unknown; };
}, TContext>, "mutationFn">) => useMutation<TData, TError, {
  requestBody?: { [key: string]: unknown; };
}, TContext>({ mutationFn: ({ requestBody }) => AuthService.authRefreshToken({ requestBody }) as unknown as Promise<TData>, ...options });
/**
* Get a stream token
* Needs refresh token with ability "issue-access-token"
* @param data The data for the request.
* @param data.requestBody
* @returns unknown
* @throws ApiError
*/
export const useAuthServiceAuthStreamToken = <TData = Common.AuthServiceAuthStreamTokenMutationResult, TError = unknown, TContext = unknown>(options?: Omit<UseMutationOptions<TData, TError, {
  requestBody?: { [key: string]: unknown; };
}, TContext>, "mutationFn">) => useMutation<TData, TError, {
  requestBody?: { [key: string]: unknown; };
}, TContext>({ mutationFn: ({ requestBody }) => AuthService.authStreamToken({ requestBody }) as unknown as Promise<TData>, ...options });
/**
* Register
* @param data The data for the request.
* @param data.requestBody
* @returns unknown
* @throws ApiError
*/
export const useAuthServiceAuthRegister = <TData = Common.AuthServiceAuthRegisterMutationResult, TError = unknown, TContext = unknown>(options?: Omit<UseMutationOptions<TData, TError, {
  requestBody?: RegisterRequest;
}, TContext>, "mutationFn">) => useMutation<TData, TError, {
  requestBody?: RegisterRequest;
}, TContext>({ mutationFn: ({ requestBody }) => AuthService.authRegister({ requestBody }) as unknown as Promise<TData>, ...options });
/**
* Request reset password link
* @param data The data for the request.
* @param data.requestBody
* @returns unknown
* @throws ApiError
*/
export const useAuthServiceAuthForgotPassword = <TData = Common.AuthServiceAuthForgotPasswordMutationResult, TError = unknown, TContext = unknown>(options?: Omit<UseMutationOptions<TData, TError, {
  requestBody?: ForgotPasswordRequest;
}, TContext>, "mutationFn">) => useMutation<TData, TError, {
  requestBody?: ForgotPasswordRequest;
}, TContext>({ mutationFn: ({ requestBody }) => AuthService.authForgotPassword({ requestBody }) as unknown as Promise<TData>, ...options });
/**
* Reset password
* @param data The data for the request.
* @param data.requestBody
* @returns unknown
* @throws ApiError
*/
export const useAuthServiceAuthResetPassword = <TData = Common.AuthServiceAuthResetPasswordMutationResult, TError = unknown, TContext = unknown>(options?: Omit<UseMutationOptions<TData, TError, {
  requestBody?: ResetPasswordRequest;
}, TContext>, "mutationFn">) => useMutation<TData, TError, {
  requestBody?: ResetPasswordRequest;
}, TContext>({ mutationFn: ({ requestBody }) => AuthService.authResetPassword({ requestBody }) as unknown as Promise<TData>, ...options });
/**
* Verify email
* @param data The data for the request.
* @param data.requestBody
* @returns UserResource `UserResource`
* @throws ApiError
*/
export const useAuthServiceAuthVerify = <TData = Common.AuthServiceAuthVerifyMutationResult, TError = unknown, TContext = unknown>(options?: Omit<UseMutationOptions<TData, TError, {
  requestBody?: { [key: string]: unknown; };
}, TContext>, "mutationFn">) => useMutation<TData, TError, {
  requestBody?: { [key: string]: unknown; };
}, TContext>({ mutationFn: ({ requestBody }) => AuthService.authVerify({ requestBody }) as unknown as Promise<TData>, ...options });
/**
* Scan a library
* @param data The data for the request.
* @param data.slug
* @param data.requestBody
* @returns unknown
* @throws ApiError
*/
export const useJobServiceJobLibraryScan = <TData = Common.JobServiceJobLibraryScanMutationResult, TError = unknown, TContext = unknown>(options?: Omit<UseMutationOptions<TData, TError, {
  requestBody?: { [key: string]: unknown; };
  slug: string;
}, TContext>, "mutationFn">) => useMutation<TData, TError, {
  requestBody?: { [key: string]: unknown; };
  slug: string;
}, TContext>({ mutationFn: ({ requestBody, slug }) => JobService.jobLibraryScan({ requestBody, slug }) as unknown as Promise<TData>, ...options });
/**
* @param data The data for the request.
* @param data.requestBody
* @returns LibraryResource `LibraryResource`
* @throws ApiError
*/
export const useLibraryServiceLibraryCreate = <TData = Common.LibraryServiceLibraryCreateMutationResult, TError = unknown, TContext = unknown>(options?: Omit<UseMutationOptions<TData, TError, {
  requestBody?: CreateLibraryRequest;
}, TContext>, "mutationFn">) => useMutation<TData, TError, {
  requestBody?: CreateLibraryRequest;
}, TContext>({ mutationFn: ({ requestBody }) => LibraryService.libraryCreate({ requestBody }) as unknown as Promise<TData>, ...options });
/**
* @param data The data for the request.
* @param data.requestBody
* @returns unknown
* @throws ApiError
*/
export const useOpCacheServiceOpcacheClear = <TData = Common.OpCacheServiceOpcacheClearMutationResult, TError = unknown, TContext = unknown>(options?: Omit<UseMutationOptions<TData, TError, {
  requestBody?: { [key: string]: unknown; };
}, TContext>, "mutationFn">) => useMutation<TData, TError, {
  requestBody?: { [key: string]: unknown; };
}, TContext>({ mutationFn: ({ requestBody }) => OpCacheService.opcacheClear({ requestBody }) as unknown as Promise<TData>, ...options });
/**
* @param data The data for the request.
* @param data.force
* @param data.requestBody
* @returns unknown
* @throws ApiError
*/
export const useOpCacheServiceOpcacheCompile = <TData = Common.OpCacheServiceOpcacheCompileMutationResult, TError = unknown, TContext = unknown>(options?: Omit<UseMutationOptions<TData, TError, {
  force?: string;
  requestBody?: { [key: string]: unknown; };
}, TContext>, "mutationFn">) => useMutation<TData, TError, {
  force?: string;
  requestBody?: { [key: string]: unknown; };
}, TContext>({ mutationFn: ({ force, requestBody }) => OpCacheService.opcacheCompile({ force, requestBody }) as unknown as Promise<TData>, ...options });
/**
* Create user
* This is endpoint allows administrators to create users
* @param data The data for the request.
* @param data.requestBody
* @returns UserResource `UserResource`
* @throws ApiError
*/
export const useUserServiceUsersStore = <TData = Common.UserServiceUsersStoreMutationResult, TError = unknown, TContext = unknown>(options?: Omit<UseMutationOptions<TData, TError, {
  requestBody?: CreateUserRequest;
}, TContext>, "mutationFn">) => useMutation<TData, TError, {
  requestBody?: CreateUserRequest;
}, TContext>({ mutationFn: ({ requestBody }) => UserService.usersStore({ requestBody }) as unknown as Promise<TData>, ...options });
/**
* @param data The data for the request.
* @param data.requestBody
* @returns LibraryResource `LibraryResource`
* @throws ApiError
*/
export const useLibraryServiceLibraryUpdate = <TData = Common.LibraryServiceLibraryUpdateMutationResult, TError = unknown, TContext = unknown>(options?: Omit<UseMutationOptions<TData, TError, {
  requestBody?: UpdateLibraryRequest;
}, TContext>, "mutationFn">) => useMutation<TData, TError, {
  requestBody?: UpdateLibraryRequest;
}, TContext>({ mutationFn: ({ requestBody }) => LibraryService.libraryUpdate({ requestBody }) as unknown as Promise<TData>, ...options });
/**
* Update user details
* @param data The data for the request.
* @param data.user The user ID
* @param data.requestBody
* @returns UserResource `UserResource`
* @throws ApiError
*/
export const useUserServiceUsersUpdate = <TData = Common.UserServiceUsersUpdateMutationResult, TError = unknown, TContext = unknown>(options?: Omit<UseMutationOptions<TData, TError, {
  requestBody?: UpdateUserRequest;
  user: number;
}, TContext>, "mutationFn">) => useMutation<TData, TError, {
  requestBody?: UpdateUserRequest;
  user: number;
}, TContext>({ mutationFn: ({ requestBody, user }) => UserService.usersUpdate({ requestBody, user }) as unknown as Promise<TData>, ...options });
/**
* @returns null No content
* @throws ApiError
*/
export const useLibraryServiceLibraryDelete = <TData = Common.LibraryServiceLibraryDeleteMutationResult, TError = unknown, TContext = unknown>(options?: Omit<UseMutationOptions<TData, TError, void, TContext>, "mutationFn">) => useMutation<TData, TError, void, TContext>({ mutationFn: () => LibraryService.libraryDelete() as unknown as Promise<TData>, ...options });
/**
* Delete a user
* @param data The data for the request.
* @param data.user
* @returns null No content
* @throws ApiError
*/
export const useUserServiceUsersDestroy = <TData = Common.UserServiceUsersDestroyMutationResult, TError = unknown, TContext = unknown>(options?: Omit<UseMutationOptions<TData, TError, {
  user: string;
}, TContext>, "mutationFn">) => useMutation<TData, TError, {
  user: string;
}, TContext>({ mutationFn: ({ user }) => UserService.usersDestroy({ user }) as unknown as Promise<TData>, ...options });
