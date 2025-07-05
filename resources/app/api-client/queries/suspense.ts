// generated with @7nohe/openapi-react-query-codegen@1.6.2 

import { UseQueryOptions, useSuspenseQuery } from "@tanstack/react-query";
import { AlbumService, ArtistService, AuthService, BatchesService, CompletedJobsService, DashboardStatsService, FailedJobsService, GenreService, ImageService, JobMetricsService, JobsService, LibraryService, LogsService, MasterSupervisorService, MonitoringService, MovieService, OpCacheService, PasskeyService, PendingJobsService, PlaylistService, QueueMetricsService, QueueService, SchemaService, SilencedJobsService, SongService, StreamService, SystemInfoService, UserService, UserTokenService, WorkloadService } from "../requests/services.gen";
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
export const useAlbumServiceGetApiLibrariesByLibraryAlbumsSuspense = <TData = Common.AlbumServiceGetApiLibrariesByLibraryAlbumsDefaultResponse, TError = unknown, TQueryKey extends Array<unknown> = unknown[]>({ fields, genres, library, limit, page, relations }: {
  fields?: string;
  genres?: string;
  library: string;
  limit?: number;
  page?: number;
  relations?: string;
}, queryKey?: TQueryKey, options?: Omit<UseQueryOptions<TData, TError>, "queryKey" | "queryFn">) => useSuspenseQuery<TData, TError>({ queryKey: Common.UseAlbumServiceGetApiLibrariesByLibraryAlbumsKeyFn({ fields, genres, library, limit, page, relations }, queryKey), queryFn: () => AlbumService.getApiLibrariesByLibraryAlbums({ fields, genres, library, limit, page, relations }) as TData, ...options });
/**
* Get an album
* @param data The data for the request.
* @param data.library The library slug
* @param data.album The album slug
* @returns AlbumResource `AlbumResource`
* @throws ApiError
*/
export const useAlbumServiceGetApiLibrariesByLibraryAlbumsByAlbumSuspense = <TData = Common.AlbumServiceGetApiLibrariesByLibraryAlbumsByAlbumDefaultResponse, TError = unknown, TQueryKey extends Array<unknown> = unknown[]>({ album, library }: {
  album: string;
  library: string;
}, queryKey?: TQueryKey, options?: Omit<UseQueryOptions<TData, TError>, "queryKey" | "queryFn">) => useSuspenseQuery<TData, TError>({ queryKey: Common.UseAlbumServiceGetApiLibrariesByLibraryAlbumsByAlbumKeyFn({ album, library }, queryKey), queryFn: () => AlbumService.getApiLibrariesByLibraryAlbumsByAlbum({ album, library }) as TData, ...options });
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
export const useArtistServiceGetApiLibrariesByLibraryArtistsSuspense = <TData = Common.ArtistServiceGetApiLibrariesByLibraryArtistsDefaultResponse, TError = unknown, TQueryKey extends Array<unknown> = unknown[]>({ fields, genres, library, limit, page, relations }: {
  fields?: string;
  genres?: string;
  library: string;
  limit?: number;
  page?: number;
  relations?: string;
}, queryKey?: TQueryKey, options?: Omit<UseQueryOptions<TData, TError>, "queryKey" | "queryFn">) => useSuspenseQuery<TData, TError>({ queryKey: Common.UseArtistServiceGetApiLibrariesByLibraryArtistsKeyFn({ fields, genres, library, limit, page, relations }, queryKey), queryFn: () => ArtistService.getApiLibrariesByLibraryArtists({ fields, genres, library, limit, page, relations }) as TData, ...options });
/**
* Get an artist
* @param data The data for the request.
* @param data.library
* @param data.artist The artist slug
* @returns ArtistResource `ArtistResource`
* @throws ApiError
*/
export const useArtistServiceGetApiLibrariesByLibraryArtistsByArtistSuspense = <TData = Common.ArtistServiceGetApiLibrariesByLibraryArtistsByArtistDefaultResponse, TError = unknown, TQueryKey extends Array<unknown> = unknown[]>({ artist, library }: {
  artist: string;
  library: string;
}, queryKey?: TQueryKey, options?: Omit<UseQueryOptions<TData, TError>, "queryKey" | "queryFn">) => useSuspenseQuery<TData, TError>({ queryKey: Common.UseArtistServiceGetApiLibrariesByLibraryArtistsByArtistKeyFn({ artist, library }, queryKey), queryFn: () => ArtistService.getApiLibrariesByLibraryArtistsByArtist({ artist, library }) as TData, ...options });
/**
* Get a passkey challenge
* @returns unknown
* @throws ApiError
*/
export const useAuthServiceGetWebauthnPasskeySuspense = <TData = Common.AuthServiceGetWebauthnPasskeyDefaultResponse, TError = unknown, TQueryKey extends Array<unknown> = unknown[]>(queryKey?: TQueryKey, options?: Omit<UseQueryOptions<TData, TError>, "queryKey" | "queryFn">) => useSuspenseQuery<TData, TError>({ queryKey: Common.UseAuthServiceGetWebauthnPasskeyKeyFn(queryKey), queryFn: () => AuthService.getWebauthnPasskey() as TData, ...options });
/**
* Get passkey registration options
* @returns unknown
* @throws ApiError
*/
export const useAuthServiceGetWebauthnPasskeyRegisterSuspense = <TData = Common.AuthServiceGetWebauthnPasskeyRegisterDefaultResponse, TError = unknown, TQueryKey extends Array<unknown> = unknown[]>(queryKey?: TQueryKey, options?: Omit<UseQueryOptions<TData, TError>, "queryKey" | "queryFn">) => useSuspenseQuery<TData, TError>({ queryKey: Common.UseAuthServiceGetWebauthnPasskeyRegisterKeyFn(queryKey), queryFn: () => AuthService.getWebauthnPasskeyRegister() as TData, ...options });
/**
* Get a passkey challenge
* @returns unknown
* @throws ApiError
*/
export const usePasskeyServiceGetWebauthnPasskeySuspense = <TData = Common.PasskeyServiceGetWebauthnPasskeyDefaultResponse, TError = unknown, TQueryKey extends Array<unknown> = unknown[]>(queryKey?: TQueryKey, options?: Omit<UseQueryOptions<TData, TError>, "queryKey" | "queryFn">) => useSuspenseQuery<TData, TError>({ queryKey: Common.UsePasskeyServiceGetWebauthnPasskeyKeyFn(queryKey), queryFn: () => PasskeyService.getWebauthnPasskey() as TData, ...options });
/**
* Get passkey registration options
* @returns unknown
* @throws ApiError
*/
export const usePasskeyServiceGetWebauthnPasskeyRegisterSuspense = <TData = Common.PasskeyServiceGetWebauthnPasskeyRegisterDefaultResponse, TError = unknown, TQueryKey extends Array<unknown> = unknown[]>(queryKey?: TQueryKey, options?: Omit<UseQueryOptions<TData, TError>, "queryKey" | "queryFn">) => useSuspenseQuery<TData, TError>({ queryKey: Common.UsePasskeyServiceGetWebauthnPasskeyRegisterKeyFn(queryKey), queryFn: () => PasskeyService.getWebauthnPasskeyRegister() as TData, ...options });
/**
* Get all of the batches
* @returns unknown
* @throws ApiError
*/
export const useBatchesServiceGetHorizonApiBatchesSuspense = <TData = Common.BatchesServiceGetHorizonApiBatchesDefaultResponse, TError = unknown, TQueryKey extends Array<unknown> = unknown[]>(queryKey?: TQueryKey, options?: Omit<UseQueryOptions<TData, TError>, "queryKey" | "queryFn">) => useSuspenseQuery<TData, TError>({ queryKey: Common.UseBatchesServiceGetHorizonApiBatchesKeyFn(queryKey), queryFn: () => BatchesService.getHorizonApiBatches() as TData, ...options });
/**
* Get the details of a batch by ID
* @param data The data for the request.
* @param data.id
* @returns unknown
* @throws ApiError
*/
export const useBatchesServiceGetHorizonApiBatchesByIdSuspense = <TData = Common.BatchesServiceGetHorizonApiBatchesByIdDefaultResponse, TError = unknown, TQueryKey extends Array<unknown> = unknown[]>({ id }: {
  id: string;
}, queryKey?: TQueryKey, options?: Omit<UseQueryOptions<TData, TError>, "queryKey" | "queryFn">) => useSuspenseQuery<TData, TError>({ queryKey: Common.UseBatchesServiceGetHorizonApiBatchesByIdKeyFn({ id }, queryKey), queryFn: () => BatchesService.getHorizonApiBatchesById({ id }) as TData, ...options });
/**
* Get all of the completed jobs
* @param data The data for the request.
* @param data.startingAt
* @returns unknown
* @throws ApiError
*/
export const useCompletedJobsServiceGetHorizonApiJobsCompletedSuspense = <TData = Common.CompletedJobsServiceGetHorizonApiJobsCompletedDefaultResponse, TError = unknown, TQueryKey extends Array<unknown> = unknown[]>({ startingAt }: {
  startingAt?: string;
} = {}, queryKey?: TQueryKey, options?: Omit<UseQueryOptions<TData, TError>, "queryKey" | "queryFn">) => useSuspenseQuery<TData, TError>({ queryKey: Common.UseCompletedJobsServiceGetHorizonApiJobsCompletedKeyFn({ startingAt }, queryKey), queryFn: () => CompletedJobsService.getHorizonApiJobsCompleted({ startingAt }) as TData, ...options });
/**
* Get the key performance stats for the dashboard
* @returns unknown
* @throws ApiError
*/
export const useDashboardStatsServiceGetHorizonApiStatsSuspense = <TData = Common.DashboardStatsServiceGetHorizonApiStatsDefaultResponse, TError = unknown, TQueryKey extends Array<unknown> = unknown[]>(queryKey?: TQueryKey, options?: Omit<UseQueryOptions<TData, TError>, "queryKey" | "queryFn">) => useSuspenseQuery<TData, TError>({ queryKey: Common.UseDashboardStatsServiceGetHorizonApiStatsKeyFn(queryKey), queryFn: () => DashboardStatsService.getHorizonApiStats() as TData, ...options });
/**
* Get all of the failed jobs
* @param data The data for the request.
* @param data.tag
* @returns unknown
* @throws ApiError
*/
export const useFailedJobsServiceGetHorizonApiJobsFailedSuspense = <TData = Common.FailedJobsServiceGetHorizonApiJobsFailedDefaultResponse, TError = unknown, TQueryKey extends Array<unknown> = unknown[]>({ tag }: {
  tag?: string;
} = {}, queryKey?: TQueryKey, options?: Omit<UseQueryOptions<TData, TError>, "queryKey" | "queryFn">) => useSuspenseQuery<TData, TError>({ queryKey: Common.UseFailedJobsServiceGetHorizonApiJobsFailedKeyFn({ tag }, queryKey), queryFn: () => FailedJobsService.getHorizonApiJobsFailed({ tag }) as TData, ...options });
/**
* Get a failed job instance
* @param data The data for the request.
* @param data.id
* @returns unknown
* @throws ApiError
*/
export const useFailedJobsServiceGetHorizonApiJobsFailedByIdSuspense = <TData = Common.FailedJobsServiceGetHorizonApiJobsFailedByIdDefaultResponse, TError = unknown, TQueryKey extends Array<unknown> = unknown[]>({ id }: {
  id: string;
}, queryKey?: TQueryKey, options?: Omit<UseQueryOptions<TData, TError>, "queryKey" | "queryFn">) => useSuspenseQuery<TData, TError>({ queryKey: Common.UseFailedJobsServiceGetHorizonApiJobsFailedByIdKeyFn({ id }, queryKey), queryFn: () => FailedJobsService.getHorizonApiJobsFailedById({ id }) as TData, ...options });
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
export const useGenreServiceGetApiGenresSuspense = <TData = Common.GenreServiceGetApiGenresDefaultResponse, TError = unknown, TQueryKey extends Array<unknown> = unknown[]>({ fields, librarySlug, limit, page, relations }: {
  fields?: string;
  librarySlug?: string;
  limit?: number;
  page?: number;
  relations?: string;
} = {}, queryKey?: TQueryKey, options?: Omit<UseQueryOptions<TData, TError>, "queryKey" | "queryFn">) => useSuspenseQuery<TData, TError>({ queryKey: Common.UseGenreServiceGetApiGenresKeyFn({ fields, librarySlug, limit, page, relations }, queryKey), queryFn: () => GenreService.getApiGenres({ fields, librarySlug, limit, page, relations }) as TData, ...options });
/**
* Get a genre
* @param data The data for the request.
* @param data.genre The genre slug
* @returns GenreResource `GenreResource`
* @throws ApiError
*/
export const useGenreServiceGetApiGenresByGenreSuspense = <TData = Common.GenreServiceGetApiGenresByGenreDefaultResponse, TError = unknown, TQueryKey extends Array<unknown> = unknown[]>({ genre }: {
  genre: string;
}, queryKey?: TQueryKey, options?: Omit<UseQueryOptions<TData, TError>, "queryKey" | "queryFn">) => useSuspenseQuery<TData, TError>({ queryKey: Common.UseGenreServiceGetApiGenresByGenreKeyFn({ genre }, queryKey), queryFn: () => GenreService.getApiGenresByGenre({ genre }) as TData, ...options });
/**
* Get an image asset
* @param data The data for the request.
* @param data.image The image public id
* @returns string
* @throws ApiError
*/
export const useImageServiceGetApiImagesByImageSuspense = <TData = Common.ImageServiceGetApiImagesByImageDefaultResponse, TError = unknown, TQueryKey extends Array<unknown> = unknown[]>({ image }: {
  image: string;
}, queryKey?: TQueryKey, options?: Omit<UseQueryOptions<TData, TError>, "queryKey" | "queryFn">) => useSuspenseQuery<TData, TError>({ queryKey: Common.UseImageServiceGetApiImagesByImageKeyFn({ image }, queryKey), queryFn: () => ImageService.getApiImagesByImage({ image }) as TData, ...options });
/**
* Get all of the measured jobs
* @returns unknown
* @throws ApiError
*/
export const useJobMetricsServiceGetHorizonApiMetricsJobsSuspense = <TData = Common.JobMetricsServiceGetHorizonApiMetricsJobsDefaultResponse, TError = unknown, TQueryKey extends Array<unknown> = unknown[]>(queryKey?: TQueryKey, options?: Omit<UseQueryOptions<TData, TError>, "queryKey" | "queryFn">) => useSuspenseQuery<TData, TError>({ queryKey: Common.UseJobMetricsServiceGetHorizonApiMetricsJobsKeyFn(queryKey), queryFn: () => JobMetricsService.getHorizonApiMetricsJobs() as TData, ...options });
/**
* Get metrics for a given job
* @param data The data for the request.
* @param data.id
* @returns unknown
* @throws ApiError
*/
export const useJobMetricsServiceGetHorizonApiMetricsJobsByIdSuspense = <TData = Common.JobMetricsServiceGetHorizonApiMetricsJobsByIdDefaultResponse, TError = unknown, TQueryKey extends Array<unknown> = unknown[]>({ id }: {
  id: string;
}, queryKey?: TQueryKey, options?: Omit<UseQueryOptions<TData, TError>, "queryKey" | "queryFn">) => useSuspenseQuery<TData, TError>({ queryKey: Common.UseJobMetricsServiceGetHorizonApiMetricsJobsByIdKeyFn({ id }, queryKey), queryFn: () => JobMetricsService.getHorizonApiMetricsJobsById({ id }) as TData, ...options });
/**
* Get the details of a recent job by ID
* @param data The data for the request.
* @param data.id
* @returns unknown
* @throws ApiError
*/
export const useJobsServiceGetHorizonApiJobsByIdSuspense = <TData = Common.JobsServiceGetHorizonApiJobsByIdDefaultResponse, TError = unknown, TQueryKey extends Array<unknown> = unknown[]>({ id }: {
  id: string;
}, queryKey?: TQueryKey, options?: Omit<UseQueryOptions<TData, TError>, "queryKey" | "queryFn">) => useSuspenseQuery<TData, TError>({ queryKey: Common.UseJobsServiceGetHorizonApiJobsByIdKeyFn({ id }, queryKey), queryFn: () => JobsService.getHorizonApiJobsById({ id }) as TData, ...options });
/**
* Get a collection of media libraries
* @param data The data for the request.
* @param data.page
* @param data.limit
* @returns unknown Paginated set of `LibraryResource`
* @throws ApiError
*/
export const useLibraryServiceGetApiLibrariesSuspense = <TData = Common.LibraryServiceGetApiLibrariesDefaultResponse, TError = unknown, TQueryKey extends Array<unknown> = unknown[]>({ limit, page }: {
  limit?: number;
  page?: number;
} = {}, queryKey?: TQueryKey, options?: Omit<UseQueryOptions<TData, TError>, "queryKey" | "queryFn">) => useSuspenseQuery<TData, TError>({ queryKey: Common.UseLibraryServiceGetApiLibrariesKeyFn({ limit, page }, queryKey), queryFn: () => LibraryService.getApiLibraries({ limit, page }) as TData, ...options });
/**
* Show library
* @param data The data for the request.
* @param data.slug
* @returns LibraryResource `LibraryResource`
* @throws ApiError
*/
export const useLibraryServiceGetApiLibrariesBySlugSuspense = <TData = Common.LibraryServiceGetApiLibrariesBySlugDefaultResponse, TError = unknown, TQueryKey extends Array<unknown> = unknown[]>({ slug }: {
  slug: string;
}, queryKey?: TQueryKey, options?: Omit<UseQueryOptions<TData, TError>, "queryKey" | "queryFn">) => useSuspenseQuery<TData, TError>({ queryKey: Common.UseLibraryServiceGetApiLibrariesBySlugKeyFn({ slug }, queryKey), queryFn: () => LibraryService.getApiLibrariesBySlug({ slug }) as TData, ...options });
/**
* Get a collection of log files
* @returns LogFile
* @throws ApiError
*/
export const useLogsServiceGetApiLogsSuspense = <TData = Common.LogsServiceGetApiLogsDefaultResponse, TError = unknown, TQueryKey extends Array<unknown> = unknown[]>(queryKey?: TQueryKey, options?: Omit<UseQueryOptions<TData, TError>, "queryKey" | "queryFn">) => useSuspenseQuery<TData, TError>({ queryKey: Common.UseLogsServiceGetApiLogsKeyFn(queryKey), queryFn: () => LogsService.getApiLogs() as TData, ...options });
/**
* Show a log file
* @param data The data for the request.
* @param data.logFile
* @returns unknown
* @throws ApiError
*/
export const useLogsServiceGetApiLogsByLogFileSuspense = <TData = Common.LogsServiceGetApiLogsByLogFileDefaultResponse, TError = unknown, TQueryKey extends Array<unknown> = unknown[]>({ logFile }: {
  logFile: string;
}, queryKey?: TQueryKey, options?: Omit<UseQueryOptions<TData, TError>, "queryKey" | "queryFn">) => useSuspenseQuery<TData, TError>({ queryKey: Common.UseLogsServiceGetApiLogsByLogFileKeyFn({ logFile }, queryKey), queryFn: () => LogsService.getApiLogsByLogFile({ logFile }) as TData, ...options });
/**
* Get log file content
* @param data The data for the request.
* @param data.logFile
* @param data.afterLine
* @param data.maxLines
* @returns unknown
* @throws ApiError
*/
export const useLogsServiceGetApiLogsByLogFileContentSuspense = <TData = Common.LogsServiceGetApiLogsByLogFileContentDefaultResponse, TError = unknown, TQueryKey extends Array<unknown> = unknown[]>({ afterLine, logFile, maxLines }: {
  afterLine?: number;
  logFile: string;
  maxLines?: number;
}, queryKey?: TQueryKey, options?: Omit<UseQueryOptions<TData, TError>, "queryKey" | "queryFn">) => useSuspenseQuery<TData, TError>({ queryKey: Common.UseLogsServiceGetApiLogsByLogFileContentKeyFn({ afterLine, logFile, maxLines }, queryKey), queryFn: () => LogsService.getApiLogsByLogFileContent({ afterLine, logFile, maxLines }) as TData, ...options });
/**
* Count log file lines
* @param data The data for the request.
* @param data.logFile
* @returns unknown
* @throws ApiError
*/
export const useLogsServiceGetApiLogsByLogFileLinesSuspense = <TData = Common.LogsServiceGetApiLogsByLogFileLinesDefaultResponse, TError = unknown, TQueryKey extends Array<unknown> = unknown[]>({ logFile }: {
  logFile: string;
}, queryKey?: TQueryKey, options?: Omit<UseQueryOptions<TData, TError>, "queryKey" | "queryFn">) => useSuspenseQuery<TData, TError>({ queryKey: Common.UseLogsServiceGetApiLogsByLogFileLinesKeyFn({ logFile }, queryKey), queryFn: () => LogsService.getApiLogsByLogFileLines({ logFile }) as TData, ...options });
/**
* Search log file content
* @param data The data for the request.
* @param data.logFile
* @param data.pattern
* @param data.caseSensitive
* @param data.maxResults
* @param data.caseSensitive
* @param data.maxResults
* @returns unknown
* @throws ApiError
*/
export const useLogsServiceGetApiLogsByLogFileSearchSuspense = <TData = Common.LogsServiceGetApiLogsByLogFileSearchDefaultResponse, TError = unknown, TQueryKey extends Array<unknown> = unknown[]>({ caseSensitive, logFile, maxResults, pattern }: {
  caseSensitive?: boolean;
  logFile: string;
  maxResults?: number;
  pattern: string;
}, queryKey?: TQueryKey, options?: Omit<UseQueryOptions<TData, TError>, "queryKey" | "queryFn">) => useSuspenseQuery<TData, TError>({ queryKey: Common.UseLogsServiceGetApiLogsByLogFileSearchKeyFn({ caseSensitive, logFile, maxResults, pattern }, queryKey), queryFn: () => LogsService.getApiLogsByLogFileSearch({ caseSensitive, logFile, maxResults, pattern }) as TData, ...options });
/**
* Get log file tail
* @param data The data for the request.
* @param data.logFile
* @param data.lines
* @returns unknown
* @throws ApiError
*/
export const useLogsServiceGetApiLogsByLogFileTailSuspense = <TData = Common.LogsServiceGetApiLogsByLogFileTailDefaultResponse, TError = unknown, TQueryKey extends Array<unknown> = unknown[]>({ lines, logFile }: {
  lines?: number;
  logFile: string;
}, queryKey?: TQueryKey, options?: Omit<UseQueryOptions<TData, TError>, "queryKey" | "queryFn">) => useSuspenseQuery<TData, TError>({ queryKey: Common.UseLogsServiceGetApiLogsByLogFileTailKeyFn({ lines, logFile }, queryKey), queryFn: () => LogsService.getApiLogsByLogFileTail({ lines, logFile }) as TData, ...options });
/**
* Get log file head
* @param data The data for the request.
* @param data.logFile
* @param data.lines
* @returns unknown
* @throws ApiError
*/
export const useLogsServiceGetApiLogsByLogFileHeadSuspense = <TData = Common.LogsServiceGetApiLogsByLogFileHeadDefaultResponse, TError = unknown, TQueryKey extends Array<unknown> = unknown[]>({ lines, logFile }: {
  lines?: number;
  logFile: string;
}, queryKey?: TQueryKey, options?: Omit<UseQueryOptions<TData, TError>, "queryKey" | "queryFn">) => useSuspenseQuery<TData, TError>({ queryKey: Common.UseLogsServiceGetApiLogsByLogFileHeadKeyFn({ lines, logFile }, queryKey), queryFn: () => LogsService.getApiLogsByLogFileHead({ lines, logFile }) as TData, ...options });
/**
* Get log file statistics
* @param data The data for the request.
* @param data.logFile
* @returns unknown
* @throws ApiError
*/
export const useLogsServiceGetApiLogsByLogFileStatsSuspense = <TData = Common.LogsServiceGetApiLogsByLogFileStatsDefaultResponse, TError = unknown, TQueryKey extends Array<unknown> = unknown[]>({ logFile }: {
  logFile: string;
}, queryKey?: TQueryKey, options?: Omit<UseQueryOptions<TData, TError>, "queryKey" | "queryFn">) => useSuspenseQuery<TData, TError>({ queryKey: Common.UseLogsServiceGetApiLogsByLogFileStatsKeyFn({ logFile }, queryKey), queryFn: () => LogsService.getApiLogsByLogFileStats({ logFile }) as TData, ...options });
/**
* Download log file
* @param data The data for the request.
* @param data.logFile
* @returns string
* @throws ApiError
*/
export const useLogsServiceGetApiLogsByLogFileDownloadSuspense = <TData = Common.LogsServiceGetApiLogsByLogFileDownloadDefaultResponse, TError = unknown, TQueryKey extends Array<unknown> = unknown[]>({ logFile }: {
  logFile: string;
}, queryKey?: TQueryKey, options?: Omit<UseQueryOptions<TData, TError>, "queryKey" | "queryFn">) => useSuspenseQuery<TData, TError>({ queryKey: Common.UseLogsServiceGetApiLogsByLogFileDownloadKeyFn({ logFile }, queryKey), queryFn: () => LogsService.getApiLogsByLogFileDownload({ logFile }) as TData, ...options });
/**
* Search across all log files
* @param data The data for the request.
* @param data.pattern
* @param data.caseSensitive
* @param data.maxResultsPerFile
* @param data.caseSensitive
* @param data.maxResultsPerFile
* @param data.files
* @returns unknown
* @throws ApiError
*/
export const useLogsServiceGetApiLogsSearchAllSuspense = <TData = Common.LogsServiceGetApiLogsSearchAllDefaultResponse, TError = unknown, TQueryKey extends Array<unknown> = unknown[]>({ caseSensitive, files, maxResultsPerFile, pattern }: {
  caseSensitive?: boolean;
  files?: string[];
  maxResultsPerFile?: number;
  pattern: string;
}, queryKey?: TQueryKey, options?: Omit<UseQueryOptions<TData, TError>, "queryKey" | "queryFn">) => useSuspenseQuery<TData, TError>({ queryKey: Common.UseLogsServiceGetApiLogsSearchAllKeyFn({ caseSensitive, files, maxResultsPerFile, pattern }, queryKey), queryFn: () => LogsService.getApiLogsSearchAll({ caseSensitive, files, maxResultsPerFile, pattern }) as TData, ...options });
/**
* Get all of the master supervisors and their underlying supervisors
* @returns unknown
* @throws ApiError
*/
export const useMasterSupervisorServiceGetHorizonApiMastersSuspense = <TData = Common.MasterSupervisorServiceGetHorizonApiMastersDefaultResponse, TError = unknown, TQueryKey extends Array<unknown> = unknown[]>(queryKey?: TQueryKey, options?: Omit<UseQueryOptions<TData, TError>, "queryKey" | "queryFn">) => useSuspenseQuery<TData, TError>({ queryKey: Common.UseMasterSupervisorServiceGetHorizonApiMastersKeyFn(queryKey), queryFn: () => MasterSupervisorService.getHorizonApiMasters() as TData, ...options });
/**
* Get all of the monitored tags and their job counts
* @returns unknown
* @throws ApiError
*/
export const useMonitoringServiceGetHorizonApiMonitoringSuspense = <TData = Common.MonitoringServiceGetHorizonApiMonitoringDefaultResponse, TError = unknown, TQueryKey extends Array<unknown> = unknown[]>(queryKey?: TQueryKey, options?: Omit<UseQueryOptions<TData, TError>, "queryKey" | "queryFn">) => useSuspenseQuery<TData, TError>({ queryKey: Common.UseMonitoringServiceGetHorizonApiMonitoringKeyFn(queryKey), queryFn: () => MonitoringService.getHorizonApiMonitoring() as TData, ...options });
/**
* Paginate the jobs for a given tag
* @param data The data for the request.
* @param data.tag
* @param data.limit
* @returns unknown
* @throws ApiError
*/
export const useMonitoringServiceGetHorizonApiMonitoringByTagSuspense = <TData = Common.MonitoringServiceGetHorizonApiMonitoringByTagDefaultResponse, TError = unknown, TQueryKey extends Array<unknown> = unknown[]>({ limit, tag }: {
  limit?: string;
  tag: string;
}, queryKey?: TQueryKey, options?: Omit<UseQueryOptions<TData, TError>, "queryKey" | "queryFn">) => useSuspenseQuery<TData, TError>({ queryKey: Common.UseMonitoringServiceGetHorizonApiMonitoringByTagKeyFn({ limit, tag }, queryKey), queryFn: () => MonitoringService.getHorizonApiMonitoringByTag({ limit, tag }) as TData, ...options });
/**
* Get a collection of movies
* @param data The data for the request.
* @param data.library The library slug
* @returns unknown Paginated set of `MovieResource`
* @throws ApiError
*/
export const useMovieServiceGetApiLibrariesByLibraryMoviesSuspense = <TData = Common.MovieServiceGetApiLibrariesByLibraryMoviesDefaultResponse, TError = unknown, TQueryKey extends Array<unknown> = unknown[]>({ library }: {
  library: string;
}, queryKey?: TQueryKey, options?: Omit<UseQueryOptions<TData, TError>, "queryKey" | "queryFn">) => useSuspenseQuery<TData, TError>({ queryKey: Common.UseMovieServiceGetApiLibrariesByLibraryMoviesKeyFn({ library }, queryKey), queryFn: () => MovieService.getApiLibrariesByLibraryMovies({ library }) as TData, ...options });
/**
* Get a movie
* @param data The data for the request.
* @param data.library The library slug
* @param data.movie The movie slug
* @returns MovieResource `MovieResource`
* @throws ApiError
*/
export const useMovieServiceGetApiLibrariesByLibraryMoviesByMovieSuspense = <TData = Common.MovieServiceGetApiLibrariesByLibraryMoviesByMovieDefaultResponse, TError = unknown, TQueryKey extends Array<unknown> = unknown[]>({ library, movie }: {
  library: string;
  movie: string;
}, queryKey?: TQueryKey, options?: Omit<UseQueryOptions<TData, TError>, "queryKey" | "queryFn">) => useSuspenseQuery<TData, TError>({ queryKey: Common.UseMovieServiceGetApiLibrariesByLibraryMoviesByMovieKeyFn({ library, movie }, queryKey), queryFn: () => MovieService.getApiLibrariesByLibraryMoviesByMovie({ library, movie }) as TData, ...options });
/**
* Get status
* @returns unknown
* @throws ApiError
*/
export const useOpCacheServiceGetApiOpcacheStatusSuspense = <TData = Common.OpCacheServiceGetApiOpcacheStatusDefaultResponse, TError = unknown, TQueryKey extends Array<unknown> = unknown[]>(queryKey?: TQueryKey, options?: Omit<UseQueryOptions<TData, TError>, "queryKey" | "queryFn">) => useSuspenseQuery<TData, TError>({ queryKey: Common.UseOpCacheServiceGetApiOpcacheStatusKeyFn(queryKey), queryFn: () => OpCacheService.getApiOpcacheStatus() as TData, ...options });
/**
* Get config
* @returns unknown
* @throws ApiError
*/
export const useOpCacheServiceGetApiOpcacheConfigSuspense = <TData = Common.OpCacheServiceGetApiOpcacheConfigDefaultResponse, TError = unknown, TQueryKey extends Array<unknown> = unknown[]>(queryKey?: TQueryKey, options?: Omit<UseQueryOptions<TData, TError>, "queryKey" | "queryFn">) => useSuspenseQuery<TData, TError>({ queryKey: Common.UseOpCacheServiceGetApiOpcacheConfigKeyFn(queryKey), queryFn: () => OpCacheService.getApiOpcacheConfig() as TData, ...options });
/**
* Get all of the pending jobs
* @param data The data for the request.
* @param data.startingAt
* @returns unknown
* @throws ApiError
*/
export const usePendingJobsServiceGetHorizonApiJobsPendingSuspense = <TData = Common.PendingJobsServiceGetHorizonApiJobsPendingDefaultResponse, TError = unknown, TQueryKey extends Array<unknown> = unknown[]>({ startingAt }: {
  startingAt?: string;
} = {}, queryKey?: TQueryKey, options?: Omit<UseQueryOptions<TData, TError>, "queryKey" | "queryFn">) => useSuspenseQuery<TData, TError>({ queryKey: Common.UsePendingJobsServiceGetHorizonApiJobsPendingKeyFn({ startingAt }, queryKey), queryFn: () => PendingJobsService.getHorizonApiJobsPending({ startingAt }) as TData, ...options });
/**
* Get a collection of playlists
* @returns unknown Paginated set of `PlaylistResource`
* @throws ApiError
*/
export const usePlaylistServiceGetApiPlaylistsSuspense = <TData = Common.PlaylistServiceGetApiPlaylistsDefaultResponse, TError = unknown, TQueryKey extends Array<unknown> = unknown[]>(queryKey?: TQueryKey, options?: Omit<UseQueryOptions<TData, TError>, "queryKey" | "queryFn">) => useSuspenseQuery<TData, TError>({ queryKey: Common.UsePlaylistServiceGetApiPlaylistsKeyFn(queryKey), queryFn: () => PlaylistService.getApiPlaylists() as TData, ...options });
/**
* Show a playlist
* @param data The data for the request.
* @param data.playlist The playlist public id
* @param data.relations
* @returns PlaylistResource `PlaylistResource`
* @throws ApiError
*/
export const usePlaylistServiceGetApiPlaylistsByPlaylistSuspense = <TData = Common.PlaylistServiceGetApiPlaylistsByPlaylistDefaultResponse, TError = unknown, TQueryKey extends Array<unknown> = unknown[]>({ playlist, relations }: {
  playlist: string;
  relations?: string;
}, queryKey?: TQueryKey, options?: Omit<UseQueryOptions<TData, TError>, "queryKey" | "queryFn">) => useSuspenseQuery<TData, TError>({ queryKey: Common.UsePlaylistServiceGetApiPlaylistsByPlaylistKeyFn({ playlist, relations }, queryKey), queryFn: () => PlaylistService.getApiPlaylistsByPlaylist({ playlist, relations }) as TData, ...options });
/**
* Get statistics
* @param data The data for the request.
* @param data.playlist The playlist public id
* @returns PlaylistStatistic `PlaylistStatistic`
* @throws ApiError
*/
export const usePlaylistServiceGetApiPlaylistsByPlaylistStatisticsSuspense = <TData = Common.PlaylistServiceGetApiPlaylistsByPlaylistStatisticsDefaultResponse, TError = unknown, TQueryKey extends Array<unknown> = unknown[]>({ playlist }: {
  playlist: string;
}, queryKey?: TQueryKey, options?: Omit<UseQueryOptions<TData, TError>, "queryKey" | "queryFn">) => useSuspenseQuery<TData, TError>({ queryKey: Common.UsePlaylistServiceGetApiPlaylistsByPlaylistStatisticsKeyFn({ playlist }, queryKey), queryFn: () => PlaylistService.getApiPlaylistsByPlaylistStatistics({ playlist }) as TData, ...options });
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
export const useQueueServiceGetApiQueueMetricsSuspense = <TData = Common.QueueServiceGetApiQueueMetricsDefaultResponse, TError = unknown, TQueryKey extends Array<unknown> = unknown[]>({ limit, name, page, queue, queuedFirst, status }: {
  limit?: number;
  name?: string;
  page?: number;
  queue?: string;
  queuedFirst?: boolean;
  status?: "running" | "succeeded" | "failed" | "stale" | "queued";
} = {}, queryKey?: TQueryKey, options?: Omit<UseQueryOptions<TData, TError>, "queryKey" | "queryFn">) => useSuspenseQuery<TData, TError>({ queryKey: Common.UseQueueServiceGetApiQueueMetricsKeyFn({ limit, name, page, queue, queuedFirst, status }, queryKey), queryFn: () => QueueService.getApiQueueMetrics({ limit, name, page, queue, queuedFirst, status }) as TData, ...options });
/**
* Get a list of queue names
* @returns unknown
* @throws ApiError
*/
export const useQueueServiceGetApiQueueMetricsQueuesSuspense = <TData = Common.QueueServiceGetApiQueueMetricsQueuesDefaultResponse, TError = unknown, TQueryKey extends Array<unknown> = unknown[]>(queryKey?: TQueryKey, options?: Omit<UseQueryOptions<TData, TError>, "queryKey" | "queryFn">) => useSuspenseQuery<TData, TError>({ queryKey: Common.UseQueueServiceGetApiQueueMetricsQueuesKeyFn(queryKey), queryFn: () => QueueService.getApiQueueMetricsQueues() as TData, ...options });
/**
* Get a metrics collection
* @param data The data for the request.
* @param data.aggregateDays
* @returns unknown
* @throws ApiError
*/
export const useQueueServiceGetApiQueueMetricsMetricsSuspense = <TData = Common.QueueServiceGetApiQueueMetricsMetricsDefaultResponse, TError = unknown, TQueryKey extends Array<unknown> = unknown[]>({ aggregateDays }: {
  aggregateDays?: number;
} = {}, queryKey?: TQueryKey, options?: Omit<UseQueryOptions<TData, TError>, "queryKey" | "queryFn">) => useSuspenseQuery<TData, TError>({ queryKey: Common.UseQueueServiceGetApiQueueMetricsMetricsKeyFn({ aggregateDays }, queryKey), queryFn: () => QueueService.getApiQueueMetricsMetrics({ aggregateDays }) as TData, ...options });
/**
* Get all of the measured queues
* @returns unknown
* @throws ApiError
*/
export const useQueueMetricsServiceGetHorizonApiMetricsQueuesSuspense = <TData = Common.QueueMetricsServiceGetHorizonApiMetricsQueuesDefaultResponse, TError = unknown, TQueryKey extends Array<unknown> = unknown[]>(queryKey?: TQueryKey, options?: Omit<UseQueryOptions<TData, TError>, "queryKey" | "queryFn">) => useSuspenseQuery<TData, TError>({ queryKey: Common.UseQueueMetricsServiceGetHorizonApiMetricsQueuesKeyFn(queryKey), queryFn: () => QueueMetricsService.getHorizonApiMetricsQueues() as TData, ...options });
/**
* Get metrics for a given queue
* @param data The data for the request.
* @param data.id
* @returns unknown
* @throws ApiError
*/
export const useQueueMetricsServiceGetHorizonApiMetricsQueuesByIdSuspense = <TData = Common.QueueMetricsServiceGetHorizonApiMetricsQueuesByIdDefaultResponse, TError = unknown, TQueryKey extends Array<unknown> = unknown[]>({ id }: {
  id: string;
}, queryKey?: TQueryKey, options?: Omit<UseQueryOptions<TData, TError>, "queryKey" | "queryFn">) => useSuspenseQuery<TData, TError>({ queryKey: Common.UseQueueMetricsServiceGetHorizonApiMetricsQueuesByIdKeyFn({ id }, queryKey), queryFn: () => QueueMetricsService.getHorizonApiMetricsQueuesById({ id }) as TData, ...options });
/**
* @returns unknown
* @throws ApiError
*/
export const useSchemaServiceGetApiSchemasMusicbrainzSuspense = <TData = Common.SchemaServiceGetApiSchemasMusicbrainzDefaultResponse, TError = unknown, TQueryKey extends Array<unknown> = unknown[]>(queryKey?: TQueryKey, options?: Omit<UseQueryOptions<TData, TError>, "queryKey" | "queryFn">) => useSuspenseQuery<TData, TError>({ queryKey: Common.UseSchemaServiceGetApiSchemasMusicbrainzKeyFn(queryKey), queryFn: () => SchemaService.getApiSchemasMusicbrainz() as TData, ...options });
/**
* Get all of the silenced jobs
* @param data The data for the request.
* @param data.startingAt
* @returns unknown
* @throws ApiError
*/
export const useSilencedJobsServiceGetHorizonApiJobsSilencedSuspense = <TData = Common.SilencedJobsServiceGetHorizonApiJobsSilencedDefaultResponse, TError = unknown, TQueryKey extends Array<unknown> = unknown[]>({ startingAt }: {
  startingAt?: string;
} = {}, queryKey?: TQueryKey, options?: Omit<UseQueryOptions<TData, TError>, "queryKey" | "queryFn">) => useSuspenseQuery<TData, TError>({ queryKey: Common.UseSilencedJobsServiceGetHorizonApiJobsSilencedKeyFn({ startingAt }, queryKey), queryFn: () => SilencedJobsService.getHorizonApiJobsSilenced({ startingAt }) as TData, ...options });
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
export const useSongServiceGetApiLibrariesByLibrarySongsSuspense = <TData = Common.SongServiceGetApiLibrariesByLibrarySongsDefaultResponse, TError = unknown, TQueryKey extends Array<unknown> = unknown[]>({ genreNames, genreSlugs, library, limit, page, relations }: {
  genreNames?: string;
  genreSlugs?: string;
  library: string;
  limit?: number;
  page?: number;
  relations?: string;
}, queryKey?: TQueryKey, options?: Omit<UseQueryOptions<TData, TError>, "queryKey" | "queryFn">) => useSuspenseQuery<TData, TError>({ queryKey: Common.UseSongServiceGetApiLibrariesByLibrarySongsKeyFn({ genreNames, genreSlugs, library, limit, page, relations }, queryKey), queryFn: () => SongService.getApiLibrariesByLibrarySongs({ genreNames, genreSlugs, library, limit, page, relations }) as TData, ...options });
/**
* Get a song by public id
* @param data The data for the request.
* @param data.library The library slug
* @param data.publicId
* @param data.relations
* @returns SongResource `SongResource`
* @throws ApiError
*/
export const useSongServiceGetApiLibrariesByLibrarySongsByPublicIdSuspense = <TData = Common.SongServiceGetApiLibrariesByLibrarySongsByPublicIdDefaultResponse, TError = unknown, TQueryKey extends Array<unknown> = unknown[]>({ library, publicId, relations }: {
  library: string;
  publicId: string;
  relations?: string;
}, queryKey?: TQueryKey, options?: Omit<UseQueryOptions<TData, TError>, "queryKey" | "queryFn">) => useSuspenseQuery<TData, TError>({ queryKey: Common.UseSongServiceGetApiLibrariesByLibrarySongsByPublicIdKeyFn({ library, publicId, relations }, queryKey), queryFn: () => SongService.getApiLibrariesByLibrarySongsByPublicId({ library, publicId, relations }) as TData, ...options });
/**
* Direct stream the song.
* Requires token with "access-stream"
* @param data The data for the request.
* @param data.song The song public id
* @returns unknown
* @throws ApiError
*/
export const useStreamServiceGetApiStreamSongBySongDirectSuspense = <TData = Common.StreamServiceGetApiStreamSongBySongDirectDefaultResponse, TError = unknown, TQueryKey extends Array<unknown> = unknown[]>({ song }: {
  song: string;
}, queryKey?: TQueryKey, options?: Omit<UseQueryOptions<TData, TError>, "queryKey" | "queryFn">) => useSuspenseQuery<TData, TError>({ queryKey: Common.UseStreamServiceGetApiStreamSongBySongDirectKeyFn({ song }, queryKey), queryFn: () => StreamService.getApiStreamSongBySongDirect({ song }) as TData, ...options });
/**
* Get php info
* @returns unknown
* @throws ApiError
*/
export const useSystemInfoServiceGetApiSystemInfoSuspense = <TData = Common.SystemInfoServiceGetApiSystemInfoDefaultResponse, TError = unknown, TQueryKey extends Array<unknown> = unknown[]>(queryKey?: TQueryKey, options?: Omit<UseQueryOptions<TData, TError>, "queryKey" | "queryFn">) => useSuspenseQuery<TData, TError>({ queryKey: Common.UseSystemInfoServiceGetApiSystemInfoKeyFn(queryKey), queryFn: () => SystemInfoService.getApiSystemInfo() as TData, ...options });
/**
* @returns unknown
* @throws ApiError
*/
export const useSystemInfoServiceGetApiSystemInfoSysSuspense = <TData = Common.SystemInfoServiceGetApiSystemInfoSysDefaultResponse, TError = unknown, TQueryKey extends Array<unknown> = unknown[]>(queryKey?: TQueryKey, options?: Omit<UseQueryOptions<TData, TError>, "queryKey" | "queryFn">) => useSuspenseQuery<TData, TError>({ queryKey: Common.UseSystemInfoServiceGetApiSystemInfoSysKeyFn(queryKey), queryFn: () => SystemInfoService.getApiSystemInfoSys() as TData, ...options });
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
export const useUserServiceGetApiUsersSuspense = <TData = Common.UserServiceGetApiUsersDefaultResponse, TError = unknown, TQueryKey extends Array<unknown> = unknown[]>({ filterModes, filters, globalFilter, limit, page, sorting }: {
  filterModes?: string;
  filters?: string;
  globalFilter?: string;
  limit?: number;
  page?: number;
  sorting?: string;
} = {}, queryKey?: TQueryKey, options?: Omit<UseQueryOptions<TData, TError>, "queryKey" | "queryFn">) => useSuspenseQuery<TData, TError>({ queryKey: Common.UseUserServiceGetApiUsersKeyFn({ filterModes, filters, globalFilter, limit, page, sorting }, queryKey), queryFn: () => UserService.getApiUsers({ filterModes, filters, globalFilter, limit, page, sorting }) as TData, ...options });
/**
* Get small user detail info
* @param data The data for the request.
* @param data.user The user ID
* @returns UserResource `UserResource`
* @throws ApiError
*/
export const useUserServiceGetApiUsersByUserSuspense = <TData = Common.UserServiceGetApiUsersByUserDefaultResponse, TError = unknown, TQueryKey extends Array<unknown> = unknown[]>({ user }: {
  user: number;
}, queryKey?: TQueryKey, options?: Omit<UseQueryOptions<TData, TError>, "queryKey" | "queryFn">) => useSuspenseQuery<TData, TError>({ queryKey: Common.UseUserServiceGetApiUsersByUserKeyFn({ user }, queryKey), queryFn: () => UserService.getApiUsersByUser({ user }) as TData, ...options });
/**
* Get the authenticated user
* @returns UserResource `UserResource`
* @throws ApiError
*/
export const useUserServiceGetApiUsersMeSuspense = <TData = Common.UserServiceGetApiUsersMeDefaultResponse, TError = unknown, TQueryKey extends Array<unknown> = unknown[]>(queryKey?: TQueryKey, options?: Omit<UseQueryOptions<TData, TError>, "queryKey" | "queryFn">) => useSuspenseQuery<TData, TError>({ queryKey: Common.UseUserServiceGetApiUsersMeKeyFn(queryKey), queryFn: () => UserService.getApiUsersMe() as TData, ...options });
/**
* Get a collection of tokens
* @param data The data for the request.
* @param data.user
* @param data.page
* @param data.perPage
* @returns unknown Paginated set of `PersonalAccessTokenViewResource`
* @throws ApiError
*/
export const useUserTokenServiceGetApiUsersTokensByUserSuspense = <TData = Common.UserTokenServiceGetApiUsersTokensByUserDefaultResponse, TError = unknown, TQueryKey extends Array<unknown> = unknown[]>({ page, perPage, user }: {
  page?: number;
  perPage?: number;
  user: string;
}, queryKey?: TQueryKey, options?: Omit<UseQueryOptions<TData, TError>, "queryKey" | "queryFn">) => useSuspenseQuery<TData, TError>({ queryKey: Common.UseUserTokenServiceGetApiUsersTokensByUserKeyFn({ page, perPage, user }, queryKey), queryFn: () => UserTokenService.getApiUsersTokensByUser({ page, perPage, user }) as TData, ...options });
/**
* Get the current queue workload for the application
* @returns unknown
* @throws ApiError
*/
export const useWorkloadServiceGetHorizonApiWorkloadSuspense = <TData = Common.WorkloadServiceGetHorizonApiWorkloadDefaultResponse, TError = unknown, TQueryKey extends Array<unknown> = unknown[]>(queryKey?: TQueryKey, options?: Omit<UseQueryOptions<TData, TError>, "queryKey" | "queryFn">) => useSuspenseQuery<TData, TError>({ queryKey: Common.UseWorkloadServiceGetHorizonApiWorkloadKeyFn(queryKey), queryFn: () => WorkloadService.getHorizonApiWorkload() as TData, ...options });
