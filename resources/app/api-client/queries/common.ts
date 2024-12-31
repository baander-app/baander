// generated with @7nohe/openapi-react-query-codegen@1.6.0 

import { UseQueryResult } from "@tanstack/react-query";
import { AlbumService, ArtistService, AuthService, GenreService, ImageService, JobService, LibraryService, ModelSchemaService, OpCacheService, SongService, UserService, UserTokenService, WidgetSchemaService, WidgetService } from "../requests/services.gen";
export type AlbumServiceAlbumsIndexDefaultResponse = Awaited<ReturnType<typeof AlbumService.albumsIndex>>;
export type AlbumServiceAlbumsIndexQueryResult<TData = AlbumServiceAlbumsIndexDefaultResponse, TError = unknown> = UseQueryResult<TData, TError>;
export const useAlbumServiceAlbumsIndexKey = "AlbumServiceAlbumsIndex";
export const UseAlbumServiceAlbumsIndexKeyFn = ({ fields, genres, library, page, perPage, relations }: {
  fields?: string;
  genres?: string;
  library: string;
  page?: number;
  perPage?: number;
  relations?: string;
}, queryKey?: Array<unknown>) => [useAlbumServiceAlbumsIndexKey, ...(queryKey ?? [{ fields, genres, library, page, perPage, relations }])];
export type AlbumServiceAlbumsShowDefaultResponse = Awaited<ReturnType<typeof AlbumService.albumsShow>>;
export type AlbumServiceAlbumsShowQueryResult<TData = AlbumServiceAlbumsShowDefaultResponse, TError = unknown> = UseQueryResult<TData, TError>;
export const useAlbumServiceAlbumsShowKey = "AlbumServiceAlbumsShow";
export const UseAlbumServiceAlbumsShowKeyFn = ({ album, library }: {
  album: string;
  library: string;
}, queryKey?: Array<unknown>) => [useAlbumServiceAlbumsShowKey, ...(queryKey ?? [{ album, library }])];
export type ArtistServiceArtistsIndexDefaultResponse = Awaited<ReturnType<typeof ArtistService.artistsIndex>>;
export type ArtistServiceArtistsIndexQueryResult<TData = ArtistServiceArtistsIndexDefaultResponse, TError = unknown> = UseQueryResult<TData, TError>;
export const useArtistServiceArtistsIndexKey = "ArtistServiceArtistsIndex";
export const UseArtistServiceArtistsIndexKeyFn = ({ fields, genres, library, page, perPage, relations }: {
  fields?: string;
  genres?: string;
  library: string;
  page?: number;
  perPage?: number;
  relations?: string;
}, queryKey?: Array<unknown>) => [useArtistServiceArtistsIndexKey, ...(queryKey ?? [{ fields, genres, library, page, perPage, relations }])];
export type ArtistServiceArtistsShowDefaultResponse = Awaited<ReturnType<typeof ArtistService.artistsShow>>;
export type ArtistServiceArtistsShowQueryResult<TData = ArtistServiceArtistsShowDefaultResponse, TError = unknown> = UseQueryResult<TData, TError>;
export const useArtistServiceArtistsShowKey = "ArtistServiceArtistsShow";
export const UseArtistServiceArtistsShowKeyFn = ({ artist, library }: {
  artist: string;
  library: string;
}, queryKey?: Array<unknown>) => [useArtistServiceArtistsShowKey, ...(queryKey ?? [{ artist, library }])];
export type GenreServiceGenresIndexDefaultResponse = Awaited<ReturnType<typeof GenreService.genresIndex>>;
export type GenreServiceGenresIndexQueryResult<TData = GenreServiceGenresIndexDefaultResponse, TError = unknown> = UseQueryResult<TData, TError>;
export const useGenreServiceGenresIndexKey = "GenreServiceGenresIndex";
export const UseGenreServiceGenresIndexKeyFn = ({ fields, page, perPage, relations }: {
  fields?: string;
  page?: number;
  perPage?: number;
  relations?: string;
} = {}, queryKey?: Array<unknown>) => [useGenreServiceGenresIndexKey, ...(queryKey ?? [{ fields, page, perPage, relations }])];
export type GenreServiceGenresShowDefaultResponse = Awaited<ReturnType<typeof GenreService.genresShow>>;
export type GenreServiceGenresShowQueryResult<TData = GenreServiceGenresShowDefaultResponse, TError = unknown> = UseQueryResult<TData, TError>;
export const useGenreServiceGenresShowKey = "GenreServiceGenresShow";
export const UseGenreServiceGenresShowKeyFn = ({ genre }: {
  genre: string;
}, queryKey?: Array<unknown>) => [useGenreServiceGenresShowKey, ...(queryKey ?? [{ genre }])];
export type ImageServiceImageServeDefaultResponse = Awaited<ReturnType<typeof ImageService.imageServe>>;
export type ImageServiceImageServeQueryResult<TData = ImageServiceImageServeDefaultResponse, TError = unknown> = UseQueryResult<TData, TError>;
export const useImageServiceImageServeKey = "ImageServiceImageServe";
export const UseImageServiceImageServeKeyFn = ({ image }: {
  image: string;
}, queryKey?: Array<unknown>) => [useImageServiceImageServeKey, ...(queryKey ?? [{ image }])];
export type LibraryServiceLibrariesIndexDefaultResponse = Awaited<ReturnType<typeof LibraryService.librariesIndex>>;
export type LibraryServiceLibrariesIndexQueryResult<TData = LibraryServiceLibrariesIndexDefaultResponse, TError = unknown> = UseQueryResult<TData, TError>;
export const useLibraryServiceLibrariesIndexKey = "LibraryServiceLibrariesIndex";
export const UseLibraryServiceLibrariesIndexKeyFn = ({ page, perPage }: {
  page?: number;
  perPage?: number;
} = {}, queryKey?: Array<unknown>) => [useLibraryServiceLibrariesIndexKey, ...(queryKey ?? [{ page, perPage }])];
export type ModelSchemaServiceSchemasModelDefaultResponse = Awaited<ReturnType<typeof ModelSchemaService.schemasModel>>;
export type ModelSchemaServiceSchemasModelQueryResult<TData = ModelSchemaServiceSchemasModelDefaultResponse, TError = unknown> = UseQueryResult<TData, TError>;
export const useModelSchemaServiceSchemasModelKey = "ModelSchemaServiceSchemasModel";
export const UseModelSchemaServiceSchemasModelKeyFn = (queryKey?: Array<unknown>) => [useModelSchemaServiceSchemasModelKey, ...(queryKey ?? [])];
export type OpCacheServiceOpCacheGetStatusDefaultResponse = Awaited<ReturnType<typeof OpCacheService.opCacheGetStatus>>;
export type OpCacheServiceOpCacheGetStatusQueryResult<TData = OpCacheServiceOpCacheGetStatusDefaultResponse, TError = unknown> = UseQueryResult<TData, TError>;
export const useOpCacheServiceOpCacheGetStatusKey = "OpCacheServiceOpCacheGetStatus";
export const UseOpCacheServiceOpCacheGetStatusKeyFn = (queryKey?: Array<unknown>) => [useOpCacheServiceOpCacheGetStatusKey, ...(queryKey ?? [])];
export type OpCacheServiceOpcacheGetConfigDefaultResponse = Awaited<ReturnType<typeof OpCacheService.opcacheGetConfig>>;
export type OpCacheServiceOpcacheGetConfigQueryResult<TData = OpCacheServiceOpcacheGetConfigDefaultResponse, TError = unknown> = UseQueryResult<TData, TError>;
export const useOpCacheServiceOpcacheGetConfigKey = "OpCacheServiceOpcacheGetConfig";
export const UseOpCacheServiceOpcacheGetConfigKeyFn = (queryKey?: Array<unknown>) => [useOpCacheServiceOpcacheGetConfigKey, ...(queryKey ?? [])];
export type SongServiceSongsIndexDefaultResponse = Awaited<ReturnType<typeof SongService.songsIndex>>;
export type SongServiceSongsIndexQueryResult<TData = SongServiceSongsIndexDefaultResponse, TError = unknown> = UseQueryResult<TData, TError>;
export const useSongServiceSongsIndexKey = "SongServiceSongsIndex";
export const UseSongServiceSongsIndexKeyFn = ({ genreNames, genreSlugs, library, page, perPage, relations }: {
  genreNames?: string;
  genreSlugs?: string;
  library: string;
  page?: number;
  perPage?: number;
  relations?: string;
}, queryKey?: Array<unknown>) => [useSongServiceSongsIndexKey, ...(queryKey ?? [{ genreNames, genreSlugs, library, page, perPage, relations }])];
export type SongServiceSongsShowDefaultResponse = Awaited<ReturnType<typeof SongService.songsShow>>;
export type SongServiceSongsShowQueryResult<TData = SongServiceSongsShowDefaultResponse, TError = unknown> = UseQueryResult<TData, TError>;
export const useSongServiceSongsShowKey = "SongServiceSongsShow";
export const UseSongServiceSongsShowKeyFn = ({ library, song }: {
  library: string;
  song: string;
}, queryKey?: Array<unknown>) => [useSongServiceSongsShowKey, ...(queryKey ?? [{ library, song }])];
export type SongServiceSongsStreamDefaultResponse = Awaited<ReturnType<typeof SongService.songsStream>>;
export type SongServiceSongsStreamQueryResult<TData = SongServiceSongsStreamDefaultResponse, TError = unknown> = UseQueryResult<TData, TError>;
export const useSongServiceSongsStreamKey = "SongServiceSongsStream";
export const UseSongServiceSongsStreamKeyFn = ({ library, song }: {
  library: string;
  song: string;
}, queryKey?: Array<unknown>) => [useSongServiceSongsStreamKey, ...(queryKey ?? [{ library, song }])];
export type UserServiceUsersIndexDefaultResponse = Awaited<ReturnType<typeof UserService.usersIndex>>;
export type UserServiceUsersIndexQueryResult<TData = UserServiceUsersIndexDefaultResponse, TError = unknown> = UseQueryResult<TData, TError>;
export const useUserServiceUsersIndexKey = "UserServiceUsersIndex";
export const UseUserServiceUsersIndexKeyFn = (queryKey?: Array<unknown>) => [useUserServiceUsersIndexKey, ...(queryKey ?? [])];
export type UserServiceUsersShowDefaultResponse = Awaited<ReturnType<typeof UserService.usersShow>>;
export type UserServiceUsersShowQueryResult<TData = UserServiceUsersShowDefaultResponse, TError = unknown> = UseQueryResult<TData, TError>;
export const useUserServiceUsersShowKey = "UserServiceUsersShow";
export const UseUserServiceUsersShowKeyFn = ({ user }: {
  user: number;
}, queryKey?: Array<unknown>) => [useUserServiceUsersShowKey, ...(queryKey ?? [{ user }])];
export type UserServiceUsersMeDefaultResponse = Awaited<ReturnType<typeof UserService.usersMe>>;
export type UserServiceUsersMeQueryResult<TData = UserServiceUsersMeDefaultResponse, TError = unknown> = UseQueryResult<TData, TError>;
export const useUserServiceUsersMeKey = "UserServiceUsersMe";
export const UseUserServiceUsersMeKeyFn = (queryKey?: Array<unknown>) => [useUserServiceUsersMeKey, ...(queryKey ?? [])];
export type UserTokenServiceUserTokenGetUserTokensDefaultResponse = Awaited<ReturnType<typeof UserTokenService.userTokenGetUserTokens>>;
export type UserTokenServiceUserTokenGetUserTokensQueryResult<TData = UserTokenServiceUserTokenGetUserTokensDefaultResponse, TError = unknown> = UseQueryResult<TData, TError>;
export const useUserTokenServiceUserTokenGetUserTokensKey = "UserTokenServiceUserTokenGetUserTokens";
export const UseUserTokenServiceUserTokenGetUserTokensKeyFn = ({ page, perPage, user }: {
  page?: number;
  perPage?: number;
  user: string;
}, queryKey?: Array<unknown>) => [useUserTokenServiceUserTokenGetUserTokensKey, ...(queryKey ?? [{ page, perPage, user }])];
export type WidgetServiceWidgetsGetWidgetDefaultResponse = Awaited<ReturnType<typeof WidgetService.widgetsGetWidget>>;
export type WidgetServiceWidgetsGetWidgetQueryResult<TData = WidgetServiceWidgetsGetWidgetDefaultResponse, TError = unknown> = UseQueryResult<TData, TError>;
export const useWidgetServiceWidgetsGetWidgetKey = "WidgetServiceWidgetsGetWidget";
export const UseWidgetServiceWidgetsGetWidgetKeyFn = ({ name }: {
  name: string;
}, queryKey?: Array<unknown>) => [useWidgetServiceWidgetsGetWidgetKey, ...(queryKey ?? [{ name }])];
export type WidgetSchemaServiceWidgetSchemaGetWidgetsDefaultResponse = Awaited<ReturnType<typeof WidgetSchemaService.widgetSchemaGetWidgets>>;
export type WidgetSchemaServiceWidgetSchemaGetWidgetsQueryResult<TData = WidgetSchemaServiceWidgetSchemaGetWidgetsDefaultResponse, TError = unknown> = UseQueryResult<TData, TError>;
export const useWidgetSchemaServiceWidgetSchemaGetWidgetsKey = "WidgetSchemaServiceWidgetSchemaGetWidgets";
export const UseWidgetSchemaServiceWidgetSchemaGetWidgetsKeyFn = (queryKey?: Array<unknown>) => [useWidgetSchemaServiceWidgetSchemaGetWidgetsKey, ...(queryKey ?? [])];
export type WidgetSchemaServiceWidgetSchemaGetWidgetDefaultResponse = Awaited<ReturnType<typeof WidgetSchemaService.widgetSchemaGetWidget>>;
export type WidgetSchemaServiceWidgetSchemaGetWidgetQueryResult<TData = WidgetSchemaServiceWidgetSchemaGetWidgetDefaultResponse, TError = unknown> = UseQueryResult<TData, TError>;
export const useWidgetSchemaServiceWidgetSchemaGetWidgetKey = "WidgetSchemaServiceWidgetSchemaGetWidget";
export const UseWidgetSchemaServiceWidgetSchemaGetWidgetKeyFn = ({ id, name }: {
  id: string;
  name: string;
}, queryKey?: Array<unknown>) => [useWidgetSchemaServiceWidgetSchemaGetWidgetKey, ...(queryKey ?? [{ id, name }])];
export type AuthServiceAuthLoginMutationResult = Awaited<ReturnType<typeof AuthService.authLogin>>;
export type AuthServiceAuthRefreshTokenMutationResult = Awaited<ReturnType<typeof AuthService.authRefreshToken>>;
export type AuthServiceAuthStreamTokenMutationResult = Awaited<ReturnType<typeof AuthService.authStreamToken>>;
export type AuthServiceAuthRegisterMutationResult = Awaited<ReturnType<typeof AuthService.authRegister>>;
export type AuthServiceAuthForgotPasswordMutationResult = Awaited<ReturnType<typeof AuthService.authForgotPassword>>;
export type AuthServiceAuthResetPasswordMutationResult = Awaited<ReturnType<typeof AuthService.authResetPassword>>;
export type AuthServiceAuthVerifyMutationResult = Awaited<ReturnType<typeof AuthService.authVerify>>;
export type JobServiceJobLibraryScanMutationResult = Awaited<ReturnType<typeof JobService.jobLibraryScan>>;
export type LibraryServiceLibraryCreateMutationResult = Awaited<ReturnType<typeof LibraryService.libraryCreate>>;
export type OpCacheServiceOpcacheClearMutationResult = Awaited<ReturnType<typeof OpCacheService.opcacheClear>>;
export type OpCacheServiceOpcacheCompileMutationResult = Awaited<ReturnType<typeof OpCacheService.opcacheCompile>>;
export type UserServiceUsersStoreMutationResult = Awaited<ReturnType<typeof UserService.usersStore>>;
export type GenreServiceGenresUpdateMutationResult = Awaited<ReturnType<typeof GenreService.genresUpdate>>;
export type LibraryServiceLibraryUpdateMutationResult = Awaited<ReturnType<typeof LibraryService.libraryUpdate>>;
export type UserServiceUsersUpdateMutationResult = Awaited<ReturnType<typeof UserService.usersUpdate>>;
export type GenreServiceGenresDestroyMutationResult = Awaited<ReturnType<typeof GenreService.genresDestroy>>;
export type LibraryServiceLibraryDeleteMutationResult = Awaited<ReturnType<typeof LibraryService.libraryDelete>>;
export type UserServiceUsersDestroyMutationResult = Awaited<ReturnType<typeof UserService.usersDestroy>>;
export type UserTokenServiceUserTokenRevokeTokenMutationResult = Awaited<ReturnType<typeof UserTokenService.userTokenRevokeToken>>;
