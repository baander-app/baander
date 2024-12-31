// generated with @7nohe/openapi-react-query-codegen@1.6.0 

import { InfiniteData, useInfiniteQuery, UseInfiniteQueryOptions } from "@tanstack/react-query";
import { AlbumService, ArtistService, GenreService, LibraryService, SongService, UserTokenService } from "../requests/services.gen";
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
export const useAlbumServiceAlbumsIndexInfinite = <TData = InfiniteData<Common.AlbumServiceAlbumsIndexDefaultResponse>, TError = unknown, TQueryKey extends Array<unknown> = unknown[]>({ fields, genres, library, perPage, relations }: {
  fields?: string;
  genres?: string;
  library: string;
  perPage?: number;
  relations?: string;
}, queryKey?: TQueryKey, options?: Omit<UseInfiniteQueryOptions<TData, TError>, "queryKey" | "queryFn">) => useInfiniteQuery({ queryKey: Common.UseAlbumServiceAlbumsIndexKeyFn({ fields, genres, library, perPage, relations }, queryKey), queryFn: ({ pageParam }) => AlbumService.albumsIndex({ fields, genres, library, page: pageParam as number, perPage, relations }) as TData, initialPageParam: 1, getNextPageParam: response => (response as { nextPage: number }).nextPage, ...options });
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
export const useArtistServiceArtistsIndexInfinite = <TData = InfiniteData<Common.ArtistServiceArtistsIndexDefaultResponse>, TError = unknown, TQueryKey extends Array<unknown> = unknown[]>({ fields, genres, library, perPage, relations }: {
  fields?: string;
  genres?: string;
  library: string;
  perPage?: number;
  relations?: string;
}, queryKey?: TQueryKey, options?: Omit<UseInfiniteQueryOptions<TData, TError>, "queryKey" | "queryFn">) => useInfiniteQuery({ queryKey: Common.UseArtistServiceArtistsIndexKeyFn({ fields, genres, library, perPage, relations }, queryKey), queryFn: ({ pageParam }) => ArtistService.artistsIndex({ fields, genres, library, page: pageParam as number, perPage, relations }) as TData, initialPageParam: 1, getNextPageParam: response => (response as { nextPage: number }).nextPage, ...options });
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
export const useGenreServiceGenresIndexInfinite = <TData = InfiniteData<Common.GenreServiceGenresIndexDefaultResponse>, TError = unknown, TQueryKey extends Array<unknown> = unknown[]>({ fields, perPage, relations }: {
  fields?: string;
  perPage?: number;
  relations?: string;
} = {}, queryKey?: TQueryKey, options?: Omit<UseInfiniteQueryOptions<TData, TError>, "queryKey" | "queryFn">) => useInfiniteQuery({ queryKey: Common.UseGenreServiceGenresIndexKeyFn({ fields, perPage, relations }, queryKey), queryFn: ({ pageParam }) => GenreService.genresIndex({ fields, page: pageParam as number, perPage, relations }) as TData, initialPageParam: 1, getNextPageParam: response => (response as { nextPage: number }).nextPage, ...options });
/**
* Get a collection of media libraries
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
export const useSongServiceSongsIndexInfinite = <TData = InfiniteData<Common.SongServiceSongsIndexDefaultResponse>, TError = unknown, TQueryKey extends Array<unknown> = unknown[]>({ albumArtist, albumId, genreIds, library, perPage, title }: {
  albumArtist?: string;
  albumId?: number;
  genreIds?: string;
  library: string;
  perPage?: number;
  title?: string;
}, queryKey?: TQueryKey, options?: Omit<UseInfiniteQueryOptions<TData, TError>, "queryKey" | "queryFn">) => useInfiniteQuery({ queryKey: Common.UseSongServiceSongsIndexKeyFn({ albumArtist, albumId, genreIds, library, perPage, title }, queryKey), queryFn: ({ pageParam }) => SongService.songsIndex({ albumArtist, albumId, genreIds, library, page: pageParam as number, perPage, title }) as TData, initialPageParam: 1, getNextPageParam: response => (response as { nextPage: number }).nextPage, ...options });
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
}, queryKey?: TQueryKey, options?: Omit<UseInfiniteQueryOptions<TData, TError>, "queryKey" | "queryFn">) => useInfiniteQuery({ queryKey: Common.UseUserTokenServiceUserTokenGetUserTokensKeyFn({ perPage, user }, queryKey), queryFn: ({ pageParam }) => UserTokenService.userTokenGetUserTokens({ page: pageParam as number, perPage, user }) as TData, initialPageParam: 1, getNextPageParam: response => (response as { nextPage: number }).nextPage, ...options });
