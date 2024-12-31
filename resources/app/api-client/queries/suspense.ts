// generated with @7nohe/openapi-react-query-codegen@1.6.1 

import { UseQueryOptions, useSuspenseQuery } from "@tanstack/react-query";
import { AlbumService, ArtistService, AuthService, BatchesService, CompletedJobsService, DashboardStatsService, FailedJobsService, FilesService, FoldersService, GenreService, HostsService, ImageService, JobMetricsService, JobsService, LibraryService, LogsService, MasterSupervisorService, MonitoringService, OpCacheService, PasskeyService, PendingJobsService, QueueMetricsService, QueueService, SilencedJobsService, SongService, SystemInfoService, UserService, UserTokenService, WorkloadService } from "../requests/services.gen";
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
* - artists
* - cover
* - library
* - songs
* @param data.page Current page
* @param data.limit Items per page
* @param data.genres _Extension_ Comma seperated list of genres
* @returns unknown Json paginated set of `AlbumResource`
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
* @returns AlbumResource `AlbumResource`
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
* Get a passkey challenge
* @returns unknown
* @throws ApiError
*/
export const useAuthServiceAuthPasskeyOptionsSuspense = <TData = Common.AuthServiceAuthPasskeyOptionsDefaultResponse, TError = unknown, TQueryKey extends Array<unknown> = unknown[]>(queryKey?: TQueryKey, options?: Omit<UseQueryOptions<TData, TError>, "queryKey" | "queryFn">) => useSuspenseQuery<TData, TError>({ queryKey: Common.UseAuthServiceAuthPasskeyOptionsKeyFn(queryKey), queryFn: () => AuthService.authPasskeyOptions() as TData, ...options });
/**
* Get passkey registration options
* @returns unknown
* @throws ApiError
*/
export const useAuthServiceAuthPasskeyRegisterOptionsSuspense = <TData = Common.AuthServiceAuthPasskeyRegisterOptionsDefaultResponse, TError = unknown, TQueryKey extends Array<unknown> = unknown[]>(queryKey?: TQueryKey, options?: Omit<UseQueryOptions<TData, TError>, "queryKey" | "queryFn">) => useSuspenseQuery<TData, TError>({ queryKey: Common.UseAuthServiceAuthPasskeyRegisterOptionsKeyFn(queryKey), queryFn: () => AuthService.authPasskeyRegisterOptions() as TData, ...options });
/**
* Get a passkey challenge
* @returns unknown
* @throws ApiError
*/
export const usePasskeyServiceAuthPasskeyOptionsSuspense = <TData = Common.PasskeyServiceAuthPasskeyOptionsDefaultResponse, TError = unknown, TQueryKey extends Array<unknown> = unknown[]>(queryKey?: TQueryKey, options?: Omit<UseQueryOptions<TData, TError>, "queryKey" | "queryFn">) => useSuspenseQuery<TData, TError>({ queryKey: Common.UsePasskeyServiceAuthPasskeyOptionsKeyFn(queryKey), queryFn: () => PasskeyService.authPasskeyOptions() as TData, ...options });
/**
* Get passkey registration options
* @returns unknown
* @throws ApiError
*/
export const usePasskeyServiceAuthPasskeyRegisterOptionsSuspense = <TData = Common.PasskeyServiceAuthPasskeyRegisterOptionsDefaultResponse, TError = unknown, TQueryKey extends Array<unknown> = unknown[]>(queryKey?: TQueryKey, options?: Omit<UseQueryOptions<TData, TError>, "queryKey" | "queryFn">) => useSuspenseQuery<TData, TError>({ queryKey: Common.UsePasskeyServiceAuthPasskeyRegisterOptionsKeyFn(queryKey), queryFn: () => PasskeyService.authPasskeyRegisterOptions() as TData, ...options });
/**
* Get all of the batches
* @returns unknown
* @throws ApiError
*/
export const useBatchesServiceHorizonJobsBatchesIndexSuspense = <TData = Common.BatchesServiceHorizonJobsBatchesIndexDefaultResponse, TError = unknown, TQueryKey extends Array<unknown> = unknown[]>(queryKey?: TQueryKey, options?: Omit<UseQueryOptions<TData, TError>, "queryKey" | "queryFn">) => useSuspenseQuery<TData, TError>({ queryKey: Common.UseBatchesServiceHorizonJobsBatchesIndexKeyFn(queryKey), queryFn: () => BatchesService.horizonJobsBatchesIndex() as TData, ...options });
/**
* Get the details of a batch by ID
* @param data The data for the request.
* @param data.id
* @returns unknown
* @throws ApiError
*/
export const useBatchesServiceHorizonJobsBatchesShowSuspense = <TData = Common.BatchesServiceHorizonJobsBatchesShowDefaultResponse, TError = unknown, TQueryKey extends Array<unknown> = unknown[]>({ id }: {
  id: string;
}, queryKey?: TQueryKey, options?: Omit<UseQueryOptions<TData, TError>, "queryKey" | "queryFn">) => useSuspenseQuery<TData, TError>({ queryKey: Common.UseBatchesServiceHorizonJobsBatchesShowKeyFn({ id }, queryKey), queryFn: () => BatchesService.horizonJobsBatchesShow({ id }) as TData, ...options });
/**
* Get all of the completed jobs
* @param data The data for the request.
* @param data.startingAt
* @returns unknown
* @throws ApiError
*/
export const useCompletedJobsServiceHorizonCompletedJobsIndexSuspense = <TData = Common.CompletedJobsServiceHorizonCompletedJobsIndexDefaultResponse, TError = unknown, TQueryKey extends Array<unknown> = unknown[]>({ startingAt }: {
  startingAt?: string;
} = {}, queryKey?: TQueryKey, options?: Omit<UseQueryOptions<TData, TError>, "queryKey" | "queryFn">) => useSuspenseQuery<TData, TError>({ queryKey: Common.UseCompletedJobsServiceHorizonCompletedJobsIndexKeyFn({ startingAt }, queryKey), queryFn: () => CompletedJobsService.horizonCompletedJobsIndex({ startingAt }) as TData, ...options });
/**
* Get the key performance stats for the dashboard
* @returns unknown
* @throws ApiError
*/
export const useDashboardStatsServiceHorizonStatsIndexSuspense = <TData = Common.DashboardStatsServiceHorizonStatsIndexDefaultResponse, TError = unknown, TQueryKey extends Array<unknown> = unknown[]>(queryKey?: TQueryKey, options?: Omit<UseQueryOptions<TData, TError>, "queryKey" | "queryFn">) => useSuspenseQuery<TData, TError>({ queryKey: Common.UseDashboardStatsServiceHorizonStatsIndexKeyFn(queryKey), queryFn: () => DashboardStatsService.horizonStatsIndex() as TData, ...options });
/**
* Get all of the failed jobs
* @param data The data for the request.
* @param data.tag
* @returns unknown
* @throws ApiError
*/
export const useFailedJobsServiceHorizonFailedJobsIndexSuspense = <TData = Common.FailedJobsServiceHorizonFailedJobsIndexDefaultResponse, TError = unknown, TQueryKey extends Array<unknown> = unknown[]>({ tag }: {
  tag?: string;
} = {}, queryKey?: TQueryKey, options?: Omit<UseQueryOptions<TData, TError>, "queryKey" | "queryFn">) => useSuspenseQuery<TData, TError>({ queryKey: Common.UseFailedJobsServiceHorizonFailedJobsIndexKeyFn({ tag }, queryKey), queryFn: () => FailedJobsService.horizonFailedJobsIndex({ tag }) as TData, ...options });
/**
* Get a failed job instance
* @param data The data for the request.
* @param data.id
* @returns string
* @throws ApiError
*/
export const useFailedJobsServiceHorizonFailedJobsShowSuspense = <TData = Common.FailedJobsServiceHorizonFailedJobsShowDefaultResponse, TError = unknown, TQueryKey extends Array<unknown> = unknown[]>({ id }: {
  id: string;
}, queryKey?: TQueryKey, options?: Omit<UseQueryOptions<TData, TError>, "queryKey" | "queryFn">) => useSuspenseQuery<TData, TError>({ queryKey: Common.UseFailedJobsServiceHorizonFailedJobsShowKeyFn({ id }, queryKey), queryFn: () => FailedJobsService.horizonFailedJobsShow({ id }) as TData, ...options });
/**
* @returns LogFileResource Array of `LogFileResource`
* @throws ApiError
*/
export const useFilesServiceLogViewerFilesSuspense = <TData = Common.FilesServiceLogViewerFilesDefaultResponse, TError = unknown, TQueryKey extends Array<unknown> = unknown[]>(queryKey?: TQueryKey, options?: Omit<UseQueryOptions<TData, TError>, "queryKey" | "queryFn">) => useSuspenseQuery<TData, TError>({ queryKey: Common.UseFilesServiceLogViewerFilesKeyFn(queryKey), queryFn: () => FilesService.logViewerFiles() as TData, ...options });
/**
* @param data The data for the request.
* @param data.fileIdentifier
* @returns unknown
* @throws ApiError
*/
export const useFilesServiceLogViewerFilesRequestDownloadSuspense = <TData = Common.FilesServiceLogViewerFilesRequestDownloadDefaultResponse, TError = unknown, TQueryKey extends Array<unknown> = unknown[]>({ fileIdentifier }: {
  fileIdentifier: string;
}, queryKey?: TQueryKey, options?: Omit<UseQueryOptions<TData, TError>, "queryKey" | "queryFn">) => useSuspenseQuery<TData, TError>({ queryKey: Common.UseFilesServiceLogViewerFilesRequestDownloadKeyFn({ fileIdentifier }, queryKey), queryFn: () => FilesService.logViewerFilesRequestDownload({ fileIdentifier }) as TData, ...options });
/**
* @param data The data for the request.
* @param data.fileIdentifier
* @returns string
* @throws ApiError
*/
export const useFilesServiceLogViewerFilesDownloadSuspense = <TData = Common.FilesServiceLogViewerFilesDownloadDefaultResponse, TError = unknown, TQueryKey extends Array<unknown> = unknown[]>({ fileIdentifier }: {
  fileIdentifier: string;
}, queryKey?: TQueryKey, options?: Omit<UseQueryOptions<TData, TError>, "queryKey" | "queryFn">) => useSuspenseQuery<TData, TError>({ queryKey: Common.UseFilesServiceLogViewerFilesDownloadKeyFn({ fileIdentifier }, queryKey), queryFn: () => FilesService.logViewerFilesDownload({ fileIdentifier }) as TData, ...options });
/**
* @returns LogFolderResource Array of `LogFolderResource`
* @throws ApiError
*/
export const useFoldersServiceLogViewerFoldersSuspense = <TData = Common.FoldersServiceLogViewerFoldersDefaultResponse, TError = unknown, TQueryKey extends Array<unknown> = unknown[]>(queryKey?: TQueryKey, options?: Omit<UseQueryOptions<TData, TError>, "queryKey" | "queryFn">) => useSuspenseQuery<TData, TError>({ queryKey: Common.UseFoldersServiceLogViewerFoldersKeyFn(queryKey), queryFn: () => FoldersService.logViewerFolders() as TData, ...options });
/**
* @param data The data for the request.
* @param data.folderIdentifier
* @returns unknown
* @throws ApiError
*/
export const useFoldersServiceLogViewerFoldersRequestDownloadSuspense = <TData = Common.FoldersServiceLogViewerFoldersRequestDownloadDefaultResponse, TError = unknown, TQueryKey extends Array<unknown> = unknown[]>({ folderIdentifier }: {
  folderIdentifier: string;
}, queryKey?: TQueryKey, options?: Omit<UseQueryOptions<TData, TError>, "queryKey" | "queryFn">) => useSuspenseQuery<TData, TError>({ queryKey: Common.UseFoldersServiceLogViewerFoldersRequestDownloadKeyFn({ folderIdentifier }, queryKey), queryFn: () => FoldersService.logViewerFoldersRequestDownload({ folderIdentifier }) as TData, ...options });
/**
* @param data The data for the request.
* @param data.folderIdentifier
* @returns string
* @throws ApiError
*/
export const useFoldersServiceLogViewerFoldersDownloadSuspense = <TData = Common.FoldersServiceLogViewerFoldersDownloadDefaultResponse, TError = unknown, TQueryKey extends Array<unknown> = unknown[]>({ folderIdentifier }: {
  folderIdentifier: string;
}, queryKey?: TQueryKey, options?: Omit<UseQueryOptions<TData, TError>, "queryKey" | "queryFn">) => useSuspenseQuery<TData, TError>({ queryKey: Common.UseFoldersServiceLogViewerFoldersDownloadKeyFn({ folderIdentifier }, queryKey), queryFn: () => FoldersService.logViewerFoldersDownload({ folderIdentifier }) as TData, ...options });
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
* @returns LogViewerHostResource Array of `LogViewerHostResource`
* @throws ApiError
*/
export const useHostsServiceLogViewerHostsSuspense = <TData = Common.HostsServiceLogViewerHostsDefaultResponse, TError = unknown, TQueryKey extends Array<unknown> = unknown[]>(queryKey?: TQueryKey, options?: Omit<UseQueryOptions<TData, TError>, "queryKey" | "queryFn">) => useSuspenseQuery<TData, TError>({ queryKey: Common.UseHostsServiceLogViewerHostsKeyFn(queryKey), queryFn: () => HostsService.logViewerHosts() as TData, ...options });
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
* Get all of the measured jobs
* @returns string
* @throws ApiError
*/
export const useJobMetricsServiceHorizonJobsMetricsIndexSuspense = <TData = Common.JobMetricsServiceHorizonJobsMetricsIndexDefaultResponse, TError = unknown, TQueryKey extends Array<unknown> = unknown[]>(queryKey?: TQueryKey, options?: Omit<UseQueryOptions<TData, TError>, "queryKey" | "queryFn">) => useSuspenseQuery<TData, TError>({ queryKey: Common.UseJobMetricsServiceHorizonJobsMetricsIndexKeyFn(queryKey), queryFn: () => JobMetricsService.horizonJobsMetricsIndex() as TData, ...options });
/**
* Get metrics for a given job
* @param data The data for the request.
* @param data.id
* @returns unknown
* @throws ApiError
*/
export const useJobMetricsServiceHorizonJobsMetricsShowSuspense = <TData = Common.JobMetricsServiceHorizonJobsMetricsShowDefaultResponse, TError = unknown, TQueryKey extends Array<unknown> = unknown[]>({ id }: {
  id: string;
}, queryKey?: TQueryKey, options?: Omit<UseQueryOptions<TData, TError>, "queryKey" | "queryFn">) => useSuspenseQuery<TData, TError>({ queryKey: Common.UseJobMetricsServiceHorizonJobsMetricsShowKeyFn({ id }, queryKey), queryFn: () => JobMetricsService.horizonJobsMetricsShow({ id }) as TData, ...options });
/**
* Get the details of a recent job by ID
* @param data The data for the request.
* @param data.id
* @returns string
* @throws ApiError
*/
export const useJobsServiceHorizonJobsShowSuspense = <TData = Common.JobsServiceHorizonJobsShowDefaultResponse, TError = unknown, TQueryKey extends Array<unknown> = unknown[]>({ id }: {
  id: string;
}, queryKey?: TQueryKey, options?: Omit<UseQueryOptions<TData, TError>, "queryKey" | "queryFn">) => useSuspenseQuery<TData, TError>({ queryKey: Common.UseJobsServiceHorizonJobsShowKeyFn({ id }, queryKey), queryFn: () => JobsService.horizonJobsShow({ id }) as TData, ...options });
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
export const useLogsServiceLogViewerLogsSuspense = <TData = Common.LogsServiceLogViewerLogsDefaultResponse, TError = unknown, TQueryKey extends Array<unknown> = unknown[]>({ direction, excludeFileTypes, excludeLevels, file, log, perPage, query, shorterStackTraces }: {
  direction?: string;
  excludeFileTypes?: string;
  excludeLevels?: string;
  file?: string;
  log?: string;
  perPage?: string;
  query?: string;
  shorterStackTraces?: boolean;
} = {}, queryKey?: TQueryKey, options?: Omit<UseQueryOptions<TData, TError>, "queryKey" | "queryFn">) => useSuspenseQuery<TData, TError>({ queryKey: Common.UseLogsServiceLogViewerLogsKeyFn({ direction, excludeFileTypes, excludeLevels, file, log, perPage, query, shorterStackTraces }, queryKey), queryFn: () => LogsService.logViewerLogs({ direction, excludeFileTypes, excludeLevels, file, log, perPage, query, shorterStackTraces }) as TData, ...options });
/**
* Get all of the master supervisors and their underlying supervisors
* @returns unknown
* @throws ApiError
*/
export const useMasterSupervisorServiceHorizonMastersIndexSuspense = <TData = Common.MasterSupervisorServiceHorizonMastersIndexDefaultResponse, TError = unknown, TQueryKey extends Array<unknown> = unknown[]>(queryKey?: TQueryKey, options?: Omit<UseQueryOptions<TData, TError>, "queryKey" | "queryFn">) => useSuspenseQuery<TData, TError>({ queryKey: Common.UseMasterSupervisorServiceHorizonMastersIndexKeyFn(queryKey), queryFn: () => MasterSupervisorService.horizonMastersIndex() as TData, ...options });
/**
* Get all of the monitored tags and their job counts
* @returns unknown
* @throws ApiError
*/
export const useMonitoringServiceHorizonMonitoringIndexSuspense = <TData = Common.MonitoringServiceHorizonMonitoringIndexDefaultResponse, TError = unknown, TQueryKey extends Array<unknown> = unknown[]>(queryKey?: TQueryKey, options?: Omit<UseQueryOptions<TData, TError>, "queryKey" | "queryFn">) => useSuspenseQuery<TData, TError>({ queryKey: Common.UseMonitoringServiceHorizonMonitoringIndexKeyFn(queryKey), queryFn: () => MonitoringService.horizonMonitoringIndex() as TData, ...options });
/**
* Paginate the jobs for a given tag
* @param data The data for the request.
* @param data.tag
* @param data.tag
* @param data.limit
* @returns unknown
* @throws ApiError
*/
export const useMonitoringServiceHorizonMonitoringTagPaginateSuspense = <TData = Common.MonitoringServiceHorizonMonitoringTagPaginateDefaultResponse, TError = unknown, TQueryKey extends Array<unknown> = unknown[]>({ limit, tag }: {
  limit?: string;
  tag?: string;
} = {}, queryKey?: TQueryKey, options?: Omit<UseQueryOptions<TData, TError>, "queryKey" | "queryFn">) => useSuspenseQuery<TData, TError>({ queryKey: Common.UseMonitoringServiceHorizonMonitoringTagPaginateKeyFn({ limit, tag }, queryKey), queryFn: () => MonitoringService.horizonMonitoringTagPaginate({ limit, tag }) as TData, ...options });
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
* Get all of the pending jobs
* @param data The data for the request.
* @param data.startingAt
* @returns unknown
* @throws ApiError
*/
export const usePendingJobsServiceHorizonPendingJobsIndexSuspense = <TData = Common.PendingJobsServiceHorizonPendingJobsIndexDefaultResponse, TError = unknown, TQueryKey extends Array<unknown> = unknown[]>({ startingAt }: {
  startingAt?: string;
} = {}, queryKey?: TQueryKey, options?: Omit<UseQueryOptions<TData, TError>, "queryKey" | "queryFn">) => useSuspenseQuery<TData, TError>({ queryKey: Common.UsePendingJobsServiceHorizonPendingJobsIndexKeyFn({ startingAt }, queryKey), queryFn: () => PendingJobsService.horizonPendingJobsIndex({ startingAt }) as TData, ...options });
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
export const useQueueServiceQueueMetricsShowSuspense = <TData = Common.QueueServiceQueueMetricsShowDefaultResponse, TError = unknown, TQueryKey extends Array<unknown> = unknown[]>({ limit, name, page, queue, queuedFirst, status }: {
  limit?: number;
  name?: string;
  page?: number;
  queue?: string;
  queuedFirst?: boolean;
  status?: "running" | "succeeded" | "failed" | "stale" | "queued";
} = {}, queryKey?: TQueryKey, options?: Omit<UseQueryOptions<TData, TError>, "queryKey" | "queryFn">) => useSuspenseQuery<TData, TError>({ queryKey: Common.UseQueueServiceQueueMetricsShowKeyFn({ limit, name, page, queue, queuedFirst, status }, queryKey), queryFn: () => QueueService.queueMetricsShow({ limit, name, page, queue, queuedFirst, status }) as TData, ...options });
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
* Get all of the measured queues
* @returns string
* @throws ApiError
*/
export const useQueueMetricsServiceHorizonQueuesMetricsIndexSuspense = <TData = Common.QueueMetricsServiceHorizonQueuesMetricsIndexDefaultResponse, TError = unknown, TQueryKey extends Array<unknown> = unknown[]>(queryKey?: TQueryKey, options?: Omit<UseQueryOptions<TData, TError>, "queryKey" | "queryFn">) => useSuspenseQuery<TData, TError>({ queryKey: Common.UseQueueMetricsServiceHorizonQueuesMetricsIndexKeyFn(queryKey), queryFn: () => QueueMetricsService.horizonQueuesMetricsIndex() as TData, ...options });
/**
* Get metrics for a given queue
* @param data The data for the request.
* @param data.id
* @returns unknown
* @throws ApiError
*/
export const useQueueMetricsServiceHorizonQueuesMetricsShowSuspense = <TData = Common.QueueMetricsServiceHorizonQueuesMetricsShowDefaultResponse, TError = unknown, TQueryKey extends Array<unknown> = unknown[]>({ id }: {
  id: string;
}, queryKey?: TQueryKey, options?: Omit<UseQueryOptions<TData, TError>, "queryKey" | "queryFn">) => useSuspenseQuery<TData, TError>({ queryKey: Common.UseQueueMetricsServiceHorizonQueuesMetricsShowKeyFn({ id }, queryKey), queryFn: () => QueueMetricsService.horizonQueuesMetricsShow({ id }) as TData, ...options });
/**
* Get all of the silenced jobs
* @param data The data for the request.
* @param data.startingAt
* @returns unknown
* @throws ApiError
*/
export const useSilencedJobsServiceHorizonSilencedJobsIndexSuspense = <TData = Common.SilencedJobsServiceHorizonSilencedJobsIndexDefaultResponse, TError = unknown, TQueryKey extends Array<unknown> = unknown[]>({ startingAt }: {
  startingAt?: string;
} = {}, queryKey?: TQueryKey, options?: Omit<UseQueryOptions<TData, TError>, "queryKey" | "queryFn">) => useSuspenseQuery<TData, TError>({ queryKey: Common.UseSilencedJobsServiceHorizonSilencedJobsIndexKeyFn({ startingAt }, queryKey), queryFn: () => SilencedJobsService.horizonSilencedJobsIndex({ startingAt }) as TData, ...options });
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
* Get php info
* @returns unknown
* @throws ApiError
*/
export const useSystemInfoServiceSystemInfoPhpSuspense = <TData = Common.SystemInfoServiceSystemInfoPhpDefaultResponse, TError = unknown, TQueryKey extends Array<unknown> = unknown[]>(queryKey?: TQueryKey, options?: Omit<UseQueryOptions<TData, TError>, "queryKey" | "queryFn">) => useSuspenseQuery<TData, TError>({ queryKey: Common.UseSystemInfoServiceSystemInfoPhpKeyFn(queryKey), queryFn: () => SystemInfoService.systemInfoPhp() as TData, ...options });
/**
* @returns unknown
* @throws ApiError
*/
export const useSystemInfoServiceSystemInfoSysSuspense = <TData = Common.SystemInfoServiceSystemInfoSysDefaultResponse, TError = unknown, TQueryKey extends Array<unknown> = unknown[]>(queryKey?: TQueryKey, options?: Omit<UseQueryOptions<TData, TError>, "queryKey" | "queryFn">) => useSuspenseQuery<TData, TError>({ queryKey: Common.UseSystemInfoServiceSystemInfoSysKeyFn(queryKey), queryFn: () => SystemInfoService.systemInfoSys() as TData, ...options });
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
/**
* Get the current queue workload for the application
* @returns string
* @throws ApiError
*/
export const useWorkloadServiceHorizonWorkloadIndexSuspense = <TData = Common.WorkloadServiceHorizonWorkloadIndexDefaultResponse, TError = unknown, TQueryKey extends Array<unknown> = unknown[]>(queryKey?: TQueryKey, options?: Omit<UseQueryOptions<TData, TError>, "queryKey" | "queryFn">) => useSuspenseQuery<TData, TError>({ queryKey: Common.UseWorkloadServiceHorizonWorkloadIndexKeyFn(queryKey), queryFn: () => WorkloadService.horizonWorkloadIndex() as TData, ...options });
