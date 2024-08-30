// generated with @7nohe/openapi-react-query-codegen@1.5.1 

import { type QueryClient } from "@tanstack/react-query";
import { AlbumService, ArtistService, GenreService, ImageService, LibraryService, LogsService, SongService, UserService, WidgetSchemaService, WidgetService } from "../requests/services.gen";
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
export const prefetchUseAlbumServiceAlbumsIndex = (queryClient: QueryClient, { fields, library, page, perPage, relations }: {
  fields?: string;
  library: string;
  page?: number;
  perPage?: number;
  relations?: string;
}) => queryClient.prefetchQuery({ queryKey: Common.UseAlbumServiceAlbumsIndexKeyFn({ fields, library, page, perPage, relations }), queryFn: () => AlbumService.albumsIndex({ fields, library, page, perPage, relations }) });
/**
* @param data The data for the request.
* @param data.library The library slug
* @param data.album The album slug
* @returns AlbumResource `AlbumResource`
* @throws ApiError
*/
export const prefetchUseAlbumServiceAlbumsShow = (queryClient: QueryClient, { album, library }: {
  album: string;
  library: string;
}) => queryClient.prefetchQuery({ queryKey: Common.UseAlbumServiceAlbumsShowKeyFn({ album, library }), queryFn: () => AlbumService.albumsShow({ album, library }) });
/**
* @param data The data for the request.
* @param data.library
* @returns unknown Json paginated set of `ArtistResource`
* @throws ApiError
*/
export const prefetchUseArtistServiceArtistsIndex = (queryClient: QueryClient, { library }: {
  library: string;
}) => queryClient.prefetchQuery({ queryKey: Common.UseArtistServiceArtistsIndexKeyFn({ library }), queryFn: () => ArtistService.artistsIndex({ library }) });
/**
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
* @param data The data for the request.
* @param data.library
* @param data.page
* @param data.perPage
* @returns unknown Json paginated set of `GenreResource`
* @throws ApiError
*/
export const prefetchUseGenreServiceGenresIndex = (queryClient: QueryClient, { library, page, perPage }: {
  library: string;
  page?: number;
  perPage?: number;
}) => queryClient.prefetchQuery({ queryKey: Common.UseGenreServiceGenresIndexKeyFn({ library, page, perPage }), queryFn: () => GenreService.genresIndex({ library, page, perPage }) });
/**
* Get image asset
* @param data The data for the request.
* @param data.image The image public id
* @returns string
* @throws ApiError
*/
export const prefetchUseImageServiceImageServe = (queryClient: QueryClient, { image }: {
  image: string;
}) => queryClient.prefetchQuery({ queryKey: Common.UseImageServiceImageServeKeyFn({ image }), queryFn: () => ImageService.imageServe({ image }) });
/**
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
* Get a list of log files
* @returns unknown
* @throws ApiError
*/
export const prefetchUseLogsServiceLogsFiles = (queryClient: QueryClient) => queryClient.prefetchQuery({ queryKey: Common.UseLogsServiceLogsFilesKeyFn(), queryFn: () => LogsService.logsFiles() });
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
* @param data The data for the request.
* @param data.library The library slug
* @param data.song The song public id
* @returns SongResource `SongResource`
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
* @param data.library
* @param data.song The song public id
* @returns unknown
* @throws ApiError
*/
export const prefetchUseSongServiceSongsStream = (queryClient: QueryClient, { library, song }: {
  library: string;
  song: string;
}) => queryClient.prefetchQuery({ queryKey: Common.UseSongServiceSongsStreamKeyFn({ library, song }), queryFn: () => SongService.songsStream({ library, song }) });
/**
* Display a collection of users
* @returns unknown Json paginated set of `UserResource`
* @throws ApiError
*/
export const prefetchUseUserServiceUsersIndex = (queryClient: QueryClient) => queryClient.prefetchQuery({ queryKey: Common.UseUserServiceUsersIndexKeyFn(), queryFn: () => UserService.usersIndex() });
/**
* Display a user
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
* Get a widget for the user
* @param data The data for the request.
* @param data.name
* @returns string
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
