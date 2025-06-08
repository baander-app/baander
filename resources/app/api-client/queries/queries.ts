// generated with @7nohe/openapi-react-query-codegen@1.6.2 

import { useMutation, UseMutationOptions, useQuery, UseQueryOptions } from "@tanstack/react-query";
import { AlbumService, ArtistService, AuthService, BatchesService, CompletedJobsService, DashboardStatsService, FailedJobsService, FilesService, FoldersService, GenreService, HostsService, ImageService, JobMetricsService, JobService, JobsService, LibraryService, LogsService, MasterSupervisorService, MonitoringService, MovieService, OpCacheService, PasskeyService, PendingJobsService, PlaylistService, QueueMetricsService, QueueService, RetryService, SchemaService, SilencedJobsService, SongService, StreamService, SystemInfoService, UserService, UserTokenService, WorkloadService } from "../requests/services.gen";
import { AuthenticateUsingPasskeyRequest, CreateLibraryRequest, CreatePlaylistRequest, CreateSmartPlaylistRequest, CreateUserRequest, ForgotPasswordRequest, LoginRequest, LogoutRequest, RegisterRequest, ResetPasswordRequest, RetryJobRequest, StorePasskeyRequest, UpdateGenreRequest, UpdateLibraryRequest, UpdatePlaylistRequest, UpdateSmartPlaylistRulesRequest, UpdateUserRequest } from "../requests/types.gen";
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
export const useAlbumServiceGetApiLibrariesByLibraryAlbums = <TData = Common.AlbumServiceGetApiLibrariesByLibraryAlbumsDefaultResponse, TError = unknown, TQueryKey extends Array<unknown> = unknown[]>({ fields, genres, library, limit, page, relations }: {
  fields?: string;
  genres?: string;
  library: string;
  limit?: number;
  page?: number;
  relations?: string;
}, queryKey?: TQueryKey, options?: Omit<UseQueryOptions<TData, TError>, "queryKey" | "queryFn">) => useQuery<TData, TError>({ queryKey: Common.UseAlbumServiceGetApiLibrariesByLibraryAlbumsKeyFn({ fields, genres, library, limit, page, relations }, queryKey), queryFn: () => AlbumService.getApiLibrariesByLibraryAlbums({ fields, genres, library, limit, page, relations }) as TData, ...options });
/**
* Get an album
* @param data The data for the request.
* @param data.library The library slug
* @param data.album The album slug
* @returns AlbumResource `AlbumResource`
* @throws ApiError
*/
export const useAlbumServiceGetApiLibrariesByLibraryAlbumsByAlbum = <TData = Common.AlbumServiceGetApiLibrariesByLibraryAlbumsByAlbumDefaultResponse, TError = unknown, TQueryKey extends Array<unknown> = unknown[]>({ album, library }: {
  album: string;
  library: string;
}, queryKey?: TQueryKey, options?: Omit<UseQueryOptions<TData, TError>, "queryKey" | "queryFn">) => useQuery<TData, TError>({ queryKey: Common.UseAlbumServiceGetApiLibrariesByLibraryAlbumsByAlbumKeyFn({ album, library }, queryKey), queryFn: () => AlbumService.getApiLibrariesByLibraryAlbumsByAlbum({ album, library }) as TData, ...options });
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
export const useArtistServiceGetApiLibrariesByLibraryArtists = <TData = Common.ArtistServiceGetApiLibrariesByLibraryArtistsDefaultResponse, TError = unknown, TQueryKey extends Array<unknown> = unknown[]>({ fields, genres, library, limit, page, relations }: {
  fields?: string;
  genres?: string;
  library: string;
  limit?: number;
  page?: number;
  relations?: string;
}, queryKey?: TQueryKey, options?: Omit<UseQueryOptions<TData, TError>, "queryKey" | "queryFn">) => useQuery<TData, TError>({ queryKey: Common.UseArtistServiceGetApiLibrariesByLibraryArtistsKeyFn({ fields, genres, library, limit, page, relations }, queryKey), queryFn: () => ArtistService.getApiLibrariesByLibraryArtists({ fields, genres, library, limit, page, relations }) as TData, ...options });
/**
* Get an artist
* @param data The data for the request.
* @param data.library
* @param data.artist The artist slug
* @returns ArtistResource `ArtistResource`
* @throws ApiError
*/
export const useArtistServiceGetApiLibrariesByLibraryArtistsByArtist = <TData = Common.ArtistServiceGetApiLibrariesByLibraryArtistsByArtistDefaultResponse, TError = unknown, TQueryKey extends Array<unknown> = unknown[]>({ artist, library }: {
  artist: string;
  library: string;
}, queryKey?: TQueryKey, options?: Omit<UseQueryOptions<TData, TError>, "queryKey" | "queryFn">) => useQuery<TData, TError>({ queryKey: Common.UseArtistServiceGetApiLibrariesByLibraryArtistsByArtistKeyFn({ artist, library }, queryKey), queryFn: () => ArtistService.getApiLibrariesByLibraryArtistsByArtist({ artist, library }) as TData, ...options });
/**
* Get a passkey challenge
* @returns unknown
* @throws ApiError
*/
export const useAuthServiceGetWebauthnPasskey = <TData = Common.AuthServiceGetWebauthnPasskeyDefaultResponse, TError = unknown, TQueryKey extends Array<unknown> = unknown[]>(queryKey?: TQueryKey, options?: Omit<UseQueryOptions<TData, TError>, "queryKey" | "queryFn">) => useQuery<TData, TError>({ queryKey: Common.UseAuthServiceGetWebauthnPasskeyKeyFn(queryKey), queryFn: () => AuthService.getWebauthnPasskey() as TData, ...options });
/**
* Get passkey registration options
* @returns unknown
* @throws ApiError
*/
export const useAuthServiceGetWebauthnPasskeyRegister = <TData = Common.AuthServiceGetWebauthnPasskeyRegisterDefaultResponse, TError = unknown, TQueryKey extends Array<unknown> = unknown[]>(queryKey?: TQueryKey, options?: Omit<UseQueryOptions<TData, TError>, "queryKey" | "queryFn">) => useQuery<TData, TError>({ queryKey: Common.UseAuthServiceGetWebauthnPasskeyRegisterKeyFn(queryKey), queryFn: () => AuthService.getWebauthnPasskeyRegister() as TData, ...options });
/**
* Get a passkey challenge
* @returns unknown
* @throws ApiError
*/
export const usePasskeyServiceGetWebauthnPasskey = <TData = Common.PasskeyServiceGetWebauthnPasskeyDefaultResponse, TError = unknown, TQueryKey extends Array<unknown> = unknown[]>(queryKey?: TQueryKey, options?: Omit<UseQueryOptions<TData, TError>, "queryKey" | "queryFn">) => useQuery<TData, TError>({ queryKey: Common.UsePasskeyServiceGetWebauthnPasskeyKeyFn(queryKey), queryFn: () => PasskeyService.getWebauthnPasskey() as TData, ...options });
/**
* Get passkey registration options
* @returns unknown
* @throws ApiError
*/
export const usePasskeyServiceGetWebauthnPasskeyRegister = <TData = Common.PasskeyServiceGetWebauthnPasskeyRegisterDefaultResponse, TError = unknown, TQueryKey extends Array<unknown> = unknown[]>(queryKey?: TQueryKey, options?: Omit<UseQueryOptions<TData, TError>, "queryKey" | "queryFn">) => useQuery<TData, TError>({ queryKey: Common.UsePasskeyServiceGetWebauthnPasskeyRegisterKeyFn(queryKey), queryFn: () => PasskeyService.getWebauthnPasskeyRegister() as TData, ...options });
/**
* Get all of the batches
* @returns unknown
* @throws ApiError
*/
export const useBatchesServiceGetHorizonApiBatches = <TData = Common.BatchesServiceGetHorizonApiBatchesDefaultResponse, TError = unknown, TQueryKey extends Array<unknown> = unknown[]>(queryKey?: TQueryKey, options?: Omit<UseQueryOptions<TData, TError>, "queryKey" | "queryFn">) => useQuery<TData, TError>({ queryKey: Common.UseBatchesServiceGetHorizonApiBatchesKeyFn(queryKey), queryFn: () => BatchesService.getHorizonApiBatches() as TData, ...options });
/**
* Get the details of a batch by ID
* @param data The data for the request.
* @param data.id
* @returns unknown
* @throws ApiError
*/
export const useBatchesServiceGetHorizonApiBatchesById = <TData = Common.BatchesServiceGetHorizonApiBatchesByIdDefaultResponse, TError = unknown, TQueryKey extends Array<unknown> = unknown[]>({ id }: {
  id: string;
}, queryKey?: TQueryKey, options?: Omit<UseQueryOptions<TData, TError>, "queryKey" | "queryFn">) => useQuery<TData, TError>({ queryKey: Common.UseBatchesServiceGetHorizonApiBatchesByIdKeyFn({ id }, queryKey), queryFn: () => BatchesService.getHorizonApiBatchesById({ id }) as TData, ...options });
/**
* Get all of the completed jobs
* @param data The data for the request.
* @param data.startingAt
* @returns unknown
* @throws ApiError
*/
export const useCompletedJobsServiceGetHorizonApiJobsCompleted = <TData = Common.CompletedJobsServiceGetHorizonApiJobsCompletedDefaultResponse, TError = unknown, TQueryKey extends Array<unknown> = unknown[]>({ startingAt }: {
  startingAt?: string;
} = {}, queryKey?: TQueryKey, options?: Omit<UseQueryOptions<TData, TError>, "queryKey" | "queryFn">) => useQuery<TData, TError>({ queryKey: Common.UseCompletedJobsServiceGetHorizonApiJobsCompletedKeyFn({ startingAt }, queryKey), queryFn: () => CompletedJobsService.getHorizonApiJobsCompleted({ startingAt }) as TData, ...options });
/**
* Get the key performance stats for the dashboard
* @returns unknown
* @throws ApiError
*/
export const useDashboardStatsServiceGetHorizonApiStats = <TData = Common.DashboardStatsServiceGetHorizonApiStatsDefaultResponse, TError = unknown, TQueryKey extends Array<unknown> = unknown[]>(queryKey?: TQueryKey, options?: Omit<UseQueryOptions<TData, TError>, "queryKey" | "queryFn">) => useQuery<TData, TError>({ queryKey: Common.UseDashboardStatsServiceGetHorizonApiStatsKeyFn(queryKey), queryFn: () => DashboardStatsService.getHorizonApiStats() as TData, ...options });
/**
* Get all of the failed jobs
* @param data The data for the request.
* @param data.tag
* @returns unknown
* @throws ApiError
*/
export const useFailedJobsServiceGetHorizonApiJobsFailed = <TData = Common.FailedJobsServiceGetHorizonApiJobsFailedDefaultResponse, TError = unknown, TQueryKey extends Array<unknown> = unknown[]>({ tag }: {
  tag?: string;
} = {}, queryKey?: TQueryKey, options?: Omit<UseQueryOptions<TData, TError>, "queryKey" | "queryFn">) => useQuery<TData, TError>({ queryKey: Common.UseFailedJobsServiceGetHorizonApiJobsFailedKeyFn({ tag }, queryKey), queryFn: () => FailedJobsService.getHorizonApiJobsFailed({ tag }) as TData, ...options });
/**
* Get a failed job instance
* @param data The data for the request.
* @param data.id
* @returns unknown
* @throws ApiError
*/
export const useFailedJobsServiceGetHorizonApiJobsFailedById = <TData = Common.FailedJobsServiceGetHorizonApiJobsFailedByIdDefaultResponse, TError = unknown, TQueryKey extends Array<unknown> = unknown[]>({ id }: {
  id: string;
}, queryKey?: TQueryKey, options?: Omit<UseQueryOptions<TData, TError>, "queryKey" | "queryFn">) => useQuery<TData, TError>({ queryKey: Common.UseFailedJobsServiceGetHorizonApiJobsFailedByIdKeyFn({ id }, queryKey), queryFn: () => FailedJobsService.getHorizonApiJobsFailedById({ id }) as TData, ...options });
/**
* @returns LogFileResource Array of `LogFileResource`
* @throws ApiError
*/
export const useFilesServiceGetSystemLogViewerApiFiles = <TData = Common.FilesServiceGetSystemLogViewerApiFilesDefaultResponse, TError = unknown, TQueryKey extends Array<unknown> = unknown[]>(queryKey?: TQueryKey, options?: Omit<UseQueryOptions<TData, TError>, "queryKey" | "queryFn">) => useQuery<TData, TError>({ queryKey: Common.UseFilesServiceGetSystemLogViewerApiFilesKeyFn(queryKey), queryFn: () => FilesService.getSystemLogViewerApiFiles() as TData, ...options });
/**
* @param data The data for the request.
* @param data.fileIdentifier
* @returns unknown
* @throws ApiError
*/
export const useFilesServiceGetSystemLogViewerApiFilesByFileIdentifierDownloadRequest = <TData = Common.FilesServiceGetSystemLogViewerApiFilesByFileIdentifierDownloadRequestDefaultResponse, TError = unknown, TQueryKey extends Array<unknown> = unknown[]>({ fileIdentifier }: {
  fileIdentifier: string;
}, queryKey?: TQueryKey, options?: Omit<UseQueryOptions<TData, TError>, "queryKey" | "queryFn">) => useQuery<TData, TError>({ queryKey: Common.UseFilesServiceGetSystemLogViewerApiFilesByFileIdentifierDownloadRequestKeyFn({ fileIdentifier }, queryKey), queryFn: () => FilesService.getSystemLogViewerApiFilesByFileIdentifierDownloadRequest({ fileIdentifier }) as TData, ...options });
/**
* @param data The data for the request.
* @param data.fileIdentifier
* @returns string
* @throws ApiError
*/
export const useFilesServiceGetSystemLogViewerApiFilesByFileIdentifierDownload = <TData = Common.FilesServiceGetSystemLogViewerApiFilesByFileIdentifierDownloadDefaultResponse, TError = unknown, TQueryKey extends Array<unknown> = unknown[]>({ fileIdentifier }: {
  fileIdentifier: string;
}, queryKey?: TQueryKey, options?: Omit<UseQueryOptions<TData, TError>, "queryKey" | "queryFn">) => useQuery<TData, TError>({ queryKey: Common.UseFilesServiceGetSystemLogViewerApiFilesByFileIdentifierDownloadKeyFn({ fileIdentifier }, queryKey), queryFn: () => FilesService.getSystemLogViewerApiFilesByFileIdentifierDownload({ fileIdentifier }) as TData, ...options });
/**
* @param data The data for the request.
* @param data.direction
* @returns LogFolderResource Array of `LogFolderResource`
* @throws ApiError
*/
export const useFoldersServiceGetSystemLogViewerApiFolders = <TData = Common.FoldersServiceGetSystemLogViewerApiFoldersDefaultResponse, TError = unknown, TQueryKey extends Array<unknown> = unknown[]>({ direction }: {
  direction?: string;
} = {}, queryKey?: TQueryKey, options?: Omit<UseQueryOptions<TData, TError>, "queryKey" | "queryFn">) => useQuery<TData, TError>({ queryKey: Common.UseFoldersServiceGetSystemLogViewerApiFoldersKeyFn({ direction }, queryKey), queryFn: () => FoldersService.getSystemLogViewerApiFolders({ direction }) as TData, ...options });
/**
* @param data The data for the request.
* @param data.folderIdentifier
* @returns unknown
* @throws ApiError
*/
export const useFoldersServiceGetSystemLogViewerApiFoldersByFolderIdentifierDownloadRequest = <TData = Common.FoldersServiceGetSystemLogViewerApiFoldersByFolderIdentifierDownloadRequestDefaultResponse, TError = unknown, TQueryKey extends Array<unknown> = unknown[]>({ folderIdentifier }: {
  folderIdentifier: string;
}, queryKey?: TQueryKey, options?: Omit<UseQueryOptions<TData, TError>, "queryKey" | "queryFn">) => useQuery<TData, TError>({ queryKey: Common.UseFoldersServiceGetSystemLogViewerApiFoldersByFolderIdentifierDownloadRequestKeyFn({ folderIdentifier }, queryKey), queryFn: () => FoldersService.getSystemLogViewerApiFoldersByFolderIdentifierDownloadRequest({ folderIdentifier }) as TData, ...options });
/**
* @param data The data for the request.
* @param data.folderIdentifier
* @returns string
* @throws ApiError
*/
export const useFoldersServiceGetSystemLogViewerApiFoldersByFolderIdentifierDownload = <TData = Common.FoldersServiceGetSystemLogViewerApiFoldersByFolderIdentifierDownloadDefaultResponse, TError = unknown, TQueryKey extends Array<unknown> = unknown[]>({ folderIdentifier }: {
  folderIdentifier: string;
}, queryKey?: TQueryKey, options?: Omit<UseQueryOptions<TData, TError>, "queryKey" | "queryFn">) => useQuery<TData, TError>({ queryKey: Common.UseFoldersServiceGetSystemLogViewerApiFoldersByFolderIdentifierDownloadKeyFn({ folderIdentifier }, queryKey), queryFn: () => FoldersService.getSystemLogViewerApiFoldersByFolderIdentifierDownload({ folderIdentifier }) as TData, ...options });
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
export const useGenreServiceGetApiGenres = <TData = Common.GenreServiceGetApiGenresDefaultResponse, TError = unknown, TQueryKey extends Array<unknown> = unknown[]>({ fields, librarySlug, limit, page, relations }: {
  fields?: string;
  librarySlug?: string;
  limit?: number;
  page?: number;
  relations?: string;
} = {}, queryKey?: TQueryKey, options?: Omit<UseQueryOptions<TData, TError>, "queryKey" | "queryFn">) => useQuery<TData, TError>({ queryKey: Common.UseGenreServiceGetApiGenresKeyFn({ fields, librarySlug, limit, page, relations }, queryKey), queryFn: () => GenreService.getApiGenres({ fields, librarySlug, limit, page, relations }) as TData, ...options });
/**
* Get a genre
* @param data The data for the request.
* @param data.genre The genre slug
* @returns GenreResource `GenreResource`
* @throws ApiError
*/
export const useGenreServiceGetApiGenresByGenre = <TData = Common.GenreServiceGetApiGenresByGenreDefaultResponse, TError = unknown, TQueryKey extends Array<unknown> = unknown[]>({ genre }: {
  genre: string;
}, queryKey?: TQueryKey, options?: Omit<UseQueryOptions<TData, TError>, "queryKey" | "queryFn">) => useQuery<TData, TError>({ queryKey: Common.UseGenreServiceGetApiGenresByGenreKeyFn({ genre }, queryKey), queryFn: () => GenreService.getApiGenresByGenre({ genre }) as TData, ...options });
/**
* @returns LogViewerHostResource Array of `LogViewerHostResource`
* @throws ApiError
*/
export const useHostsServiceGetSystemLogViewerApiHosts = <TData = Common.HostsServiceGetSystemLogViewerApiHostsDefaultResponse, TError = unknown, TQueryKey extends Array<unknown> = unknown[]>(queryKey?: TQueryKey, options?: Omit<UseQueryOptions<TData, TError>, "queryKey" | "queryFn">) => useQuery<TData, TError>({ queryKey: Common.UseHostsServiceGetSystemLogViewerApiHostsKeyFn(queryKey), queryFn: () => HostsService.getSystemLogViewerApiHosts() as TData, ...options });
/**
* Get an image asset
* @param data The data for the request.
* @param data.image The image public id
* @returns string
* @throws ApiError
*/
export const useImageServiceGetApiImagesByImage = <TData = Common.ImageServiceGetApiImagesByImageDefaultResponse, TError = unknown, TQueryKey extends Array<unknown> = unknown[]>({ image }: {
  image: string;
}, queryKey?: TQueryKey, options?: Omit<UseQueryOptions<TData, TError>, "queryKey" | "queryFn">) => useQuery<TData, TError>({ queryKey: Common.UseImageServiceGetApiImagesByImageKeyFn({ image }, queryKey), queryFn: () => ImageService.getApiImagesByImage({ image }) as TData, ...options });
/**
* Get all of the measured jobs
* @returns unknown
* @throws ApiError
*/
export const useJobMetricsServiceGetHorizonApiMetricsJobs = <TData = Common.JobMetricsServiceGetHorizonApiMetricsJobsDefaultResponse, TError = unknown, TQueryKey extends Array<unknown> = unknown[]>(queryKey?: TQueryKey, options?: Omit<UseQueryOptions<TData, TError>, "queryKey" | "queryFn">) => useQuery<TData, TError>({ queryKey: Common.UseJobMetricsServiceGetHorizonApiMetricsJobsKeyFn(queryKey), queryFn: () => JobMetricsService.getHorizonApiMetricsJobs() as TData, ...options });
/**
* Get metrics for a given job
* @param data The data for the request.
* @param data.id
* @returns unknown
* @throws ApiError
*/
export const useJobMetricsServiceGetHorizonApiMetricsJobsById = <TData = Common.JobMetricsServiceGetHorizonApiMetricsJobsByIdDefaultResponse, TError = unknown, TQueryKey extends Array<unknown> = unknown[]>({ id }: {
  id: string;
}, queryKey?: TQueryKey, options?: Omit<UseQueryOptions<TData, TError>, "queryKey" | "queryFn">) => useQuery<TData, TError>({ queryKey: Common.UseJobMetricsServiceGetHorizonApiMetricsJobsByIdKeyFn({ id }, queryKey), queryFn: () => JobMetricsService.getHorizonApiMetricsJobsById({ id }) as TData, ...options });
/**
* Get the details of a recent job by ID
* @param data The data for the request.
* @param data.id
* @returns unknown
* @throws ApiError
*/
export const useJobsServiceGetHorizonApiJobsById = <TData = Common.JobsServiceGetHorizonApiJobsByIdDefaultResponse, TError = unknown, TQueryKey extends Array<unknown> = unknown[]>({ id }: {
  id: string;
}, queryKey?: TQueryKey, options?: Omit<UseQueryOptions<TData, TError>, "queryKey" | "queryFn">) => useQuery<TData, TError>({ queryKey: Common.UseJobsServiceGetHorizonApiJobsByIdKeyFn({ id }, queryKey), queryFn: () => JobsService.getHorizonApiJobsById({ id }) as TData, ...options });
/**
* Get a collection of media libraries
* @param data The data for the request.
* @param data.page
* @param data.limit
* @returns unknown Paginated set of `LibraryResource`
* @throws ApiError
*/
export const useLibraryServiceGetApiLibraries = <TData = Common.LibraryServiceGetApiLibrariesDefaultResponse, TError = unknown, TQueryKey extends Array<unknown> = unknown[]>({ limit, page }: {
  limit?: number;
  page?: number;
} = {}, queryKey?: TQueryKey, options?: Omit<UseQueryOptions<TData, TError>, "queryKey" | "queryFn">) => useQuery<TData, TError>({ queryKey: Common.UseLibraryServiceGetApiLibrariesKeyFn({ limit, page }, queryKey), queryFn: () => LibraryService.getApiLibraries({ limit, page }) as TData, ...options });
/**
* Show library
* @param data The data for the request.
* @param data.slug
* @returns LibraryResource `LibraryResource`
* @throws ApiError
*/
export const useLibraryServiceGetApiLibrariesBySlug = <TData = Common.LibraryServiceGetApiLibrariesBySlugDefaultResponse, TError = unknown, TQueryKey extends Array<unknown> = unknown[]>({ slug }: {
  slug: string;
}, queryKey?: TQueryKey, options?: Omit<UseQueryOptions<TData, TError>, "queryKey" | "queryFn">) => useQuery<TData, TError>({ queryKey: Common.UseLibraryServiceGetApiLibrariesBySlugKeyFn({ slug }, queryKey), queryFn: () => LibraryService.getApiLibrariesBySlug({ slug }) as TData, ...options });
/**
* @param data The data for the request.
* @param data.file
* @param data.query
* @param data.direction
* @param data.log
* @param data.excludeLevels
* @param data.excludeFileTypes
* @param data.perPage
* @param data.shorterStackTraces
* @returns unknown
* @throws ApiError
*/
export const useLogsServiceGetSystemLogViewerApiLogs = <TData = Common.LogsServiceGetSystemLogViewerApiLogsDefaultResponse, TError = unknown, TQueryKey extends Array<unknown> = unknown[]>({ direction, excludeFileTypes, excludeLevels, file, log, perPage, query, shorterStackTraces }: {
  direction?: string;
  excludeFileTypes?: string;
  excludeLevels?: string;
  file?: string;
  log?: string;
  perPage?: string;
  query?: string;
  shorterStackTraces?: boolean;
} = {}, queryKey?: TQueryKey, options?: Omit<UseQueryOptions<TData, TError>, "queryKey" | "queryFn">) => useQuery<TData, TError>({ queryKey: Common.UseLogsServiceGetSystemLogViewerApiLogsKeyFn({ direction, excludeFileTypes, excludeLevels, file, log, perPage, query, shorterStackTraces }, queryKey), queryFn: () => LogsService.getSystemLogViewerApiLogs({ direction, excludeFileTypes, excludeLevels, file, log, perPage, query, shorterStackTraces }) as TData, ...options });
/**
* Get all of the master supervisors and their underlying supervisors
* @returns unknown
* @throws ApiError
*/
export const useMasterSupervisorServiceGetHorizonApiMasters = <TData = Common.MasterSupervisorServiceGetHorizonApiMastersDefaultResponse, TError = unknown, TQueryKey extends Array<unknown> = unknown[]>(queryKey?: TQueryKey, options?: Omit<UseQueryOptions<TData, TError>, "queryKey" | "queryFn">) => useQuery<TData, TError>({ queryKey: Common.UseMasterSupervisorServiceGetHorizonApiMastersKeyFn(queryKey), queryFn: () => MasterSupervisorService.getHorizonApiMasters() as TData, ...options });
/**
* Get all of the monitored tags and their job counts
* @returns unknown
* @throws ApiError
*/
export const useMonitoringServiceGetHorizonApiMonitoring = <TData = Common.MonitoringServiceGetHorizonApiMonitoringDefaultResponse, TError = unknown, TQueryKey extends Array<unknown> = unknown[]>(queryKey?: TQueryKey, options?: Omit<UseQueryOptions<TData, TError>, "queryKey" | "queryFn">) => useQuery<TData, TError>({ queryKey: Common.UseMonitoringServiceGetHorizonApiMonitoringKeyFn(queryKey), queryFn: () => MonitoringService.getHorizonApiMonitoring() as TData, ...options });
/**
* Paginate the jobs for a given tag
* @param data The data for the request.
* @param data.tag
* @param data.limit
* @returns unknown
* @throws ApiError
*/
export const useMonitoringServiceGetHorizonApiMonitoringByTag = <TData = Common.MonitoringServiceGetHorizonApiMonitoringByTagDefaultResponse, TError = unknown, TQueryKey extends Array<unknown> = unknown[]>({ limit, tag }: {
  limit?: string;
  tag: string;
}, queryKey?: TQueryKey, options?: Omit<UseQueryOptions<TData, TError>, "queryKey" | "queryFn">) => useQuery<TData, TError>({ queryKey: Common.UseMonitoringServiceGetHorizonApiMonitoringByTagKeyFn({ limit, tag }, queryKey), queryFn: () => MonitoringService.getHorizonApiMonitoringByTag({ limit, tag }) as TData, ...options });
/**
* Get a collection of movies
* @param data The data for the request.
* @param data.library The library slug
* @returns unknown Paginated set of `MovieResource`
* @throws ApiError
*/
export const useMovieServiceGetApiLibrariesByLibraryMovies = <TData = Common.MovieServiceGetApiLibrariesByLibraryMoviesDefaultResponse, TError = unknown, TQueryKey extends Array<unknown> = unknown[]>({ library }: {
  library: string;
}, queryKey?: TQueryKey, options?: Omit<UseQueryOptions<TData, TError>, "queryKey" | "queryFn">) => useQuery<TData, TError>({ queryKey: Common.UseMovieServiceGetApiLibrariesByLibraryMoviesKeyFn({ library }, queryKey), queryFn: () => MovieService.getApiLibrariesByLibraryMovies({ library }) as TData, ...options });
/**
* Get a movie
* @param data The data for the request.
* @param data.library The library slug
* @param data.movie The movie slug
* @returns MovieResource `MovieResource`
* @throws ApiError
*/
export const useMovieServiceGetApiLibrariesByLibraryMoviesByMovie = <TData = Common.MovieServiceGetApiLibrariesByLibraryMoviesByMovieDefaultResponse, TError = unknown, TQueryKey extends Array<unknown> = unknown[]>({ library, movie }: {
  library: string;
  movie: string;
}, queryKey?: TQueryKey, options?: Omit<UseQueryOptions<TData, TError>, "queryKey" | "queryFn">) => useQuery<TData, TError>({ queryKey: Common.UseMovieServiceGetApiLibrariesByLibraryMoviesByMovieKeyFn({ library, movie }, queryKey), queryFn: () => MovieService.getApiLibrariesByLibraryMoviesByMovie({ library, movie }) as TData, ...options });
/**
* Get status
* @returns unknown
* @throws ApiError
*/
export const useOpCacheServiceGetApiOpcacheStatus = <TData = Common.OpCacheServiceGetApiOpcacheStatusDefaultResponse, TError = unknown, TQueryKey extends Array<unknown> = unknown[]>(queryKey?: TQueryKey, options?: Omit<UseQueryOptions<TData, TError>, "queryKey" | "queryFn">) => useQuery<TData, TError>({ queryKey: Common.UseOpCacheServiceGetApiOpcacheStatusKeyFn(queryKey), queryFn: () => OpCacheService.getApiOpcacheStatus() as TData, ...options });
/**
* Get config
* @returns unknown
* @throws ApiError
*/
export const useOpCacheServiceGetApiOpcacheConfig = <TData = Common.OpCacheServiceGetApiOpcacheConfigDefaultResponse, TError = unknown, TQueryKey extends Array<unknown> = unknown[]>(queryKey?: TQueryKey, options?: Omit<UseQueryOptions<TData, TError>, "queryKey" | "queryFn">) => useQuery<TData, TError>({ queryKey: Common.UseOpCacheServiceGetApiOpcacheConfigKeyFn(queryKey), queryFn: () => OpCacheService.getApiOpcacheConfig() as TData, ...options });
/**
* Get all of the pending jobs
* @param data The data for the request.
* @param data.startingAt
* @returns unknown
* @throws ApiError
*/
export const usePendingJobsServiceGetHorizonApiJobsPending = <TData = Common.PendingJobsServiceGetHorizonApiJobsPendingDefaultResponse, TError = unknown, TQueryKey extends Array<unknown> = unknown[]>({ startingAt }: {
  startingAt?: string;
} = {}, queryKey?: TQueryKey, options?: Omit<UseQueryOptions<TData, TError>, "queryKey" | "queryFn">) => useQuery<TData, TError>({ queryKey: Common.UsePendingJobsServiceGetHorizonApiJobsPendingKeyFn({ startingAt }, queryKey), queryFn: () => PendingJobsService.getHorizonApiJobsPending({ startingAt }) as TData, ...options });
/**
* Get a collection of playlists
* @returns unknown Paginated set of `PlaylistResource`
* @throws ApiError
*/
export const usePlaylistServiceGetApiPlaylists = <TData = Common.PlaylistServiceGetApiPlaylistsDefaultResponse, TError = unknown, TQueryKey extends Array<unknown> = unknown[]>(queryKey?: TQueryKey, options?: Omit<UseQueryOptions<TData, TError>, "queryKey" | "queryFn">) => useQuery<TData, TError>({ queryKey: Common.UsePlaylistServiceGetApiPlaylistsKeyFn(queryKey), queryFn: () => PlaylistService.getApiPlaylists() as TData, ...options });
/**
* Show a playlist
* @param data The data for the request.
* @param data.playlist The playlist public id
* @param data.relations
* @returns PlaylistResource `PlaylistResource`
* @throws ApiError
*/
export const usePlaylistServiceGetApiPlaylistsByPlaylist = <TData = Common.PlaylistServiceGetApiPlaylistsByPlaylistDefaultResponse, TError = unknown, TQueryKey extends Array<unknown> = unknown[]>({ playlist, relations }: {
  playlist: string;
  relations?: string;
}, queryKey?: TQueryKey, options?: Omit<UseQueryOptions<TData, TError>, "queryKey" | "queryFn">) => useQuery<TData, TError>({ queryKey: Common.UsePlaylistServiceGetApiPlaylistsByPlaylistKeyFn({ playlist, relations }, queryKey), queryFn: () => PlaylistService.getApiPlaylistsByPlaylist({ playlist, relations }) as TData, ...options });
/**
* Get statistics
* @param data The data for the request.
* @param data.playlist The playlist public id
* @returns PlaylistStatistic `PlaylistStatistic`
* @throws ApiError
*/
export const usePlaylistServiceGetApiPlaylistsByPlaylistStatistics = <TData = Common.PlaylistServiceGetApiPlaylistsByPlaylistStatisticsDefaultResponse, TError = unknown, TQueryKey extends Array<unknown> = unknown[]>({ playlist }: {
  playlist: string;
}, queryKey?: TQueryKey, options?: Omit<UseQueryOptions<TData, TError>, "queryKey" | "queryFn">) => useQuery<TData, TError>({ queryKey: Common.UsePlaylistServiceGetApiPlaylistsByPlaylistStatisticsKeyFn({ playlist }, queryKey), queryFn: () => PlaylistService.getApiPlaylistsByPlaylistStatistics({ playlist }) as TData, ...options });
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
export const useQueueServiceGetApiQueueMetrics = <TData = Common.QueueServiceGetApiQueueMetricsDefaultResponse, TError = unknown, TQueryKey extends Array<unknown> = unknown[]>({ limit, name, page, queue, queuedFirst, status }: {
  limit?: number;
  name?: string;
  page?: number;
  queue?: string;
  queuedFirst?: boolean;
  status?: "running" | "succeeded" | "failed" | "stale" | "queued";
} = {}, queryKey?: TQueryKey, options?: Omit<UseQueryOptions<TData, TError>, "queryKey" | "queryFn">) => useQuery<TData, TError>({ queryKey: Common.UseQueueServiceGetApiQueueMetricsKeyFn({ limit, name, page, queue, queuedFirst, status }, queryKey), queryFn: () => QueueService.getApiQueueMetrics({ limit, name, page, queue, queuedFirst, status }) as TData, ...options });
/**
* Get a list of queue names
* @returns unknown
* @throws ApiError
*/
export const useQueueServiceGetApiQueueMetricsQueues = <TData = Common.QueueServiceGetApiQueueMetricsQueuesDefaultResponse, TError = unknown, TQueryKey extends Array<unknown> = unknown[]>(queryKey?: TQueryKey, options?: Omit<UseQueryOptions<TData, TError>, "queryKey" | "queryFn">) => useQuery<TData, TError>({ queryKey: Common.UseQueueServiceGetApiQueueMetricsQueuesKeyFn(queryKey), queryFn: () => QueueService.getApiQueueMetricsQueues() as TData, ...options });
/**
* Get a metrics collection
* @param data The data for the request.
* @param data.aggregateDays
* @returns unknown
* @throws ApiError
*/
export const useQueueServiceGetApiQueueMetricsMetrics = <TData = Common.QueueServiceGetApiQueueMetricsMetricsDefaultResponse, TError = unknown, TQueryKey extends Array<unknown> = unknown[]>({ aggregateDays }: {
  aggregateDays?: number;
} = {}, queryKey?: TQueryKey, options?: Omit<UseQueryOptions<TData, TError>, "queryKey" | "queryFn">) => useQuery<TData, TError>({ queryKey: Common.UseQueueServiceGetApiQueueMetricsMetricsKeyFn({ aggregateDays }, queryKey), queryFn: () => QueueService.getApiQueueMetricsMetrics({ aggregateDays }) as TData, ...options });
/**
* Get all of the measured queues
* @returns unknown
* @throws ApiError
*/
export const useQueueMetricsServiceGetHorizonApiMetricsQueues = <TData = Common.QueueMetricsServiceGetHorizonApiMetricsQueuesDefaultResponse, TError = unknown, TQueryKey extends Array<unknown> = unknown[]>(queryKey?: TQueryKey, options?: Omit<UseQueryOptions<TData, TError>, "queryKey" | "queryFn">) => useQuery<TData, TError>({ queryKey: Common.UseQueueMetricsServiceGetHorizonApiMetricsQueuesKeyFn(queryKey), queryFn: () => QueueMetricsService.getHorizonApiMetricsQueues() as TData, ...options });
/**
* Get metrics for a given queue
* @param data The data for the request.
* @param data.id
* @returns unknown
* @throws ApiError
*/
export const useQueueMetricsServiceGetHorizonApiMetricsQueuesById = <TData = Common.QueueMetricsServiceGetHorizonApiMetricsQueuesByIdDefaultResponse, TError = unknown, TQueryKey extends Array<unknown> = unknown[]>({ id }: {
  id: string;
}, queryKey?: TQueryKey, options?: Omit<UseQueryOptions<TData, TError>, "queryKey" | "queryFn">) => useQuery<TData, TError>({ queryKey: Common.UseQueueMetricsServiceGetHorizonApiMetricsQueuesByIdKeyFn({ id }, queryKey), queryFn: () => QueueMetricsService.getHorizonApiMetricsQueuesById({ id }) as TData, ...options });
/**
* @returns unknown
* @throws ApiError
*/
export const useSchemaServiceGetApiSchemasMusicbrainz = <TData = Common.SchemaServiceGetApiSchemasMusicbrainzDefaultResponse, TError = unknown, TQueryKey extends Array<unknown> = unknown[]>(queryKey?: TQueryKey, options?: Omit<UseQueryOptions<TData, TError>, "queryKey" | "queryFn">) => useQuery<TData, TError>({ queryKey: Common.UseSchemaServiceGetApiSchemasMusicbrainzKeyFn(queryKey), queryFn: () => SchemaService.getApiSchemasMusicbrainz() as TData, ...options });
/**
* Get all of the silenced jobs
* @param data The data for the request.
* @param data.startingAt
* @returns unknown
* @throws ApiError
*/
export const useSilencedJobsServiceGetHorizonApiJobsSilenced = <TData = Common.SilencedJobsServiceGetHorizonApiJobsSilencedDefaultResponse, TError = unknown, TQueryKey extends Array<unknown> = unknown[]>({ startingAt }: {
  startingAt?: string;
} = {}, queryKey?: TQueryKey, options?: Omit<UseQueryOptions<TData, TError>, "queryKey" | "queryFn">) => useQuery<TData, TError>({ queryKey: Common.UseSilencedJobsServiceGetHorizonApiJobsSilencedKeyFn({ startingAt }, queryKey), queryFn: () => SilencedJobsService.getHorizonApiJobsSilenced({ startingAt }) as TData, ...options });
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
export const useSongServiceGetApiLibrariesByLibrarySongs = <TData = Common.SongServiceGetApiLibrariesByLibrarySongsDefaultResponse, TError = unknown, TQueryKey extends Array<unknown> = unknown[]>({ genreNames, genreSlugs, library, limit, page, relations }: {
  genreNames?: string;
  genreSlugs?: string;
  library: string;
  limit?: number;
  page?: number;
  relations?: string;
}, queryKey?: TQueryKey, options?: Omit<UseQueryOptions<TData, TError>, "queryKey" | "queryFn">) => useQuery<TData, TError>({ queryKey: Common.UseSongServiceGetApiLibrariesByLibrarySongsKeyFn({ genreNames, genreSlugs, library, limit, page, relations }, queryKey), queryFn: () => SongService.getApiLibrariesByLibrarySongs({ genreNames, genreSlugs, library, limit, page, relations }) as TData, ...options });
/**
* Get a song by public id
* @param data The data for the request.
* @param data.library The library slug
* @param data.publicId
* @param data.relations
* @returns SongResource `SongResource`
* @throws ApiError
*/
export const useSongServiceGetApiLibrariesByLibrarySongsByPublicId = <TData = Common.SongServiceGetApiLibrariesByLibrarySongsByPublicIdDefaultResponse, TError = unknown, TQueryKey extends Array<unknown> = unknown[]>({ library, publicId, relations }: {
  library: string;
  publicId: string;
  relations?: string;
}, queryKey?: TQueryKey, options?: Omit<UseQueryOptions<TData, TError>, "queryKey" | "queryFn">) => useQuery<TData, TError>({ queryKey: Common.UseSongServiceGetApiLibrariesByLibrarySongsByPublicIdKeyFn({ library, publicId, relations }, queryKey), queryFn: () => SongService.getApiLibrariesByLibrarySongsByPublicId({ library, publicId, relations }) as TData, ...options });
/**
* Direct stream the song.
* Requires token with "access-stream"
* @param data The data for the request.
* @param data.song The song public id
* @returns unknown
* @throws ApiError
*/
export const useStreamServiceGetApiStreamSongBySongDirect = <TData = Common.StreamServiceGetApiStreamSongBySongDirectDefaultResponse, TError = unknown, TQueryKey extends Array<unknown> = unknown[]>({ song }: {
  song: string;
}, queryKey?: TQueryKey, options?: Omit<UseQueryOptions<TData, TError>, "queryKey" | "queryFn">) => useQuery<TData, TError>({ queryKey: Common.UseStreamServiceGetApiStreamSongBySongDirectKeyFn({ song }, queryKey), queryFn: () => StreamService.getApiStreamSongBySongDirect({ song }) as TData, ...options });
/**
* Get php info
* @returns unknown
* @throws ApiError
*/
export const useSystemInfoServiceGetApiSystemInfo = <TData = Common.SystemInfoServiceGetApiSystemInfoDefaultResponse, TError = unknown, TQueryKey extends Array<unknown> = unknown[]>(queryKey?: TQueryKey, options?: Omit<UseQueryOptions<TData, TError>, "queryKey" | "queryFn">) => useQuery<TData, TError>({ queryKey: Common.UseSystemInfoServiceGetApiSystemInfoKeyFn(queryKey), queryFn: () => SystemInfoService.getApiSystemInfo() as TData, ...options });
/**
* @returns unknown
* @throws ApiError
*/
export const useSystemInfoServiceGetApiSystemInfoSys = <TData = Common.SystemInfoServiceGetApiSystemInfoSysDefaultResponse, TError = unknown, TQueryKey extends Array<unknown> = unknown[]>(queryKey?: TQueryKey, options?: Omit<UseQueryOptions<TData, TError>, "queryKey" | "queryFn">) => useQuery<TData, TError>({ queryKey: Common.UseSystemInfoServiceGetApiSystemInfoSysKeyFn(queryKey), queryFn: () => SystemInfoService.getApiSystemInfoSys() as TData, ...options });
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
export const useUserServiceGetApiUsers = <TData = Common.UserServiceGetApiUsersDefaultResponse, TError = unknown, TQueryKey extends Array<unknown> = unknown[]>({ filterModes, filters, globalFilter, limit, page, sorting }: {
  filterModes?: string;
  filters?: string;
  globalFilter?: string;
  limit?: number;
  page?: number;
  sorting?: string;
} = {}, queryKey?: TQueryKey, options?: Omit<UseQueryOptions<TData, TError>, "queryKey" | "queryFn">) => useQuery<TData, TError>({ queryKey: Common.UseUserServiceGetApiUsersKeyFn({ filterModes, filters, globalFilter, limit, page, sorting }, queryKey), queryFn: () => UserService.getApiUsers({ filterModes, filters, globalFilter, limit, page, sorting }) as TData, ...options });
/**
* Get small user detail info
* @param data The data for the request.
* @param data.user The user ID
* @returns UserResource `UserResource`
* @throws ApiError
*/
export const useUserServiceGetApiUsersByUser = <TData = Common.UserServiceGetApiUsersByUserDefaultResponse, TError = unknown, TQueryKey extends Array<unknown> = unknown[]>({ user }: {
  user: number;
}, queryKey?: TQueryKey, options?: Omit<UseQueryOptions<TData, TError>, "queryKey" | "queryFn">) => useQuery<TData, TError>({ queryKey: Common.UseUserServiceGetApiUsersByUserKeyFn({ user }, queryKey), queryFn: () => UserService.getApiUsersByUser({ user }) as TData, ...options });
/**
* Get the authenticated user
* @returns UserResource `UserResource`
* @throws ApiError
*/
export const useUserServiceGetApiUsersMe = <TData = Common.UserServiceGetApiUsersMeDefaultResponse, TError = unknown, TQueryKey extends Array<unknown> = unknown[]>(queryKey?: TQueryKey, options?: Omit<UseQueryOptions<TData, TError>, "queryKey" | "queryFn">) => useQuery<TData, TError>({ queryKey: Common.UseUserServiceGetApiUsersMeKeyFn(queryKey), queryFn: () => UserService.getApiUsersMe() as TData, ...options });
/**
* Get a collection of tokens
* @param data The data for the request.
* @param data.user
* @param data.page
* @param data.perPage
* @returns unknown Paginated set of `PersonalAccessTokenViewResource`
* @throws ApiError
*/
export const useUserTokenServiceGetApiUsersTokensByUser = <TData = Common.UserTokenServiceGetApiUsersTokensByUserDefaultResponse, TError = unknown, TQueryKey extends Array<unknown> = unknown[]>({ page, perPage, user }: {
  page?: number;
  perPage?: number;
  user: string;
}, queryKey?: TQueryKey, options?: Omit<UseQueryOptions<TData, TError>, "queryKey" | "queryFn">) => useQuery<TData, TError>({ queryKey: Common.UseUserTokenServiceGetApiUsersTokensByUserKeyFn({ page, perPage, user }, queryKey), queryFn: () => UserTokenService.getApiUsersTokensByUser({ page, perPage, user }) as TData, ...options });
/**
* Get the current queue workload for the application
* @returns unknown
* @throws ApiError
*/
export const useWorkloadServiceGetHorizonApiWorkload = <TData = Common.WorkloadServiceGetHorizonApiWorkloadDefaultResponse, TError = unknown, TQueryKey extends Array<unknown> = unknown[]>(queryKey?: TQueryKey, options?: Omit<UseQueryOptions<TData, TError>, "queryKey" | "queryFn">) => useQuery<TData, TError>({ queryKey: Common.UseWorkloadServiceGetHorizonApiWorkloadKeyFn(queryKey), queryFn: () => WorkloadService.getHorizonApiWorkload() as TData, ...options });
/**
* Login
* @param data The data for the request.
* @param data.requestBody
* @returns unknown
* @throws ApiError
*/
export const useAuthServicePostApiAuthLogin = <TData = Common.AuthServicePostApiAuthLoginMutationResult, TError = unknown, TContext = unknown>(options?: Omit<UseMutationOptions<TData, TError, {
  requestBody: LoginRequest;
}, TContext>, "mutationFn">) => useMutation<TData, TError, {
  requestBody: LoginRequest;
}, TContext>({ mutationFn: ({ requestBody }) => AuthService.postApiAuthLogin({ requestBody }) as unknown as Promise<TData>, ...options });
/**
* Refresh token
* Needs refresh token with ability "issue-access-token"
* @returns unknown
* @throws ApiError
*/
export const useAuthServicePostApiAuthRefreshToken = <TData = Common.AuthServicePostApiAuthRefreshTokenMutationResult, TError = unknown, TContext = unknown>(options?: Omit<UseMutationOptions<TData, TError, void, TContext>, "mutationFn">) => useMutation<TData, TError, void, TContext>({ mutationFn: () => AuthService.postApiAuthRefreshToken() as unknown as Promise<TData>, ...options });
/**
* Get a stream token
* Needs refresh token with ability "issue-access-token"
* @returns unknown
* @throws ApiError
*/
export const useAuthServicePostApiAuthStreamToken = <TData = Common.AuthServicePostApiAuthStreamTokenMutationResult, TError = unknown, TContext = unknown>(options?: Omit<UseMutationOptions<TData, TError, void, TContext>, "mutationFn">) => useMutation<TData, TError, void, TContext>({ mutationFn: () => AuthService.postApiAuthStreamToken() as unknown as Promise<TData>, ...options });
/**
* Register
* @param data The data for the request.
* @param data.requestBody
* @returns unknown
* @throws ApiError
*/
export const useAuthServicePostApiAuthRegister = <TData = Common.AuthServicePostApiAuthRegisterMutationResult, TError = unknown, TContext = unknown>(options?: Omit<UseMutationOptions<TData, TError, {
  requestBody: RegisterRequest;
}, TContext>, "mutationFn">) => useMutation<TData, TError, {
  requestBody: RegisterRequest;
}, TContext>({ mutationFn: ({ requestBody }) => AuthService.postApiAuthRegister({ requestBody }) as unknown as Promise<TData>, ...options });
/**
* Request reset password link
* @param data The data for the request.
* @param data.requestBody
* @returns unknown
* @throws ApiError
*/
export const useAuthServicePostApiAuthForgotPassword = <TData = Common.AuthServicePostApiAuthForgotPasswordMutationResult, TError = unknown, TContext = unknown>(options?: Omit<UseMutationOptions<TData, TError, {
  requestBody: ForgotPasswordRequest;
}, TContext>, "mutationFn">) => useMutation<TData, TError, {
  requestBody: ForgotPasswordRequest;
}, TContext>({ mutationFn: ({ requestBody }) => AuthService.postApiAuthForgotPassword({ requestBody }) as unknown as Promise<TData>, ...options });
/**
* Reset password
* @param data The data for the request.
* @param data.requestBody
* @returns unknown
* @throws ApiError
*/
export const useAuthServicePostApiAuthResetPassword = <TData = Common.AuthServicePostApiAuthResetPasswordMutationResult, TError = unknown, TContext = unknown>(options?: Omit<UseMutationOptions<TData, TError, {
  requestBody: ResetPasswordRequest;
}, TContext>, "mutationFn">) => useMutation<TData, TError, {
  requestBody: ResetPasswordRequest;
}, TContext>({ mutationFn: ({ requestBody }) => AuthService.postApiAuthResetPassword({ requestBody }) as unknown as Promise<TData>, ...options });
/**
* Verify email
* @param data The data for the request.
* @param data.id
* @param data.hash
* @returns UserResource `UserResource`
* @throws ApiError
*/
export const useAuthServicePostApiAuthVerifyByIdByHash = <TData = Common.AuthServicePostApiAuthVerifyByIdByHashMutationResult, TError = unknown, TContext = unknown>(options?: Omit<UseMutationOptions<TData, TError, {
  hash: string;
  id: number;
}, TContext>, "mutationFn">) => useMutation<TData, TError, {
  hash: string;
  id: number;
}, TContext>({ mutationFn: ({ hash, id }) => AuthService.postApiAuthVerifyByIdByHash({ hash, id }) as unknown as Promise<TData>, ...options });
/**
* Logout
* Invalidates the current session
* @param data The data for the request.
* @param data.requestBody
* @returns void No content
* @throws ApiError
*/
export const useAuthServicePostApiAuthLogout = <TData = Common.AuthServicePostApiAuthLogoutMutationResult, TError = unknown, TContext = unknown>(options?: Omit<UseMutationOptions<TData, TError, {
  requestBody?: LogoutRequest & { refreshToken?: string; };
}, TContext>, "mutationFn">) => useMutation<TData, TError, {
  requestBody?: LogoutRequest & { refreshToken?: string; };
}, TContext>({ mutationFn: ({ requestBody }) => AuthService.postApiAuthLogout({ requestBody }) as unknown as Promise<TData>, ...options });
/**
* Login with a passkey
* @param data The data for the request.
* @param data.requestBody
* @returns unknown
* @throws ApiError
*/
export const useAuthServicePostWebauthnPasskey = <TData = Common.AuthServicePostWebauthnPasskeyMutationResult, TError = unknown, TContext = unknown>(options?: Omit<UseMutationOptions<TData, TError, {
  requestBody: AuthenticateUsingPasskeyRequest;
}, TContext>, "mutationFn">) => useMutation<TData, TError, {
  requestBody: AuthenticateUsingPasskeyRequest;
}, TContext>({ mutationFn: ({ requestBody }) => AuthService.postWebauthnPasskey({ requestBody }) as unknown as Promise<TData>, ...options });
/**
* Register passkey
* @param data The data for the request.
* @param data.requestBody
* @returns unknown
* @throws ApiError
*/
export const useAuthServicePostWebauthnPasskeyRegister = <TData = Common.AuthServicePostWebauthnPasskeyRegisterMutationResult, TError = unknown, TContext = unknown>(options?: Omit<UseMutationOptions<TData, TError, {
  requestBody: StorePasskeyRequest;
}, TContext>, "mutationFn">) => useMutation<TData, TError, {
  requestBody: StorePasskeyRequest;
}, TContext>({ mutationFn: ({ requestBody }) => AuthService.postWebauthnPasskeyRegister({ requestBody }) as unknown as Promise<TData>, ...options });
/**
* Login with a passkey
* @param data The data for the request.
* @param data.requestBody
* @returns unknown
* @throws ApiError
*/
export const usePasskeyServicePostWebauthnPasskey = <TData = Common.PasskeyServicePostWebauthnPasskeyMutationResult, TError = unknown, TContext = unknown>(options?: Omit<UseMutationOptions<TData, TError, {
  requestBody: AuthenticateUsingPasskeyRequest;
}, TContext>, "mutationFn">) => useMutation<TData, TError, {
  requestBody: AuthenticateUsingPasskeyRequest;
}, TContext>({ mutationFn: ({ requestBody }) => PasskeyService.postWebauthnPasskey({ requestBody }) as unknown as Promise<TData>, ...options });
/**
* Register passkey
* @param data The data for the request.
* @param data.requestBody
* @returns unknown
* @throws ApiError
*/
export const usePasskeyServicePostWebauthnPasskeyRegister = <TData = Common.PasskeyServicePostWebauthnPasskeyRegisterMutationResult, TError = unknown, TContext = unknown>(options?: Omit<UseMutationOptions<TData, TError, {
  requestBody: StorePasskeyRequest;
}, TContext>, "mutationFn">) => useMutation<TData, TError, {
  requestBody: StorePasskeyRequest;
}, TContext>({ mutationFn: ({ requestBody }) => PasskeyService.postWebauthnPasskeyRegister({ requestBody }) as unknown as Promise<TData>, ...options });
/**
* Retry the given batch
* @param data The data for the request.
* @param data.id
* @returns unknown
* @throws ApiError
*/
export const useBatchesServicePostHorizonApiBatchesRetryById = <TData = Common.BatchesServicePostHorizonApiBatchesRetryByIdMutationResult, TError = unknown, TContext = unknown>(options?: Omit<UseMutationOptions<TData, TError, {
  id: string;
}, TContext>, "mutationFn">) => useMutation<TData, TError, {
  id: string;
}, TContext>({ mutationFn: ({ id }) => BatchesService.postHorizonApiBatchesRetryById({ id }) as unknown as Promise<TData>, ...options });
/**
* @param data The data for the request.
* @param data.fileIdentifier
* @returns unknown
* @throws ApiError
*/
export const useFilesServicePostSystemLogViewerApiFilesByFileIdentifierClearCache = <TData = Common.FilesServicePostSystemLogViewerApiFilesByFileIdentifierClearCacheMutationResult, TError = unknown, TContext = unknown>(options?: Omit<UseMutationOptions<TData, TError, {
  fileIdentifier: string;
}, TContext>, "mutationFn">) => useMutation<TData, TError, {
  fileIdentifier: string;
}, TContext>({ mutationFn: ({ fileIdentifier }) => FilesService.postSystemLogViewerApiFilesByFileIdentifierClearCache({ fileIdentifier }) as unknown as Promise<TData>, ...options });
/**
* @returns unknown
* @throws ApiError
*/
export const useFilesServicePostSystemLogViewerApiClearCacheAll = <TData = Common.FilesServicePostSystemLogViewerApiClearCacheAllMutationResult, TError = unknown, TContext = unknown>(options?: Omit<UseMutationOptions<TData, TError, void, TContext>, "mutationFn">) => useMutation<TData, TError, void, TContext>({ mutationFn: () => FilesService.postSystemLogViewerApiClearCacheAll() as unknown as Promise<TData>, ...options });
/**
* @param data The data for the request.
* @param data.requestBody
* @returns unknown
* @throws ApiError
*/
export const useFilesServicePostSystemLogViewerApiDeleteMultipleFiles = <TData = Common.FilesServicePostSystemLogViewerApiDeleteMultipleFilesMutationResult, TError = unknown, TContext = unknown>(options?: Omit<UseMutationOptions<TData, TError, {
  requestBody?: { files?: string; };
}, TContext>, "mutationFn">) => useMutation<TData, TError, {
  requestBody?: { files?: string; };
}, TContext>({ mutationFn: ({ requestBody }) => FilesService.postSystemLogViewerApiDeleteMultipleFiles({ requestBody }) as unknown as Promise<TData>, ...options });
/**
* @param data The data for the request.
* @param data.folderIdentifier
* @returns unknown
* @throws ApiError
*/
export const useFoldersServicePostSystemLogViewerApiFoldersByFolderIdentifierClearCache = <TData = Common.FoldersServicePostSystemLogViewerApiFoldersByFolderIdentifierClearCacheMutationResult, TError = unknown, TContext = unknown>(options?: Omit<UseMutationOptions<TData, TError, {
  folderIdentifier: string;
}, TContext>, "mutationFn">) => useMutation<TData, TError, {
  folderIdentifier: string;
}, TContext>({ mutationFn: ({ folderIdentifier }) => FoldersService.postSystemLogViewerApiFoldersByFolderIdentifierClearCache({ folderIdentifier }) as unknown as Promise<TData>, ...options });
/**
* Scan a library
* @param data The data for the request.
* @param data.slug
* @returns unknown
* @throws ApiError
*/
export const useJobServicePostApiJobsScanLibraryBySlug = <TData = Common.JobServicePostApiJobsScanLibraryBySlugMutationResult, TError = unknown, TContext = unknown>(options?: Omit<UseMutationOptions<TData, TError, {
  slug: string;
}, TContext>, "mutationFn">) => useMutation<TData, TError, {
  slug: string;
}, TContext>({ mutationFn: ({ slug }) => JobService.postApiJobsScanLibraryBySlug({ slug }) as unknown as Promise<TData>, ...options });
/**
* Create a library
* @param data The data for the request.
* @param data.requestBody
* @returns LibraryResource `LibraryResource`
* @throws ApiError
*/
export const useLibraryServicePostApiLibraries = <TData = Common.LibraryServicePostApiLibrariesMutationResult, TError = unknown, TContext = unknown>(options?: Omit<UseMutationOptions<TData, TError, {
  requestBody: CreateLibraryRequest;
}, TContext>, "mutationFn">) => useMutation<TData, TError, {
  requestBody: CreateLibraryRequest;
}, TContext>({ mutationFn: ({ requestBody }) => LibraryService.postApiLibraries({ requestBody }) as unknown as Promise<TData>, ...options });
/**
* Start monitoring the given tag
* @returns unknown
* @throws ApiError
*/
export const useMonitoringServicePostHorizonApiMonitoring = <TData = Common.MonitoringServicePostHorizonApiMonitoringMutationResult, TError = unknown, TContext = unknown>(options?: Omit<UseMutationOptions<TData, TError, void, TContext>, "mutationFn">) => useMutation<TData, TError, void, TContext>({ mutationFn: () => MonitoringService.postHorizonApiMonitoring() as unknown as Promise<TData>, ...options });
/**
* Clear
* @returns unknown
* @throws ApiError
*/
export const useOpCacheServicePostApiOpcacheClear = <TData = Common.OpCacheServicePostApiOpcacheClearMutationResult, TError = unknown, TContext = unknown>(options?: Omit<UseMutationOptions<TData, TError, void, TContext>, "mutationFn">) => useMutation<TData, TError, void, TContext>({ mutationFn: () => OpCacheService.postApiOpcacheClear() as unknown as Promise<TData>, ...options });
/**
* Compile cache
* @param data The data for the request.
* @param data.force
* @returns unknown
* @throws ApiError
*/
export const useOpCacheServicePostApiOpcacheCompile = <TData = Common.OpCacheServicePostApiOpcacheCompileMutationResult, TError = unknown, TContext = unknown>(options?: Omit<UseMutationOptions<TData, TError, {
  force?: string;
}, TContext>, "mutationFn">) => useMutation<TData, TError, {
  force?: string;
}, TContext>({ mutationFn: ({ force }) => OpCacheService.postApiOpcacheCompile({ force }) as unknown as Promise<TData>, ...options });
/**
* Create a playlist
* @param data The data for the request.
* @param data.requestBody
* @returns PlaylistResource `PlaylistResource`
* @throws ApiError
*/
export const usePlaylistServicePostApiPlaylists = <TData = Common.PlaylistServicePostApiPlaylistsMutationResult, TError = unknown, TContext = unknown>(options?: Omit<UseMutationOptions<TData, TError, {
  requestBody: CreatePlaylistRequest;
}, TContext>, "mutationFn">) => useMutation<TData, TError, {
  requestBody: CreatePlaylistRequest;
}, TContext>({ mutationFn: ({ requestBody }) => PlaylistService.postApiPlaylists({ requestBody }) as unknown as Promise<TData>, ...options });
/**
* Add a song
* @param data The data for the request.
* @param data.playlist The playlist public id
* @param data.song The song public id
* @returns unknown
* @throws ApiError
*/
export const usePlaylistServicePostApiPlaylistsByPlaylistSongsBySong = <TData = Common.PlaylistServicePostApiPlaylistsByPlaylistSongsBySongMutationResult, TError = unknown, TContext = unknown>(options?: Omit<UseMutationOptions<TData, TError, {
  playlist: string;
  song: string;
}, TContext>, "mutationFn">) => useMutation<TData, TError, {
  playlist: string;
  song: string;
}, TContext>({ mutationFn: ({ playlist, song }) => PlaylistService.postApiPlaylistsByPlaylistSongsBySong({ playlist, song }) as unknown as Promise<TData>, ...options });
/**
* Reorder songs
* @param data The data for the request.
* @param data.playlist The playlist public id
* @param data.requestBody
* @returns unknown
* @throws ApiError
*/
export const usePlaylistServicePostApiPlaylistsByPlaylistReorder = <TData = Common.PlaylistServicePostApiPlaylistsByPlaylistReorderMutationResult, TError = unknown, TContext = unknown>(options?: Omit<UseMutationOptions<TData, TError, {
  playlist: string;
  requestBody: { song_ids: Array<(number)>; };
}, TContext>, "mutationFn">) => useMutation<TData, TError, {
  playlist: string;
  requestBody: { song_ids: Array<(number)>; };
}, TContext>({ mutationFn: ({ playlist, requestBody }) => PlaylistService.postApiPlaylistsByPlaylistReorder({ playlist, requestBody }) as unknown as Promise<TData>, ...options });
/**
* Add collaborator
* @param data The data for the request.
* @param data.playlist The playlist public id
* @param data.requestBody
* @returns unknown
* @throws ApiError
*/
export const usePlaylistServicePostApiPlaylistsByPlaylistCollaborators = <TData = Common.PlaylistServicePostApiPlaylistsByPlaylistCollaboratorsMutationResult, TError = unknown, TContext = unknown>(options?: Omit<UseMutationOptions<TData, TError, {
  playlist: string;
  requestBody: { user_id: number; role?: "editor" | "contributor"; };
}, TContext>, "mutationFn">) => useMutation<TData, TError, {
  playlist: string;
  requestBody: { user_id: number; role?: "editor" | "contributor"; };
}, TContext>({ mutationFn: ({ playlist, requestBody }) => PlaylistService.postApiPlaylistsByPlaylistCollaborators({ playlist, requestBody }) as unknown as Promise<TData>, ...options });
/**
* Clone playlist
* @param data The data for the request.
* @param data.playlist The playlist public id
* @returns PlaylistResource `PlaylistResource`
* @throws ApiError
*/
export const usePlaylistServicePostApiPlaylistsByPlaylistClone = <TData = Common.PlaylistServicePostApiPlaylistsByPlaylistCloneMutationResult, TError = unknown, TContext = unknown>(options?: Omit<UseMutationOptions<TData, TError, {
  playlist: string;
}, TContext>, "mutationFn">) => useMutation<TData, TError, {
  playlist: string;
}, TContext>({ mutationFn: ({ playlist }) => PlaylistService.postApiPlaylistsByPlaylistClone({ playlist }) as unknown as Promise<TData>, ...options });
/**
* Statistics - Record view
* @param data The data for the request.
* @param data.playlist The playlist public id
* @returns unknown
* @throws ApiError
*/
export const usePlaylistServicePostApiPlaylistsByPlaylistStatisticsRecordView = <TData = Common.PlaylistServicePostApiPlaylistsByPlaylistStatisticsRecordViewMutationResult, TError = unknown, TContext = unknown>(options?: Omit<UseMutationOptions<TData, TError, {
  playlist: string;
}, TContext>, "mutationFn">) => useMutation<TData, TError, {
  playlist: string;
}, TContext>({ mutationFn: ({ playlist }) => PlaylistService.postApiPlaylistsByPlaylistStatisticsRecordView({ playlist }) as unknown as Promise<TData>, ...options });
/**
* Statistics - Record play
* @param data The data for the request.
* @param data.playlist The playlist public id
* @returns unknown
* @throws ApiError
*/
export const usePlaylistServicePostApiPlaylistsByPlaylistStatisticsRecordPlay = <TData = Common.PlaylistServicePostApiPlaylistsByPlaylistStatisticsRecordPlayMutationResult, TError = unknown, TContext = unknown>(options?: Omit<UseMutationOptions<TData, TError, {
  playlist: string;
}, TContext>, "mutationFn">) => useMutation<TData, TError, {
  playlist: string;
}, TContext>({ mutationFn: ({ playlist }) => PlaylistService.postApiPlaylistsByPlaylistStatisticsRecordPlay({ playlist }) as unknown as Promise<TData>, ...options });
/**
* Share
* @param data The data for the request.
* @param data.playlist The playlist public id
* @returns unknown
* @throws ApiError
*/
export const usePlaylistServicePostApiPlaylistsByPlaylistStatisticsRecordShare = <TData = Common.PlaylistServicePostApiPlaylistsByPlaylistStatisticsRecordShareMutationResult, TError = unknown, TContext = unknown>(options?: Omit<UseMutationOptions<TData, TError, {
  playlist: string;
}, TContext>, "mutationFn">) => useMutation<TData, TError, {
  playlist: string;
}, TContext>({ mutationFn: ({ playlist }) => PlaylistService.postApiPlaylistsByPlaylistStatisticsRecordShare({ playlist }) as unknown as Promise<TData>, ...options });
/**
* Favorite
* @param data The data for the request.
* @param data.playlist The playlist public id
* @returns unknown
* @throws ApiError
*/
export const usePlaylistServicePostApiPlaylistsByPlaylistStatisticsRecordFavorite = <TData = Common.PlaylistServicePostApiPlaylistsByPlaylistStatisticsRecordFavoriteMutationResult, TError = unknown, TContext = unknown>(options?: Omit<UseMutationOptions<TData, TError, {
  playlist: string;
}, TContext>, "mutationFn">) => useMutation<TData, TError, {
  playlist: string;
}, TContext>({ mutationFn: ({ playlist }) => PlaylistService.postApiPlaylistsByPlaylistStatisticsRecordFavorite({ playlist }) as unknown as Promise<TData>, ...options });
/**
* Smart playlist - Create
* @param data The data for the request.
* @param data.requestBody
* @returns PlaylistResource `PlaylistResource`
* @throws ApiError
*/
export const usePlaylistServicePostApiPlaylistsSmart = <TData = Common.PlaylistServicePostApiPlaylistsSmartMutationResult, TError = unknown, TContext = unknown>(options?: Omit<UseMutationOptions<TData, TError, {
  requestBody: CreateSmartPlaylistRequest;
}, TContext>, "mutationFn">) => useMutation<TData, TError, {
  requestBody: CreateSmartPlaylistRequest;
}, TContext>({ mutationFn: ({ requestBody }) => PlaylistService.postApiPlaylistsSmart({ requestBody }) as unknown as Promise<TData>, ...options });
/**
* Smart playlist - Sync
* @param data The data for the request.
* @param data.playlist The playlist public id
* @returns unknown
* @throws ApiError
*/
export const usePlaylistServicePostApiPlaylistsByPlaylistSmartSync = <TData = Common.PlaylistServicePostApiPlaylistsByPlaylistSmartSyncMutationResult, TError = unknown, TContext = unknown>(options?: Omit<UseMutationOptions<TData, TError, {
  playlist: string;
}, TContext>, "mutationFn">) => useMutation<TData, TError, {
  playlist: string;
}, TContext>({ mutationFn: ({ playlist }) => PlaylistService.postApiPlaylistsByPlaylistSmartSync({ playlist }) as unknown as Promise<TData>, ...options });
/**
* Retry a job
* @param data The data for the request.
* @param data.id
* @param data.requestBody
* @returns unknown
* @throws ApiError
*/
export const useQueueServicePostApiQueueMetricsRetryById = <TData = Common.QueueServicePostApiQueueMetricsRetryByIdMutationResult, TError = unknown, TContext = unknown>(options?: Omit<UseMutationOptions<TData, TError, {
  id: string;
  requestBody?: RetryJobRequest;
}, TContext>, "mutationFn">) => useMutation<TData, TError, {
  id: string;
  requestBody?: RetryJobRequest;
}, TContext>({ mutationFn: ({ id, requestBody }) => QueueService.postApiQueueMetricsRetryById({ id, requestBody }) as unknown as Promise<TData>, ...options });
/**
* Retry a failed job
* @param data The data for the request.
* @param data.id
* @returns unknown
* @throws ApiError
*/
export const useRetryServicePostHorizonApiJobsRetryById = <TData = Common.RetryServicePostHorizonApiJobsRetryByIdMutationResult, TError = unknown, TContext = unknown>(options?: Omit<UseMutationOptions<TData, TError, {
  id: string;
}, TContext>, "mutationFn">) => useMutation<TData, TError, {
  id: string;
}, TContext>({ mutationFn: ({ id }) => RetryService.postHorizonApiJobsRetryById({ id }) as unknown as Promise<TData>, ...options });
/**
* Create user
* This is endpoint allows administrators to create users
* @param data The data for the request.
* @param data.requestBody
* @returns UserResource `UserResource`
* @throws ApiError
*/
export const useUserServicePostApiUsers = <TData = Common.UserServicePostApiUsersMutationResult, TError = unknown, TContext = unknown>(options?: Omit<UseMutationOptions<TData, TError, {
  requestBody: CreateUserRequest;
}, TContext>, "mutationFn">) => useMutation<TData, TError, {
  requestBody: CreateUserRequest;
}, TContext>({ mutationFn: ({ requestBody }) => UserService.postApiUsers({ requestBody }) as unknown as Promise<TData>, ...options });
/**
* Update a playlist
* @param data The data for the request.
* @param data.playlist The playlist public id
* @param data.requestBody
* @returns PlaylistResource `PlaylistResource`
* @throws ApiError
*/
export const usePlaylistServicePutApiPlaylistsByPlaylist = <TData = Common.PlaylistServicePutApiPlaylistsByPlaylistMutationResult, TError = unknown, TContext = unknown>(options?: Omit<UseMutationOptions<TData, TError, {
  playlist: string;
  requestBody?: UpdatePlaylistRequest;
}, TContext>, "mutationFn">) => useMutation<TData, TError, {
  playlist: string;
  requestBody?: UpdatePlaylistRequest;
}, TContext>({ mutationFn: ({ playlist, requestBody }) => PlaylistService.putApiPlaylistsByPlaylist({ playlist, requestBody }) as unknown as Promise<TData>, ...options });
/**
* Smart playlist - Update rules
* @param data The data for the request.
* @param data.playlist The playlist public id
* @param data.requestBody
* @returns PlaylistResource `PlaylistResource`
* @throws ApiError
*/
export const usePlaylistServicePutApiPlaylistsByPlaylistSmart = <TData = Common.PlaylistServicePutApiPlaylistsByPlaylistSmartMutationResult, TError = unknown, TContext = unknown>(options?: Omit<UseMutationOptions<TData, TError, {
  playlist: string;
  requestBody: UpdateSmartPlaylistRulesRequest;
}, TContext>, "mutationFn">) => useMutation<TData, TError, {
  playlist: string;
  requestBody: UpdateSmartPlaylistRulesRequest;
}, TContext>({ mutationFn: ({ playlist, requestBody }) => PlaylistService.putApiPlaylistsByPlaylistSmart({ playlist, requestBody }) as unknown as Promise<TData>, ...options });
/**
* Update a genre
* @param data The data for the request.
* @param data.genre The genre slug
* @param data.requestBody
* @returns GenreResource `GenreResource`
* @throws ApiError
*/
export const useGenreServicePatchApiGenresByGenre = <TData = Common.GenreServicePatchApiGenresByGenreMutationResult, TError = unknown, TContext = unknown>(options?: Omit<UseMutationOptions<TData, TError, {
  genre: string;
  requestBody: UpdateGenreRequest;
}, TContext>, "mutationFn">) => useMutation<TData, TError, {
  genre: string;
  requestBody: UpdateGenreRequest;
}, TContext>({ mutationFn: ({ genre, requestBody }) => GenreService.patchApiGenresByGenre({ genre, requestBody }) as unknown as Promise<TData>, ...options });
/**
* Update a library specified by the provided slug
* @param data The data for the request.
* @param data.slug
* @param data.requestBody
* @returns LibraryResource `LibraryResource`
* @throws ApiError
*/
export const useLibraryServicePatchApiLibrariesBySlug = <TData = Common.LibraryServicePatchApiLibrariesBySlugMutationResult, TError = unknown, TContext = unknown>(options?: Omit<UseMutationOptions<TData, TError, {
  requestBody?: UpdateLibraryRequest;
  slug: string;
}, TContext>, "mutationFn">) => useMutation<TData, TError, {
  requestBody?: UpdateLibraryRequest;
  slug: string;
}, TContext>({ mutationFn: ({ requestBody, slug }) => LibraryService.patchApiLibrariesBySlug({ requestBody, slug }) as unknown as Promise<TData>, ...options });
/**
* Update a user
* @param data The data for the request.
* @param data.user The user ID
* @param data.requestBody
* @returns UserResource `UserResource`
* @throws ApiError
*/
export const useUserServicePatchApiUsersByUser = <TData = Common.UserServicePatchApiUsersByUserMutationResult, TError = unknown, TContext = unknown>(options?: Omit<UseMutationOptions<TData, TError, {
  requestBody?: UpdateUserRequest;
  user: number;
}, TContext>, "mutationFn">) => useMutation<TData, TError, {
  requestBody?: UpdateUserRequest;
  user: number;
}, TContext>({ mutationFn: ({ requestBody, user }) => UserService.patchApiUsersByUser({ requestBody, user }) as unknown as Promise<TData>, ...options });
/**
* @param data The data for the request.
* @param data.fileIdentifier
* @returns unknown
* @throws ApiError
*/
export const useFilesServiceDeleteSystemLogViewerApiFilesByFileIdentifier = <TData = Common.FilesServiceDeleteSystemLogViewerApiFilesByFileIdentifierMutationResult, TError = unknown, TContext = unknown>(options?: Omit<UseMutationOptions<TData, TError, {
  fileIdentifier: string;
}, TContext>, "mutationFn">) => useMutation<TData, TError, {
  fileIdentifier: string;
}, TContext>({ mutationFn: ({ fileIdentifier }) => FilesService.deleteSystemLogViewerApiFilesByFileIdentifier({ fileIdentifier }) as unknown as Promise<TData>, ...options });
/**
* @param data The data for the request.
* @param data.folderIdentifier
* @returns unknown
* @throws ApiError
*/
export const useFoldersServiceDeleteSystemLogViewerApiFoldersByFolderIdentifier = <TData = Common.FoldersServiceDeleteSystemLogViewerApiFoldersByFolderIdentifierMutationResult, TError = unknown, TContext = unknown>(options?: Omit<UseMutationOptions<TData, TError, {
  folderIdentifier: string;
}, TContext>, "mutationFn">) => useMutation<TData, TError, {
  folderIdentifier: string;
}, TContext>({ mutationFn: ({ folderIdentifier }) => FoldersService.deleteSystemLogViewerApiFoldersByFolderIdentifier({ folderIdentifier }) as unknown as Promise<TData>, ...options });
/**
* Delete a genre
* @param data The data for the request.
* @param data.genre The genre slug
* @returns void No content
* @throws ApiError
*/
export const useGenreServiceDeleteApiGenresByGenre = <TData = Common.GenreServiceDeleteApiGenresByGenreMutationResult, TError = unknown, TContext = unknown>(options?: Omit<UseMutationOptions<TData, TError, {
  genre: string;
}, TContext>, "mutationFn">) => useMutation<TData, TError, {
  genre: string;
}, TContext>({ mutationFn: ({ genre }) => GenreService.deleteApiGenresByGenre({ genre }) as unknown as Promise<TData>, ...options });
/**
* Delete a library
* @param data The data for the request.
* @param data.slug
* @returns void No content
* @throws ApiError
*/
export const useLibraryServiceDeleteApiLibrariesBySlug = <TData = Common.LibraryServiceDeleteApiLibrariesBySlugMutationResult, TError = unknown, TContext = unknown>(options?: Omit<UseMutationOptions<TData, TError, {
  slug: string;
}, TContext>, "mutationFn">) => useMutation<TData, TError, {
  slug: string;
}, TContext>({ mutationFn: ({ slug }) => LibraryService.deleteApiLibrariesBySlug({ slug }) as unknown as Promise<TData>, ...options });
/**
* Stop monitoring the given tag
* @param data The data for the request.
* @param data.tag
* @returns unknown
* @throws ApiError
*/
export const useMonitoringServiceDeleteHorizonApiMonitoringByTag = <TData = Common.MonitoringServiceDeleteHorizonApiMonitoringByTagMutationResult, TError = unknown, TContext = unknown>(options?: Omit<UseMutationOptions<TData, TError, {
  tag: string;
}, TContext>, "mutationFn">) => useMutation<TData, TError, {
  tag: string;
}, TContext>({ mutationFn: ({ tag }) => MonitoringService.deleteHorizonApiMonitoringByTag({ tag }) as unknown as Promise<TData>, ...options });
/**
* Delete a playlist
* @param data The data for the request.
* @param data.playlist The playlist public id
* @returns void No content
* @throws ApiError
*/
export const usePlaylistServiceDeleteApiPlaylistsByPlaylist = <TData = Common.PlaylistServiceDeleteApiPlaylistsByPlaylistMutationResult, TError = unknown, TContext = unknown>(options?: Omit<UseMutationOptions<TData, TError, {
  playlist: string;
}, TContext>, "mutationFn">) => useMutation<TData, TError, {
  playlist: string;
}, TContext>({ mutationFn: ({ playlist }) => PlaylistService.deleteApiPlaylistsByPlaylist({ playlist }) as unknown as Promise<TData>, ...options });
/**
* Remove a song
* @param data The data for the request.
* @param data.playlist The playlist public id
* @param data.song The song public id
* @returns unknown
* @throws ApiError
*/
export const usePlaylistServiceDeleteApiPlaylistsByPlaylistSongsBySong = <TData = Common.PlaylistServiceDeleteApiPlaylistsByPlaylistSongsBySongMutationResult, TError = unknown, TContext = unknown>(options?: Omit<UseMutationOptions<TData, TError, {
  playlist: string;
  song: string;
}, TContext>, "mutationFn">) => useMutation<TData, TError, {
  playlist: string;
  song: string;
}, TContext>({ mutationFn: ({ playlist, song }) => PlaylistService.deleteApiPlaylistsByPlaylistSongsBySong({ playlist, song }) as unknown as Promise<TData>, ...options });
/**
* Remove collaborator
* @param data The data for the request.
* @param data.playlist The playlist public id
* @param data.user The user ID
* @returns unknown
* @throws ApiError
*/
export const usePlaylistServiceDeleteApiPlaylistsByPlaylistCollaboratorsByUser = <TData = Common.PlaylistServiceDeleteApiPlaylistsByPlaylistCollaboratorsByUserMutationResult, TError = unknown, TContext = unknown>(options?: Omit<UseMutationOptions<TData, TError, {
  playlist: string;
  user: number;
}, TContext>, "mutationFn">) => useMutation<TData, TError, {
  playlist: string;
  user: number;
}, TContext>({ mutationFn: ({ playlist, user }) => PlaylistService.deleteApiPlaylistsByPlaylistCollaboratorsByUser({ playlist, user }) as unknown as Promise<TData>, ...options });
/**
* Delete by id
* @param data The data for the request.
* @param data.id
* @returns void No content
* @throws ApiError
*/
export const useQueueServiceDeleteApiQueueMetricsById = <TData = Common.QueueServiceDeleteApiQueueMetricsByIdMutationResult, TError = unknown, TContext = unknown>(options?: Omit<UseMutationOptions<TData, TError, {
  id: string;
}, TContext>, "mutationFn">) => useMutation<TData, TError, {
  id: string;
}, TContext>({ mutationFn: ({ id }) => QueueService.deleteApiQueueMetricsById({ id }) as unknown as Promise<TData>, ...options });
/**
* Purge all records
* @returns void No content
* @throws ApiError
*/
export const useQueueServiceDeleteApiQueueMetricsPurge = <TData = Common.QueueServiceDeleteApiQueueMetricsPurgeMutationResult, TError = unknown, TContext = unknown>(options?: Omit<UseMutationOptions<TData, TError, void, TContext>, "mutationFn">) => useMutation<TData, TError, void, TContext>({ mutationFn: () => QueueService.deleteApiQueueMetricsPurge() as unknown as Promise<TData>, ...options });
/**
* Delete a user
* @param data The data for the request.
* @param data.user
* @returns void No content
* @throws ApiError
*/
export const useUserServiceDeleteApiUsersByUser = <TData = Common.UserServiceDeleteApiUsersByUserMutationResult, TError = unknown, TContext = unknown>(options?: Omit<UseMutationOptions<TData, TError, {
  user: string;
}, TContext>, "mutationFn">) => useMutation<TData, TError, {
  user: string;
}, TContext>({ mutationFn: ({ user }) => UserService.deleteApiUsersByUser({ user }) as unknown as Promise<TData>, ...options });
/**
* Revoke a given token
* @param data The data for the request.
* @param data.token The token ID
* @returns void No content
* @throws ApiError
*/
export const useUserTokenServiceDeleteApiUsersTokensByToken = <TData = Common.UserTokenServiceDeleteApiUsersTokensByTokenMutationResult, TError = unknown, TContext = unknown>(options?: Omit<UseMutationOptions<TData, TError, {
  token: number;
}, TContext>, "mutationFn">) => useMutation<TData, TError, {
  token: number;
}, TContext>({ mutationFn: ({ token }) => UserTokenService.deleteApiUsersTokensByToken({ token }) as unknown as Promise<TData>, ...options });
