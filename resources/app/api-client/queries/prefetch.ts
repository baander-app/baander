// generated with @7nohe/openapi-react-query-codegen@1.6.0 

import { type QueryClient } from "@tanstack/react-query";
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
export const prefetchUseAlbumServiceAlbumsIndex = (queryClient: QueryClient, { fields, genres, library, page, perPage, relations }: {
  fields?: string;
  genres?: string;
  library: string;
  page?: number;
  perPage?: number;
  relations?: string;
}) => queryClient.prefetchQuery({ queryKey: Common.UseAlbumServiceAlbumsIndexKeyFn({ fields, genres, library, page, perPage, relations }), queryFn: () => AlbumService.albumsIndex({ fields, genres, library, page, perPage, relations }) });
/**
* Get an album
* @param data The data for the request.
* @param data.library The library slug
* @param data.album The album slug
* @returns AlbumResourceResource `AlbumResourceResource`
* @throws ApiError
*/
export const prefetchUseAlbumServiceAlbumsShow = (queryClient: QueryClient, { album, library }: {
  album: string;
  library: string;
}) => queryClient.prefetchQuery({ queryKey: Common.UseAlbumServiceAlbumsShowKeyFn({ album, library }), queryFn: () => AlbumService.albumsShow({ album, library }) });
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
export const prefetchUseArtistServiceArtistsIndex = (queryClient: QueryClient, { fields, genres, library, page, perPage, relations }: {
  fields?: string;
  genres?: string;
  library: string;
  page?: number;
  perPage?: number;
  relations?: string;
}) => queryClient.prefetchQuery({ queryKey: Common.UseArtistServiceArtistsIndexKeyFn({ fields, genres, library, page, perPage, relations }), queryFn: () => ArtistService.artistsIndex({ fields, genres, library, page, perPage, relations }) });
/**
* Get an artist
* @param data The data for the request.
* @param data.library
* @param data.artist The artist slug
* @returns ArtistResource `ArtistResource`
* @throws ApiError
*/
export const prefetchUseArtistServiceArtistsShow = (queryClient: QueryClient, { artist, library }: {
  artist: string;
  library: string;
}) => queryClient.prefetchQuery({ queryKey: Common.UseArtistServiceArtistsShowKeyFn({ artist, library }), queryFn: () => ArtistService.artistsShow({ artist, library }) });
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
export const prefetchUseGenreServiceGenresIndex = (queryClient: QueryClient, { fields, page, perPage, relations }: {
  fields?: string;
  page?: number;
  perPage?: number;
  relations?: string;
} = {}) => queryClient.prefetchQuery({ queryKey: Common.UseGenreServiceGenresIndexKeyFn({ fields, page, perPage, relations }), queryFn: () => GenreService.genresIndex({ fields, page, perPage, relations }) });
/**
* Get a genre
* @param data The data for the request.
* @param data.genre The genre slug
* @returns GenreResource `GenreResource`
* @throws ApiError
*/
export const prefetchUseGenreServiceGenresShow = (queryClient: QueryClient, { genre }: {
  genre: string;
}) => queryClient.prefetchQuery({ queryKey: Common.UseGenreServiceGenresShowKeyFn({ genre }), queryFn: () => GenreService.genresShow({ genre }) });
/**
* Get an image asset
* @param data The data for the request.
* @param data.image The image public id
* @returns string
* @throws ApiError
*/
export const prefetchUseImageServiceImageServe = (queryClient: QueryClient, { image }: {
  image: string;
}) => queryClient.prefetchQuery({ queryKey: Common.UseImageServiceImageServeKeyFn({ image }), queryFn: () => ImageService.imageServe({ image }) });
/**
* Get a collection of media libraries
* @param data The data for the request.
* @param data.page
* @param data.perPage
* @returns unknown Json paginated set of `LibraryResource`
* @throws ApiError
*/
export const prefetchUseLibraryServiceLibrariesIndex = (queryClient: QueryClient, { page, perPage }: {
  page?: number;
  perPage?: number;
} = {}) => queryClient.prefetchQuery({ queryKey: Common.UseLibraryServiceLibrariesIndexKeyFn({ page, perPage }), queryFn: () => LibraryService.librariesIndex({ page, perPage }) });
/**
* @returns string
* @throws ApiError
*/
export const prefetchUseModelSchemaServiceSchemasModel = (queryClient: QueryClient) => queryClient.prefetchQuery({ queryKey: Common.UseModelSchemaServiceSchemasModelKeyFn(), queryFn: () => ModelSchemaService.schemasModel() });
/**
* Get status
* @returns unknown
* @throws ApiError
*/
export const prefetchUseOpCacheServiceOpCacheGetStatus = (queryClient: QueryClient) => queryClient.prefetchQuery({ queryKey: Common.UseOpCacheServiceOpCacheGetStatusKeyFn(), queryFn: () => OpCacheService.opCacheGetStatus() });
/**
* Get config
* @returns unknown
* @throws ApiError
*/
export const prefetchUseOpCacheServiceOpcacheGetConfig = (queryClient: QueryClient) => queryClient.prefetchQuery({ queryKey: Common.UseOpCacheServiceOpcacheGetConfigKeyFn(), queryFn: () => OpCacheService.opcacheGetConfig() });
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
export const prefetchUseSongServiceSongsIndex = (queryClient: QueryClient, { albumArtist, albumId, genreIds, library, page, perPage, title }: {
  albumArtist?: string;
  albumId?: number;
  genreIds?: string;
  library: string;
  page?: number;
  perPage?: number;
  title?: string;
}) => queryClient.prefetchQuery({ queryKey: Common.UseSongServiceSongsIndexKeyFn({ albumArtist, albumId, genreIds, library, page, perPage, title }), queryFn: () => SongService.songsIndex({ albumArtist, albumId, genreIds, library, page, perPage, title }) });
/**
* Get a song
* @param data The data for the request.
* @param data.library The library slug
* @param data.song The song public id
* @returns SongWithAlbumResource `SongWithAlbumResource`
* @throws ApiError
*/
export const prefetchUseSongServiceSongsShow = (queryClient: QueryClient, { library, song }: {
  library: string;
  song: string;
}) => queryClient.prefetchQuery({ queryKey: Common.UseSongServiceSongsShowKeyFn({ library, song }), queryFn: () => SongService.songsShow({ library, song }) });
/**
* Direct stream the song
* Requires token with "access-stream"
* @param data The data for the request.
* @param data.library The library slug
* @param data.song The song public id
* @returns unknown
* @throws ApiError
*/
export const prefetchUseSongServiceSongsStream = (queryClient: QueryClient, { library, song }: {
  library: string;
  song: string;
}) => queryClient.prefetchQuery({ queryKey: Common.UseSongServiceSongsStreamKeyFn({ library, song }), queryFn: () => SongService.songsStream({ library, song }) });
/**
* Get a collection of users
* @returns unknown Json paginated set of `UserResource`
* @throws ApiError
*/
export const prefetchUseUserServiceUsersIndex = (queryClient: QueryClient) => queryClient.prefetchQuery({ queryKey: Common.UseUserServiceUsersIndexKeyFn(), queryFn: () => UserService.usersIndex() });
/**
* Get small user detail info
* @param data The data for the request.
* @param data.user The user ID
* @returns UserResource `UserResource`
* @throws ApiError
*/
export const prefetchUseUserServiceUsersShow = (queryClient: QueryClient, { user }: {
  user: number;
}) => queryClient.prefetchQuery({ queryKey: Common.UseUserServiceUsersShowKeyFn({ user }), queryFn: () => UserService.usersShow({ user }) });
/**
* Get the authenticated user
* @returns UserResource `UserResource`
* @throws ApiError
*/
export const prefetchUseUserServiceUsersMe = (queryClient: QueryClient) => queryClient.prefetchQuery({ queryKey: Common.UseUserServiceUsersMeKeyFn(), queryFn: () => UserService.usersMe() });
/**
* Get a collection of tokens
* @param data The data for the request.
* @param data.user
* @param data.page
* @param data.perPage
* @returns unknown Json paginated set of `PersonalAccessTokenViewResource`
* @throws ApiError
*/
export const prefetchUseUserTokenServiceUserTokenGetUserTokens = (queryClient: QueryClient, { page, perPage, user }: {
  page?: number;
  perPage?: number;
  user: string;
}) => queryClient.prefetchQuery({ queryKey: Common.UseUserTokenServiceUserTokenGetUserTokensKeyFn({ page, perPage, user }), queryFn: () => UserTokenService.userTokenGetUserTokens({ page, perPage, user }) });
/**
* Get a widget for the user
* @param data The data for the request.
* @param data.name
* @returns null
* @throws ApiError
*/
export const prefetchUseWidgetServiceWidgetsGetWidget = (queryClient: QueryClient, { name }: {
  name: string;
}) => queryClient.prefetchQuery({ queryKey: Common.UseWidgetServiceWidgetsGetWidgetKeyFn({ name }), queryFn: () => WidgetService.widgetsGetWidget({ name }) });
/**
* Get a list of widgets
* @returns WidgetListItemResource Array of `WidgetListItemResource`
* @throws ApiError
*/
export const prefetchUseWidgetSchemaServiceWidgetSchemaGetWidgets = (queryClient: QueryClient) => queryClient.prefetchQuery({ queryKey: Common.UseWidgetSchemaServiceWidgetSchemaGetWidgetsKeyFn(), queryFn: () => WidgetSchemaService.widgetSchemaGetWidgets() });
/**
* Get widget schema
* @param data The data for the request.
* @param data.name Name of the schema
* @param data.id
* @returns string
* @throws ApiError
*/
export const prefetchUseWidgetSchemaServiceWidgetSchemaGetWidget = (queryClient: QueryClient, { id, name }: {
  id: string;
  name: string;
}) => queryClient.prefetchQuery({ queryKey: Common.UseWidgetSchemaServiceWidgetSchemaGetWidgetKeyFn({ id, name }), queryFn: () => WidgetSchemaService.widgetSchemaGetWidget({ id, name }) });
