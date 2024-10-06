// generated with @7nohe/openapi-react-query-codegen@1.6.1 

import { type QueryClient } from "@tanstack/react-query";
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
export const prefetchUseAlbumServiceAlbumsIndex = (queryClient: QueryClient, { fields, genres, library, limit, page, relations }: {
  fields?: string;
  genres?: string;
  library: string;
  limit?: number;
  page?: number;
  relations?: string;
}) => queryClient.prefetchQuery({ queryKey: Common.UseAlbumServiceAlbumsIndexKeyFn({ fields, genres, library, limit, page, relations }), queryFn: () => AlbumService.albumsIndex({ fields, genres, library, limit, page, relations }) });
/**
* Get an album
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
export const prefetchUseArtistServiceArtistsIndex = (queryClient: QueryClient, { fields, genres, library, limit, page, relations }: {
  fields?: string;
  genres?: string;
  library: string;
  limit?: number;
  page?: number;
  relations?: string;
}) => queryClient.prefetchQuery({ queryKey: Common.UseArtistServiceArtistsIndexKeyFn({ fields, genres, library, limit, page, relations }), queryFn: () => ArtistService.artistsIndex({ fields, genres, library, limit, page, relations }) });
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
* Get a passkey challenge
* @returns unknown
* @throws ApiError
*/
export const prefetchUseAuthServiceAuthPasskeyOptions = (queryClient: QueryClient) => queryClient.prefetchQuery({ queryKey: Common.UseAuthServiceAuthPasskeyOptionsKeyFn(), queryFn: () => AuthService.authPasskeyOptions() });
/**
* Get passkey registration options
* @returns unknown
* @throws ApiError
*/
export const prefetchUseAuthServiceAuthPasskeyRegisterOptions = (queryClient: QueryClient) => queryClient.prefetchQuery({ queryKey: Common.UseAuthServiceAuthPasskeyRegisterOptionsKeyFn(), queryFn: () => AuthService.authPasskeyRegisterOptions() });
/**
* Get a passkey challenge
* @returns unknown
* @throws ApiError
*/
export const prefetchUsePasskeyServiceAuthPasskeyOptions = (queryClient: QueryClient) => queryClient.prefetchQuery({ queryKey: Common.UsePasskeyServiceAuthPasskeyOptionsKeyFn(), queryFn: () => PasskeyService.authPasskeyOptions() });
/**
* Get passkey registration options
* @returns unknown
* @throws ApiError
*/
export const prefetchUsePasskeyServiceAuthPasskeyRegisterOptions = (queryClient: QueryClient) => queryClient.prefetchQuery({ queryKey: Common.UsePasskeyServiceAuthPasskeyRegisterOptionsKeyFn(), queryFn: () => PasskeyService.authPasskeyRegisterOptions() });
/**
* Get all of the batches
* @returns unknown
* @throws ApiError
*/
export const prefetchUseBatchesServiceHorizonJobsBatchesIndex = (queryClient: QueryClient) => queryClient.prefetchQuery({ queryKey: Common.UseBatchesServiceHorizonJobsBatchesIndexKeyFn(), queryFn: () => BatchesService.horizonJobsBatchesIndex() });
/**
* Get the details of a batch by ID
* @param data The data for the request.
* @param data.id
* @returns unknown
* @throws ApiError
*/
export const prefetchUseBatchesServiceHorizonJobsBatchesShow = (queryClient: QueryClient, { id }: {
  id: string;
}) => queryClient.prefetchQuery({ queryKey: Common.UseBatchesServiceHorizonJobsBatchesShowKeyFn({ id }), queryFn: () => BatchesService.horizonJobsBatchesShow({ id }) });
/**
* Get all of the completed jobs
* @param data The data for the request.
* @param data.startingAt
* @returns unknown
* @throws ApiError
*/
export const prefetchUseCompletedJobsServiceHorizonCompletedJobsIndex = (queryClient: QueryClient, { startingAt }: {
  startingAt?: string;
} = {}) => queryClient.prefetchQuery({ queryKey: Common.UseCompletedJobsServiceHorizonCompletedJobsIndexKeyFn({ startingAt }), queryFn: () => CompletedJobsService.horizonCompletedJobsIndex({ startingAt }) });
/**
* Get the key performance stats for the dashboard
* @returns unknown
* @throws ApiError
*/
export const prefetchUseDashboardStatsServiceHorizonStatsIndex = (queryClient: QueryClient) => queryClient.prefetchQuery({ queryKey: Common.UseDashboardStatsServiceHorizonStatsIndexKeyFn(), queryFn: () => DashboardStatsService.horizonStatsIndex() });
/**
* Get all of the failed jobs
* @param data The data for the request.
* @param data.tag
* @returns unknown
* @throws ApiError
*/
export const prefetchUseFailedJobsServiceHorizonFailedJobsIndex = (queryClient: QueryClient, { tag }: {
  tag?: string;
} = {}) => queryClient.prefetchQuery({ queryKey: Common.UseFailedJobsServiceHorizonFailedJobsIndexKeyFn({ tag }), queryFn: () => FailedJobsService.horizonFailedJobsIndex({ tag }) });
/**
* Get a failed job instance
* @param data The data for the request.
* @param data.id
* @returns string
* @throws ApiError
*/
export const prefetchUseFailedJobsServiceHorizonFailedJobsShow = (queryClient: QueryClient, { id }: {
  id: string;
}) => queryClient.prefetchQuery({ queryKey: Common.UseFailedJobsServiceHorizonFailedJobsShowKeyFn({ id }), queryFn: () => FailedJobsService.horizonFailedJobsShow({ id }) });
/**
* @returns LogFileResource Array of `LogFileResource`
* @throws ApiError
*/
export const prefetchUseFilesServiceLogViewerFiles = (queryClient: QueryClient) => queryClient.prefetchQuery({ queryKey: Common.UseFilesServiceLogViewerFilesKeyFn(), queryFn: () => FilesService.logViewerFiles() });
/**
* @param data The data for the request.
* @param data.fileIdentifier
* @returns unknown
* @throws ApiError
*/
export const prefetchUseFilesServiceLogViewerFilesRequestDownload = (queryClient: QueryClient, { fileIdentifier }: {
  fileIdentifier: string;
}) => queryClient.prefetchQuery({ queryKey: Common.UseFilesServiceLogViewerFilesRequestDownloadKeyFn({ fileIdentifier }), queryFn: () => FilesService.logViewerFilesRequestDownload({ fileIdentifier }) });
/**
* @param data The data for the request.
* @param data.fileIdentifier
* @returns string
* @throws ApiError
*/
export const prefetchUseFilesServiceLogViewerFilesDownload = (queryClient: QueryClient, { fileIdentifier }: {
  fileIdentifier: string;
}) => queryClient.prefetchQuery({ queryKey: Common.UseFilesServiceLogViewerFilesDownloadKeyFn({ fileIdentifier }), queryFn: () => FilesService.logViewerFilesDownload({ fileIdentifier }) });
/**
* @returns LogFolderResource Array of `LogFolderResource`
* @throws ApiError
*/
export const prefetchUseFoldersServiceLogViewerFolders = (queryClient: QueryClient) => queryClient.prefetchQuery({ queryKey: Common.UseFoldersServiceLogViewerFoldersKeyFn(), queryFn: () => FoldersService.logViewerFolders() });
/**
* @param data The data for the request.
* @param data.folderIdentifier
* @returns unknown
* @throws ApiError
*/
export const prefetchUseFoldersServiceLogViewerFoldersRequestDownload = (queryClient: QueryClient, { folderIdentifier }: {
  folderIdentifier: string;
}) => queryClient.prefetchQuery({ queryKey: Common.UseFoldersServiceLogViewerFoldersRequestDownloadKeyFn({ folderIdentifier }), queryFn: () => FoldersService.logViewerFoldersRequestDownload({ folderIdentifier }) });
/**
* @param data The data for the request.
* @param data.folderIdentifier
* @returns string
* @throws ApiError
*/
export const prefetchUseFoldersServiceLogViewerFoldersDownload = (queryClient: QueryClient, { folderIdentifier }: {
  folderIdentifier: string;
}) => queryClient.prefetchQuery({ queryKey: Common.UseFoldersServiceLogViewerFoldersDownloadKeyFn({ folderIdentifier }), queryFn: () => FoldersService.logViewerFoldersDownload({ folderIdentifier }) });
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
export const prefetchUseGenreServiceGenresIndex = (queryClient: QueryClient, { fields, librarySlug, limit, page, relations }: {
  fields?: string;
  librarySlug?: string;
  limit?: number;
  page?: number;
  relations?: string;
} = {}) => queryClient.prefetchQuery({ queryKey: Common.UseGenreServiceGenresIndexKeyFn({ fields, librarySlug, limit, page, relations }), queryFn: () => GenreService.genresIndex({ fields, librarySlug, limit, page, relations }) });
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
* @returns LogViewerHostResource Array of `LogViewerHostResource`
* @throws ApiError
*/
export const prefetchUseHostsServiceLogViewerHosts = (queryClient: QueryClient) => queryClient.prefetchQuery({ queryKey: Common.UseHostsServiceLogViewerHostsKeyFn(), queryFn: () => HostsService.logViewerHosts() });
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
* Get all of the measured jobs
* @returns string
* @throws ApiError
*/
export const prefetchUseJobMetricsServiceHorizonJobsMetricsIndex = (queryClient: QueryClient) => queryClient.prefetchQuery({ queryKey: Common.UseJobMetricsServiceHorizonJobsMetricsIndexKeyFn(), queryFn: () => JobMetricsService.horizonJobsMetricsIndex() });
/**
* Get metrics for a given job
* @param data The data for the request.
* @param data.id
* @returns unknown
* @throws ApiError
*/
export const prefetchUseJobMetricsServiceHorizonJobsMetricsShow = (queryClient: QueryClient, { id }: {
  id: string;
}) => queryClient.prefetchQuery({ queryKey: Common.UseJobMetricsServiceHorizonJobsMetricsShowKeyFn({ id }), queryFn: () => JobMetricsService.horizonJobsMetricsShow({ id }) });
/**
* Get the details of a recent job by ID
* @param data The data for the request.
* @param data.id
* @returns string
* @throws ApiError
*/
export const prefetchUseJobsServiceHorizonJobsShow = (queryClient: QueryClient, { id }: {
  id: string;
}) => queryClient.prefetchQuery({ queryKey: Common.UseJobsServiceHorizonJobsShowKeyFn({ id }), queryFn: () => JobsService.horizonJobsShow({ id }) });
/**
* Get a collection of media libraries
* @param data The data for the request.
* @param data.page Current page
* @param data.limit Items per page
* @returns unknown Json paginated set of `LibraryResource`
* @throws ApiError
*/
export const prefetchUseLibraryServiceLibrariesIndex = (queryClient: QueryClient, { limit, page }: {
  limit?: number;
  page?: number;
} = {}) => queryClient.prefetchQuery({ queryKey: Common.UseLibraryServiceLibrariesIndexKeyFn({ limit, page }), queryFn: () => LibraryService.librariesIndex({ limit, page }) });
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
export const prefetchUseLogsServiceLogViewerLogs = (queryClient: QueryClient, { direction, excludeFileTypes, excludeLevels, file, log, perPage, query, shorterStackTraces }: {
  direction?: string;
  excludeFileTypes?: string;
  excludeLevels?: string;
  file?: string;
  log?: string;
  perPage?: string;
  query?: string;
  shorterStackTraces?: boolean;
} = {}) => queryClient.prefetchQuery({ queryKey: Common.UseLogsServiceLogViewerLogsKeyFn({ direction, excludeFileTypes, excludeLevels, file, log, perPage, query, shorterStackTraces }), queryFn: () => LogsService.logViewerLogs({ direction, excludeFileTypes, excludeLevels, file, log, perPage, query, shorterStackTraces }) });
/**
* Get all of the master supervisors and their underlying supervisors
* @returns unknown
* @throws ApiError
*/
export const prefetchUseMasterSupervisorServiceHorizonMastersIndex = (queryClient: QueryClient) => queryClient.prefetchQuery({ queryKey: Common.UseMasterSupervisorServiceHorizonMastersIndexKeyFn(), queryFn: () => MasterSupervisorService.horizonMastersIndex() });
/**
* Get all of the monitored tags and their job counts
* @returns unknown
* @throws ApiError
*/
export const prefetchUseMonitoringServiceHorizonMonitoringIndex = (queryClient: QueryClient) => queryClient.prefetchQuery({ queryKey: Common.UseMonitoringServiceHorizonMonitoringIndexKeyFn(), queryFn: () => MonitoringService.horizonMonitoringIndex() });
/**
* Paginate the jobs for a given tag
* @param data The data for the request.
* @param data.tag
* @param data.tag
* @param data.limit
* @returns unknown
* @throws ApiError
*/
export const prefetchUseMonitoringServiceHorizonMonitoringTagPaginate = (queryClient: QueryClient, { limit, tag }: {
  limit?: string;
  tag?: string;
} = {}) => queryClient.prefetchQuery({ queryKey: Common.UseMonitoringServiceHorizonMonitoringTagPaginateKeyFn({ limit, tag }), queryFn: () => MonitoringService.horizonMonitoringTagPaginate({ limit, tag }) });
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
* Get all of the pending jobs
* @param data The data for the request.
* @param data.startingAt
* @returns unknown
* @throws ApiError
*/
export const prefetchUsePendingJobsServiceHorizonPendingJobsIndex = (queryClient: QueryClient, { startingAt }: {
  startingAt?: string;
} = {}) => queryClient.prefetchQuery({ queryKey: Common.UsePendingJobsServiceHorizonPendingJobsIndexKeyFn({ startingAt }), queryFn: () => PendingJobsService.horizonPendingJobsIndex({ startingAt }) });
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
export const prefetchUseQueueServiceQueueMetricsShow = (queryClient: QueryClient, { limit, name, page, queue, queuedFirst, status }: {
  limit?: number;
  name?: string;
  page?: number;
  queue?: string;
  queuedFirst?: boolean;
  status?: "running" | "succeeded" | "failed" | "stale" | "queued";
} = {}) => queryClient.prefetchQuery({ queryKey: Common.UseQueueServiceQueueMetricsShowKeyFn({ limit, name, page, queue, queuedFirst, status }), queryFn: () => QueueService.queueMetricsShow({ limit, name, page, queue, queuedFirst, status }) });
/**
* Get a list of queue names
* @returns unknown
* @throws ApiError
*/
export const prefetchUseQueueServiceQueueMetricsQueues = (queryClient: QueryClient) => queryClient.prefetchQuery({ queryKey: Common.UseQueueServiceQueueMetricsQueuesKeyFn(), queryFn: () => QueueService.queueMetricsQueues() });
/**
* Get a metrics collection
* @param data The data for the request.
* @param data.aggregateDays Days to aggregate
* @returns unknown
* @throws ApiError
*/
export const prefetchUseQueueServiceQueueMetricsMetrics = (queryClient: QueryClient, { aggregateDays }: {
  aggregateDays?: number;
} = {}) => queryClient.prefetchQuery({ queryKey: Common.UseQueueServiceQueueMetricsMetricsKeyFn({ aggregateDays }), queryFn: () => QueueService.queueMetricsMetrics({ aggregateDays }) });
/**
* Get all of the measured queues
* @returns string
* @throws ApiError
*/
export const prefetchUseQueueMetricsServiceHorizonQueuesMetricsIndex = (queryClient: QueryClient) => queryClient.prefetchQuery({ queryKey: Common.UseQueueMetricsServiceHorizonQueuesMetricsIndexKeyFn(), queryFn: () => QueueMetricsService.horizonQueuesMetricsIndex() });
/**
* Get metrics for a given queue
* @param data The data for the request.
* @param data.id
* @returns unknown
* @throws ApiError
*/
export const prefetchUseQueueMetricsServiceHorizonQueuesMetricsShow = (queryClient: QueryClient, { id }: {
  id: string;
}) => queryClient.prefetchQuery({ queryKey: Common.UseQueueMetricsServiceHorizonQueuesMetricsShowKeyFn({ id }), queryFn: () => QueueMetricsService.horizonQueuesMetricsShow({ id }) });
/**
* Get all of the silenced jobs
* @param data The data for the request.
* @param data.startingAt
* @returns unknown
* @throws ApiError
*/
export const prefetchUseSilencedJobsServiceHorizonSilencedJobsIndex = (queryClient: QueryClient, { startingAt }: {
  startingAt?: string;
} = {}) => queryClient.prefetchQuery({ queryKey: Common.UseSilencedJobsServiceHorizonSilencedJobsIndexKeyFn({ startingAt }), queryFn: () => SilencedJobsService.horizonSilencedJobsIndex({ startingAt }) });
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
export const prefetchUseSongServiceSongsIndex = (queryClient: QueryClient, { genreNames, genreSlugs, library, limit, page, relations }: {
  genreNames?: string;
  genreSlugs?: string;
  library: string;
  limit?: number;
  page?: number;
  relations?: string;
}) => queryClient.prefetchQuery({ queryKey: Common.UseSongServiceSongsIndexKeyFn({ genreNames, genreSlugs, library, limit, page, relations }), queryFn: () => SongService.songsIndex({ genreNames, genreSlugs, library, limit, page, relations }) });
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
export const prefetchUseSongServiceSongsShow = (queryClient: QueryClient, { library, publicId, relations }: {
  library: string;
  publicId: string;
  relations?: string;
}) => queryClient.prefetchQuery({ queryKey: Common.UseSongServiceSongsShowKeyFn({ library, publicId, relations }), queryFn: () => SongService.songsShow({ library, publicId, relations }) });
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
* Get php info
* @returns unknown
* @throws ApiError
*/
export const prefetchUseSystemInfoServiceSystemInfoPhp = (queryClient: QueryClient) => queryClient.prefetchQuery({ queryKey: Common.UseSystemInfoServiceSystemInfoPhpKeyFn(), queryFn: () => SystemInfoService.systemInfoPhp() });
/**
* @returns unknown
* @throws ApiError
*/
export const prefetchUseSystemInfoServiceSystemInfoSys = (queryClient: QueryClient) => queryClient.prefetchQuery({ queryKey: Common.UseSystemInfoServiceSystemInfoSysKeyFn(), queryFn: () => SystemInfoService.systemInfoSys() });
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
export const prefetchUseUserServiceUsersIndex = (queryClient: QueryClient, { filterModes, filters, globalFilter, limit, page, sorting }: {
  filterModes?: string;
  filters?: string;
  globalFilter?: string;
  limit?: number;
  page?: number;
  sorting?: string;
} = {}) => queryClient.prefetchQuery({ queryKey: Common.UseUserServiceUsersIndexKeyFn({ filterModes, filters, globalFilter, limit, page, sorting }), queryFn: () => UserService.usersIndex({ filterModes, filters, globalFilter, limit, page, sorting }) });
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
* Get the current queue workload for the application
* @returns string
* @throws ApiError
*/
export const prefetchUseWorkloadServiceHorizonWorkloadIndex = (queryClient: QueryClient) => queryClient.prefetchQuery({ queryKey: Common.UseWorkloadServiceHorizonWorkloadIndexKeyFn(), queryFn: () => WorkloadService.horizonWorkloadIndex() });
