// generated with @7nohe/openapi-react-query-codegen@1.6.2 

import { type QueryClient } from "@tanstack/react-query";
import { AlbumService, ArtistService, AuthService, BatchesService, CompletedJobsService, DashboardStatsService, FailedJobsService, FilesService, FoldersService, GenreService, HostsService, ImageService, JobMetricsService, JobsService, LibraryService, LogsService, MasterSupervisorService, MonitoringService, OpCacheService, PasskeyService, PendingJobsService, PlaylistService, QueueMetricsService, QueueService, SchemaService, SilencedJobsService, SongService, StreamService, SystemInfoService, UserService, UserTokenService, WorkloadService } from "../requests/services.gen";
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
export const prefetchUseAlbumServiceGetApiLibrariesByLibraryAlbums = (queryClient: QueryClient, { fields, genres, library, limit, page, relations }: {
  fields?: string;
  genres?: string;
  library: string;
  limit?: number;
  page?: number;
  relations?: string;
}) => queryClient.prefetchQuery({ queryKey: Common.UseAlbumServiceGetApiLibrariesByLibraryAlbumsKeyFn({ fields, genres, library, limit, page, relations }), queryFn: () => AlbumService.getApiLibrariesByLibraryAlbums({ fields, genres, library, limit, page, relations }) });
/**
* Get an album
* @param data The data for the request.
* @param data.library The library slug
* @param data.album The album slug
* @returns AlbumResource `AlbumResource`
* @throws ApiError
*/
export const prefetchUseAlbumServiceGetApiLibrariesByLibraryAlbumsByAlbum = (queryClient: QueryClient, { album, library }: {
  album: string;
  library: string;
}) => queryClient.prefetchQuery({ queryKey: Common.UseAlbumServiceGetApiLibrariesByLibraryAlbumsByAlbumKeyFn({ album, library }), queryFn: () => AlbumService.getApiLibrariesByLibraryAlbumsByAlbum({ album, library }) });
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
export const prefetchUseArtistServiceGetApiLibrariesByLibraryArtists = (queryClient: QueryClient, { fields, genres, library, limit, page, relations }: {
  fields?: string;
  genres?: string;
  library: string;
  limit?: number;
  page?: number;
  relations?: string;
}) => queryClient.prefetchQuery({ queryKey: Common.UseArtistServiceGetApiLibrariesByLibraryArtistsKeyFn({ fields, genres, library, limit, page, relations }), queryFn: () => ArtistService.getApiLibrariesByLibraryArtists({ fields, genres, library, limit, page, relations }) });
/**
* Get an artist
* @param data The data for the request.
* @param data.library
* @param data.artist The artist slug
* @returns ArtistResource `ArtistResource`
* @throws ApiError
*/
export const prefetchUseArtistServiceGetApiLibrariesByLibraryArtistsByArtist = (queryClient: QueryClient, { artist, library }: {
  artist: string;
  library: string;
}) => queryClient.prefetchQuery({ queryKey: Common.UseArtistServiceGetApiLibrariesByLibraryArtistsByArtistKeyFn({ artist, library }), queryFn: () => ArtistService.getApiLibrariesByLibraryArtistsByArtist({ artist, library }) });
/**
* Get a passkey challenge
* @returns unknown
* @throws ApiError
*/
export const prefetchUseAuthServiceGetWebauthnPasskey = (queryClient: QueryClient) => queryClient.prefetchQuery({ queryKey: Common.UseAuthServiceGetWebauthnPasskeyKeyFn(), queryFn: () => AuthService.getWebauthnPasskey() });
/**
* Get passkey registration options
* @returns unknown
* @throws ApiError
*/
export const prefetchUseAuthServiceGetWebauthnPasskeyRegister = (queryClient: QueryClient) => queryClient.prefetchQuery({ queryKey: Common.UseAuthServiceGetWebauthnPasskeyRegisterKeyFn(), queryFn: () => AuthService.getWebauthnPasskeyRegister() });
/**
* Get a passkey challenge
* @returns unknown
* @throws ApiError
*/
export const prefetchUsePasskeyServiceGetWebauthnPasskey = (queryClient: QueryClient) => queryClient.prefetchQuery({ queryKey: Common.UsePasskeyServiceGetWebauthnPasskeyKeyFn(), queryFn: () => PasskeyService.getWebauthnPasskey() });
/**
* Get passkey registration options
* @returns unknown
* @throws ApiError
*/
export const prefetchUsePasskeyServiceGetWebauthnPasskeyRegister = (queryClient: QueryClient) => queryClient.prefetchQuery({ queryKey: Common.UsePasskeyServiceGetWebauthnPasskeyRegisterKeyFn(), queryFn: () => PasskeyService.getWebauthnPasskeyRegister() });
/**
* Get all of the batches
* @returns unknown
* @throws ApiError
*/
export const prefetchUseBatchesServiceGetHorizonApiBatches = (queryClient: QueryClient) => queryClient.prefetchQuery({ queryKey: Common.UseBatchesServiceGetHorizonApiBatchesKeyFn(), queryFn: () => BatchesService.getHorizonApiBatches() });
/**
* Get the details of a batch by ID
* @param data The data for the request.
* @param data.id
* @returns unknown
* @throws ApiError
*/
export const prefetchUseBatchesServiceGetHorizonApiBatchesById = (queryClient: QueryClient, { id }: {
  id: string;
}) => queryClient.prefetchQuery({ queryKey: Common.UseBatchesServiceGetHorizonApiBatchesByIdKeyFn({ id }), queryFn: () => BatchesService.getHorizonApiBatchesById({ id }) });
/**
* Get all of the completed jobs
* @param data The data for the request.
* @param data.startingAt
* @returns unknown
* @throws ApiError
*/
export const prefetchUseCompletedJobsServiceGetHorizonApiJobsCompleted = (queryClient: QueryClient, { startingAt }: {
  startingAt?: string;
} = {}) => queryClient.prefetchQuery({ queryKey: Common.UseCompletedJobsServiceGetHorizonApiJobsCompletedKeyFn({ startingAt }), queryFn: () => CompletedJobsService.getHorizonApiJobsCompleted({ startingAt }) });
/**
* Get the key performance stats for the dashboard
* @returns unknown
* @throws ApiError
*/
export const prefetchUseDashboardStatsServiceGetHorizonApiStats = (queryClient: QueryClient) => queryClient.prefetchQuery({ queryKey: Common.UseDashboardStatsServiceGetHorizonApiStatsKeyFn(), queryFn: () => DashboardStatsService.getHorizonApiStats() });
/**
* Get all of the failed jobs
* @param data The data for the request.
* @param data.tag
* @returns unknown
* @throws ApiError
*/
export const prefetchUseFailedJobsServiceGetHorizonApiJobsFailed = (queryClient: QueryClient, { tag }: {
  tag?: string;
} = {}) => queryClient.prefetchQuery({ queryKey: Common.UseFailedJobsServiceGetHorizonApiJobsFailedKeyFn({ tag }), queryFn: () => FailedJobsService.getHorizonApiJobsFailed({ tag }) });
/**
* Get a failed job instance
* @param data The data for the request.
* @param data.id
* @returns unknown
* @throws ApiError
*/
export const prefetchUseFailedJobsServiceGetHorizonApiJobsFailedById = (queryClient: QueryClient, { id }: {
  id: string;
}) => queryClient.prefetchQuery({ queryKey: Common.UseFailedJobsServiceGetHorizonApiJobsFailedByIdKeyFn({ id }), queryFn: () => FailedJobsService.getHorizonApiJobsFailedById({ id }) });
/**
* @returns LogFileResource Array of `LogFileResource`
* @throws ApiError
*/
export const prefetchUseFilesServiceGetSystemLogViewerApiFiles = (queryClient: QueryClient) => queryClient.prefetchQuery({ queryKey: Common.UseFilesServiceGetSystemLogViewerApiFilesKeyFn(), queryFn: () => FilesService.getSystemLogViewerApiFiles() });
/**
* @param data The data for the request.
* @param data.fileIdentifier
* @returns unknown
* @throws ApiError
*/
export const prefetchUseFilesServiceGetSystemLogViewerApiFilesByFileIdentifierDownloadRequest = (queryClient: QueryClient, { fileIdentifier }: {
  fileIdentifier: string;
}) => queryClient.prefetchQuery({ queryKey: Common.UseFilesServiceGetSystemLogViewerApiFilesByFileIdentifierDownloadRequestKeyFn({ fileIdentifier }), queryFn: () => FilesService.getSystemLogViewerApiFilesByFileIdentifierDownloadRequest({ fileIdentifier }) });
/**
* @param data The data for the request.
* @param data.fileIdentifier
* @returns string
* @throws ApiError
*/
export const prefetchUseFilesServiceGetSystemLogViewerApiFilesByFileIdentifierDownload = (queryClient: QueryClient, { fileIdentifier }: {
  fileIdentifier: string;
}) => queryClient.prefetchQuery({ queryKey: Common.UseFilesServiceGetSystemLogViewerApiFilesByFileIdentifierDownloadKeyFn({ fileIdentifier }), queryFn: () => FilesService.getSystemLogViewerApiFilesByFileIdentifierDownload({ fileIdentifier }) });
/**
* @returns LogFolderResource Array of `LogFolderResource`
* @throws ApiError
*/
export const prefetchUseFoldersServiceGetSystemLogViewerApiFolders = (queryClient: QueryClient) => queryClient.prefetchQuery({ queryKey: Common.UseFoldersServiceGetSystemLogViewerApiFoldersKeyFn(), queryFn: () => FoldersService.getSystemLogViewerApiFolders() });
/**
* @param data The data for the request.
* @param data.folderIdentifier
* @returns unknown
* @throws ApiError
*/
export const prefetchUseFoldersServiceGetSystemLogViewerApiFoldersByFolderIdentifierDownloadRequest = (queryClient: QueryClient, { folderIdentifier }: {
  folderIdentifier: string;
}) => queryClient.prefetchQuery({ queryKey: Common.UseFoldersServiceGetSystemLogViewerApiFoldersByFolderIdentifierDownloadRequestKeyFn({ folderIdentifier }), queryFn: () => FoldersService.getSystemLogViewerApiFoldersByFolderIdentifierDownloadRequest({ folderIdentifier }) });
/**
* @param data The data for the request.
* @param data.folderIdentifier
* @returns string
* @throws ApiError
*/
export const prefetchUseFoldersServiceGetSystemLogViewerApiFoldersByFolderIdentifierDownload = (queryClient: QueryClient, { folderIdentifier }: {
  folderIdentifier: string;
}) => queryClient.prefetchQuery({ queryKey: Common.UseFoldersServiceGetSystemLogViewerApiFoldersByFolderIdentifierDownloadKeyFn({ folderIdentifier }), queryFn: () => FoldersService.getSystemLogViewerApiFoldersByFolderIdentifierDownload({ folderIdentifier }) });
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
export const prefetchUseGenreServiceGetApiGenres = (queryClient: QueryClient, { fields, librarySlug, limit, page, relations }: {
  fields?: string;
  librarySlug?: string;
  limit?: number;
  page?: number;
  relations?: string;
} = {}) => queryClient.prefetchQuery({ queryKey: Common.UseGenreServiceGetApiGenresKeyFn({ fields, librarySlug, limit, page, relations }), queryFn: () => GenreService.getApiGenres({ fields, librarySlug, limit, page, relations }) });
/**
* Get a genre
* @param data The data for the request.
* @param data.genre The genre slug
* @returns GenreResource `GenreResource`
* @throws ApiError
*/
export const prefetchUseGenreServiceGetApiGenresByGenre = (queryClient: QueryClient, { genre }: {
  genre: string;
}) => queryClient.prefetchQuery({ queryKey: Common.UseGenreServiceGetApiGenresByGenreKeyFn({ genre }), queryFn: () => GenreService.getApiGenresByGenre({ genre }) });
/**
* @returns LogViewerHostResource Array of `LogViewerHostResource`
* @throws ApiError
*/
export const prefetchUseHostsServiceGetSystemLogViewerApiHosts = (queryClient: QueryClient) => queryClient.prefetchQuery({ queryKey: Common.UseHostsServiceGetSystemLogViewerApiHostsKeyFn(), queryFn: () => HostsService.getSystemLogViewerApiHosts() });
/**
* Get an image asset
* @param data The data for the request.
* @param data.image The image public id
* @returns string
* @throws ApiError
*/
export const prefetchUseImageServiceGetApiImagesByImage = (queryClient: QueryClient, { image }: {
  image: string;
}) => queryClient.prefetchQuery({ queryKey: Common.UseImageServiceGetApiImagesByImageKeyFn({ image }), queryFn: () => ImageService.getApiImagesByImage({ image }) });
/**
* Get all of the measured jobs
* @returns unknown
* @throws ApiError
*/
export const prefetchUseJobMetricsServiceGetHorizonApiMetricsJobs = (queryClient: QueryClient) => queryClient.prefetchQuery({ queryKey: Common.UseJobMetricsServiceGetHorizonApiMetricsJobsKeyFn(), queryFn: () => JobMetricsService.getHorizonApiMetricsJobs() });
/**
* Get metrics for a given job
* @param data The data for the request.
* @param data.id
* @returns unknown
* @throws ApiError
*/
export const prefetchUseJobMetricsServiceGetHorizonApiMetricsJobsById = (queryClient: QueryClient, { id }: {
  id: string;
}) => queryClient.prefetchQuery({ queryKey: Common.UseJobMetricsServiceGetHorizonApiMetricsJobsByIdKeyFn({ id }), queryFn: () => JobMetricsService.getHorizonApiMetricsJobsById({ id }) });
/**
* Get the details of a recent job by ID
* @param data The data for the request.
* @param data.id
* @returns unknown
* @throws ApiError
*/
export const prefetchUseJobsServiceGetHorizonApiJobsById = (queryClient: QueryClient, { id }: {
  id: string;
}) => queryClient.prefetchQuery({ queryKey: Common.UseJobsServiceGetHorizonApiJobsByIdKeyFn({ id }), queryFn: () => JobsService.getHorizonApiJobsById({ id }) });
/**
* Get a collection of media libraries
* @param data The data for the request.
* @param data.page
* @param data.limit
* @returns unknown Paginated set of `LibraryResource`
* @throws ApiError
*/
export const prefetchUseLibraryServiceGetApiLibraries = (queryClient: QueryClient, { limit, page }: {
  limit?: number;
  page?: number;
} = {}) => queryClient.prefetchQuery({ queryKey: Common.UseLibraryServiceGetApiLibrariesKeyFn({ limit, page }), queryFn: () => LibraryService.getApiLibraries({ limit, page }) });
/**
* Show library
* @param data The data for the request.
* @param data.slug
* @returns LibraryResource `LibraryResource`
* @throws ApiError
*/
export const prefetchUseLibraryServiceGetApiLibrariesBySlug = (queryClient: QueryClient, { slug }: {
  slug: string;
}) => queryClient.prefetchQuery({ queryKey: Common.UseLibraryServiceGetApiLibrariesBySlugKeyFn({ slug }), queryFn: () => LibraryService.getApiLibrariesBySlug({ slug }) });
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
export const prefetchUseLogsServiceGetSystemLogViewerApiLogs = (queryClient: QueryClient, { direction, excludeFileTypes, excludeLevels, file, log, perPage, query, shorterStackTraces }: {
  direction?: string;
  excludeFileTypes?: string;
  excludeLevels?: string;
  file?: string;
  log?: string;
  perPage?: string;
  query?: string;
  shorterStackTraces?: boolean;
} = {}) => queryClient.prefetchQuery({ queryKey: Common.UseLogsServiceGetSystemLogViewerApiLogsKeyFn({ direction, excludeFileTypes, excludeLevels, file, log, perPage, query, shorterStackTraces }), queryFn: () => LogsService.getSystemLogViewerApiLogs({ direction, excludeFileTypes, excludeLevels, file, log, perPage, query, shorterStackTraces }) });
/**
* Get all of the master supervisors and their underlying supervisors
* @returns unknown
* @throws ApiError
*/
export const prefetchUseMasterSupervisorServiceGetHorizonApiMasters = (queryClient: QueryClient) => queryClient.prefetchQuery({ queryKey: Common.UseMasterSupervisorServiceGetHorizonApiMastersKeyFn(), queryFn: () => MasterSupervisorService.getHorizonApiMasters() });
/**
* Get all of the monitored tags and their job counts
* @returns unknown
* @throws ApiError
*/
export const prefetchUseMonitoringServiceGetHorizonApiMonitoring = (queryClient: QueryClient) => queryClient.prefetchQuery({ queryKey: Common.UseMonitoringServiceGetHorizonApiMonitoringKeyFn(), queryFn: () => MonitoringService.getHorizonApiMonitoring() });
/**
* Paginate the jobs for a given tag
* @param data The data for the request.
* @param data.tag
* @param data.limit
* @returns unknown
* @throws ApiError
*/
export const prefetchUseMonitoringServiceGetHorizonApiMonitoringByTag = (queryClient: QueryClient, { limit, tag }: {
  limit?: string;
  tag: string;
}) => queryClient.prefetchQuery({ queryKey: Common.UseMonitoringServiceGetHorizonApiMonitoringByTagKeyFn({ limit, tag }), queryFn: () => MonitoringService.getHorizonApiMonitoringByTag({ limit, tag }) });
/**
* Get status
* @returns unknown
* @throws ApiError
*/
export const prefetchUseOpCacheServiceGetApiOpcacheStatus = (queryClient: QueryClient) => queryClient.prefetchQuery({ queryKey: Common.UseOpCacheServiceGetApiOpcacheStatusKeyFn(), queryFn: () => OpCacheService.getApiOpcacheStatus() });
/**
* Get config
* @returns unknown
* @throws ApiError
*/
export const prefetchUseOpCacheServiceGetApiOpcacheConfig = (queryClient: QueryClient) => queryClient.prefetchQuery({ queryKey: Common.UseOpCacheServiceGetApiOpcacheConfigKeyFn(), queryFn: () => OpCacheService.getApiOpcacheConfig() });
/**
* Get all of the pending jobs
* @param data The data for the request.
* @param data.startingAt
* @returns unknown
* @throws ApiError
*/
export const prefetchUsePendingJobsServiceGetHorizonApiJobsPending = (queryClient: QueryClient, { startingAt }: {
  startingAt?: string;
} = {}) => queryClient.prefetchQuery({ queryKey: Common.UsePendingJobsServiceGetHorizonApiJobsPendingKeyFn({ startingAt }), queryFn: () => PendingJobsService.getHorizonApiJobsPending({ startingAt }) });
/**
* Get a collection of playlists
* @returns unknown Paginated set of `PlaylistResource`
* @throws ApiError
*/
export const prefetchUsePlaylistServiceGetApiPlaylists = (queryClient: QueryClient) => queryClient.prefetchQuery({ queryKey: Common.UsePlaylistServiceGetApiPlaylistsKeyFn(), queryFn: () => PlaylistService.getApiPlaylists() });
/**
* @param data The data for the request.
* @param data.playlist The playlist public id
* @returns PlaylistResource `PlaylistResource`
* @throws ApiError
*/
export const prefetchUsePlaylistServiceGetApiPlaylistsByPlaylist = (queryClient: QueryClient, { playlist }: {
  playlist: string;
}) => queryClient.prefetchQuery({ queryKey: Common.UsePlaylistServiceGetApiPlaylistsByPlaylistKeyFn({ playlist }), queryFn: () => PlaylistService.getApiPlaylistsByPlaylist({ playlist }) });
/**
* @param data The data for the request.
* @param data.playlist The playlist public id
* @returns PlaylistStatistic `PlaylistStatistic`
* @throws ApiError
*/
export const prefetchUsePlaylistServiceGetApiPlaylistsByPlaylistStatistics = (queryClient: QueryClient, { playlist }: {
  playlist: string;
}) => queryClient.prefetchQuery({ queryKey: Common.UsePlaylistServiceGetApiPlaylistsByPlaylistStatisticsKeyFn({ playlist }), queryFn: () => PlaylistService.getApiPlaylistsByPlaylistStatistics({ playlist }) });
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
export const prefetchUseQueueServiceGetApiQueueMetrics = (queryClient: QueryClient, { limit, name, page, queue, queuedFirst, status }: {
  limit?: number;
  name?: string;
  page?: number;
  queue?: string;
  queuedFirst?: boolean;
  status?: "running" | "succeeded" | "failed" | "stale" | "queued";
} = {}) => queryClient.prefetchQuery({ queryKey: Common.UseQueueServiceGetApiQueueMetricsKeyFn({ limit, name, page, queue, queuedFirst, status }), queryFn: () => QueueService.getApiQueueMetrics({ limit, name, page, queue, queuedFirst, status }) });
/**
* Get a list of queue names
* @returns unknown
* @throws ApiError
*/
export const prefetchUseQueueServiceGetApiQueueMetricsQueues = (queryClient: QueryClient) => queryClient.prefetchQuery({ queryKey: Common.UseQueueServiceGetApiQueueMetricsQueuesKeyFn(), queryFn: () => QueueService.getApiQueueMetricsQueues() });
/**
* Get a metrics collection
* @param data The data for the request.
* @param data.aggregateDays
* @returns unknown
* @throws ApiError
*/
export const prefetchUseQueueServiceGetApiQueueMetricsMetrics = (queryClient: QueryClient, { aggregateDays }: {
  aggregateDays?: number;
} = {}) => queryClient.prefetchQuery({ queryKey: Common.UseQueueServiceGetApiQueueMetricsMetricsKeyFn({ aggregateDays }), queryFn: () => QueueService.getApiQueueMetricsMetrics({ aggregateDays }) });
/**
* Get all of the measured queues
* @returns unknown
* @throws ApiError
*/
export const prefetchUseQueueMetricsServiceGetHorizonApiMetricsQueues = (queryClient: QueryClient) => queryClient.prefetchQuery({ queryKey: Common.UseQueueMetricsServiceGetHorizonApiMetricsQueuesKeyFn(), queryFn: () => QueueMetricsService.getHorizonApiMetricsQueues() });
/**
* Get metrics for a given queue
* @param data The data for the request.
* @param data.id
* @returns unknown
* @throws ApiError
*/
export const prefetchUseQueueMetricsServiceGetHorizonApiMetricsQueuesById = (queryClient: QueryClient, { id }: {
  id: string;
}) => queryClient.prefetchQuery({ queryKey: Common.UseQueueMetricsServiceGetHorizonApiMetricsQueuesByIdKeyFn({ id }), queryFn: () => QueueMetricsService.getHorizonApiMetricsQueuesById({ id }) });
/**
* @returns unknown
* @throws ApiError
*/
export const prefetchUseSchemaServiceGetApiSchemasMusicbrainz = (queryClient: QueryClient) => queryClient.prefetchQuery({ queryKey: Common.UseSchemaServiceGetApiSchemasMusicbrainzKeyFn(), queryFn: () => SchemaService.getApiSchemasMusicbrainz() });
/**
* Get all of the silenced jobs
* @param data The data for the request.
* @param data.startingAt
* @returns unknown
* @throws ApiError
*/
export const prefetchUseSilencedJobsServiceGetHorizonApiJobsSilenced = (queryClient: QueryClient, { startingAt }: {
  startingAt?: string;
} = {}) => queryClient.prefetchQuery({ queryKey: Common.UseSilencedJobsServiceGetHorizonApiJobsSilencedKeyFn({ startingAt }), queryFn: () => SilencedJobsService.getHorizonApiJobsSilenced({ startingAt }) });
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
export const prefetchUseSongServiceGetApiLibrariesByLibrarySongs = (queryClient: QueryClient, { genreNames, genreSlugs, library, limit, page, relations }: {
  genreNames?: string;
  genreSlugs?: string;
  library: string;
  limit?: number;
  page?: number;
  relations?: string;
}) => queryClient.prefetchQuery({ queryKey: Common.UseSongServiceGetApiLibrariesByLibrarySongsKeyFn({ genreNames, genreSlugs, library, limit, page, relations }), queryFn: () => SongService.getApiLibrariesByLibrarySongs({ genreNames, genreSlugs, library, limit, page, relations }) });
/**
* Get a song by public id
* @param data The data for the request.
* @param data.library The library slug
* @param data.publicId
* @param data.relations
* @returns SongResource `SongResource`
* @throws ApiError
*/
export const prefetchUseSongServiceGetApiLibrariesByLibrarySongsByPublicId = (queryClient: QueryClient, { library, publicId, relations }: {
  library: string;
  publicId: string;
  relations?: string;
}) => queryClient.prefetchQuery({ queryKey: Common.UseSongServiceGetApiLibrariesByLibrarySongsByPublicIdKeyFn({ library, publicId, relations }), queryFn: () => SongService.getApiLibrariesByLibrarySongsByPublicId({ library, publicId, relations }) });
/**
* Direct stream the song
* Requires token with "access-stream"
* @param data The data for the request.
* @param data.library The library slug
* @param data.song The song public id
* @returns unknown
* @throws ApiError
*/
export const prefetchUseSongServiceGetApiLibrariesByLibrarySongsStreamSongBySongDirect = (queryClient: QueryClient, { library, song }: {
  library: string;
  song: string;
}) => queryClient.prefetchQuery({ queryKey: Common.UseSongServiceGetApiLibrariesByLibrarySongsStreamSongBySongDirectKeyFn({ library, song }), queryFn: () => SongService.getApiLibrariesByLibrarySongsStreamSongBySongDirect({ library, song }) });
/**
* Generate a unique session ID for streams
* @returns unknown
* @throws ApiError
*/
export const prefetchUseStreamServiceGetApiStreamSession = (queryClient: QueryClient) => queryClient.prefetchQuery({ queryKey: Common.UseStreamServiceGetApiStreamSessionKeyFn(), queryFn: () => StreamService.getApiStreamSession() });
/**
* Get php info
* @returns unknown
* @throws ApiError
*/
export const prefetchUseSystemInfoServiceGetApiSystemInfo = (queryClient: QueryClient) => queryClient.prefetchQuery({ queryKey: Common.UseSystemInfoServiceGetApiSystemInfoKeyFn(), queryFn: () => SystemInfoService.getApiSystemInfo() });
/**
* @returns unknown
* @throws ApiError
*/
export const prefetchUseSystemInfoServiceGetApiSystemInfoSys = (queryClient: QueryClient) => queryClient.prefetchQuery({ queryKey: Common.UseSystemInfoServiceGetApiSystemInfoSysKeyFn(), queryFn: () => SystemInfoService.getApiSystemInfoSys() });
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
export const prefetchUseUserServiceGetApiUsers = (queryClient: QueryClient, { filterModes, filters, globalFilter, limit, page, sorting }: {
  filterModes?: string;
  filters?: string;
  globalFilter?: string;
  limit?: number;
  page?: number;
  sorting?: string;
} = {}) => queryClient.prefetchQuery({ queryKey: Common.UseUserServiceGetApiUsersKeyFn({ filterModes, filters, globalFilter, limit, page, sorting }), queryFn: () => UserService.getApiUsers({ filterModes, filters, globalFilter, limit, page, sorting }) });
/**
* Get small user detail info
* @param data The data for the request.
* @param data.user The user ID
* @returns UserResource `UserResource`
* @throws ApiError
*/
export const prefetchUseUserServiceGetApiUsersByUser = (queryClient: QueryClient, { user }: {
  user: number;
}) => queryClient.prefetchQuery({ queryKey: Common.UseUserServiceGetApiUsersByUserKeyFn({ user }), queryFn: () => UserService.getApiUsersByUser({ user }) });
/**
* Get the authenticated user
* @returns UserResource `UserResource`
* @throws ApiError
*/
export const prefetchUseUserServiceGetApiUsersMe = (queryClient: QueryClient) => queryClient.prefetchQuery({ queryKey: Common.UseUserServiceGetApiUsersMeKeyFn(), queryFn: () => UserService.getApiUsersMe() });
/**
* Get a collection of tokens
* @param data The data for the request.
* @param data.user
* @param data.page
* @param data.perPage
* @returns unknown Paginated set of `PersonalAccessTokenViewResource`
* @throws ApiError
*/
export const prefetchUseUserTokenServiceGetApiUsersTokensByUser = (queryClient: QueryClient, { page, perPage, user }: {
  page?: number;
  perPage?: number;
  user: string;
}) => queryClient.prefetchQuery({ queryKey: Common.UseUserTokenServiceGetApiUsersTokensByUserKeyFn({ page, perPage, user }), queryFn: () => UserTokenService.getApiUsersTokensByUser({ page, perPage, user }) });
/**
* Get the current queue workload for the application
* @returns unknown
* @throws ApiError
*/
export const prefetchUseWorkloadServiceGetHorizonApiWorkload = (queryClient: QueryClient) => queryClient.prefetchQuery({ queryKey: Common.UseWorkloadServiceGetHorizonApiWorkloadKeyFn(), queryFn: () => WorkloadService.getHorizonApiWorkload() });
