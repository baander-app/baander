// generated with @7nohe/openapi-react-query-codegen@1.6.2 

import { type QueryClient } from "@tanstack/react-query";
import { AlbumService, ArtistService, AuthService, BatchesService, CompletedJobsService, DashboardStatsService, FailedJobsService, GenreService, ImageService, JobMetricsService, JobsService, LastFmService, LibraryService, LogsService, MasterSupervisorService, MonitoringService, MovieService, OpCacheService, PasskeyService, PendingJobsService, PlaylistService, QueueMetricsService, QueueService, SchemaService, SilencedJobsService, SongService, SpotifyService, StreamService, SystemInfoService, UserService, UserTokenService, WorkloadService } from "../requests/services.gen";
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
export const ensureUseAlbumServiceGetApiLibrariesByLibraryAlbumsData = (queryClient: QueryClient, { fields, genres, library, limit, page, relations }: {
  fields?: string;
  genres?: string;
  library: string;
  limit?: number;
  page?: number;
  relations?: string;
}) => queryClient.ensureQueryData({ queryKey: Common.UseAlbumServiceGetApiLibrariesByLibraryAlbumsKeyFn({ fields, genres, library, limit, page, relations }), queryFn: () => AlbumService.getApiLibrariesByLibraryAlbums({ fields, genres, library, limit, page, relations }) });
/**
* Get an album
* @param data The data for the request.
* @param data.library The library slug
* @param data.album The album slug
* @returns AlbumResource `AlbumResource`
* @throws ApiError
*/
export const ensureUseAlbumServiceGetApiLibrariesByLibraryAlbumsByAlbumData = (queryClient: QueryClient, { album, library }: {
  album: string;
  library: string;
}) => queryClient.ensureQueryData({ queryKey: Common.UseAlbumServiceGetApiLibrariesByLibraryAlbumsByAlbumKeyFn({ album, library }), queryFn: () => AlbumService.getApiLibrariesByLibraryAlbumsByAlbum({ album, library }) });
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
export const ensureUseArtistServiceGetApiLibrariesByLibraryArtistsData = (queryClient: QueryClient, { fields, genres, library, limit, page, relations }: {
  fields?: string;
  genres?: string;
  library: string;
  limit?: number;
  page?: number;
  relations?: string;
}) => queryClient.ensureQueryData({ queryKey: Common.UseArtistServiceGetApiLibrariesByLibraryArtistsKeyFn({ fields, genres, library, limit, page, relations }), queryFn: () => ArtistService.getApiLibrariesByLibraryArtists({ fields, genres, library, limit, page, relations }) });
/**
* Get an artist
* @param data The data for the request.
* @param data.library
* @param data.artist The artist slug
* @returns ArtistResource `ArtistResource`
* @throws ApiError
*/
export const ensureUseArtistServiceGetApiLibrariesByLibraryArtistsByArtistData = (queryClient: QueryClient, { artist, library }: {
  artist: string;
  library: string;
}) => queryClient.ensureQueryData({ queryKey: Common.UseArtistServiceGetApiLibrariesByLibraryArtistsByArtistKeyFn({ artist, library }), queryFn: () => ArtistService.getApiLibrariesByLibraryArtistsByArtist({ artist, library }) });
/**
* Get a passkey challenge
* @returns unknown
* @throws ApiError
*/
export const ensureUseAuthServiceGetWebauthnPasskeyData = (queryClient: QueryClient) => queryClient.ensureQueryData({ queryKey: Common.UseAuthServiceGetWebauthnPasskeyKeyFn(), queryFn: () => AuthService.getWebauthnPasskey() });
/**
* Get passkey registration options
* @returns unknown
* @throws ApiError
*/
export const ensureUseAuthServiceGetWebauthnPasskeyRegisterData = (queryClient: QueryClient) => queryClient.ensureQueryData({ queryKey: Common.UseAuthServiceGetWebauthnPasskeyRegisterKeyFn(), queryFn: () => AuthService.getWebauthnPasskeyRegister() });
/**
* Get a passkey challenge
* @returns unknown
* @throws ApiError
*/
export const ensureUsePasskeyServiceGetWebauthnPasskeyData = (queryClient: QueryClient) => queryClient.ensureQueryData({ queryKey: Common.UsePasskeyServiceGetWebauthnPasskeyKeyFn(), queryFn: () => PasskeyService.getWebauthnPasskey() });
/**
* Get passkey registration options
* @returns unknown
* @throws ApiError
*/
export const ensureUsePasskeyServiceGetWebauthnPasskeyRegisterData = (queryClient: QueryClient) => queryClient.ensureQueryData({ queryKey: Common.UsePasskeyServiceGetWebauthnPasskeyRegisterKeyFn(), queryFn: () => PasskeyService.getWebauthnPasskeyRegister() });
/**
* Get all of the batches
* @returns unknown
* @throws ApiError
*/
export const ensureUseBatchesServiceGetHorizonApiBatchesData = (queryClient: QueryClient) => queryClient.ensureQueryData({ queryKey: Common.UseBatchesServiceGetHorizonApiBatchesKeyFn(), queryFn: () => BatchesService.getHorizonApiBatches() });
/**
* Get the details of a batch by ID
* @param data The data for the request.
* @param data.id
* @returns unknown
* @throws ApiError
*/
export const ensureUseBatchesServiceGetHorizonApiBatchesByIdData = (queryClient: QueryClient, { id }: {
  id: string;
}) => queryClient.ensureQueryData({ queryKey: Common.UseBatchesServiceGetHorizonApiBatchesByIdKeyFn({ id }), queryFn: () => BatchesService.getHorizonApiBatchesById({ id }) });
/**
* Get all of the completed jobs
* @param data The data for the request.
* @param data.startingAt
* @returns unknown
* @throws ApiError
*/
export const ensureUseCompletedJobsServiceGetHorizonApiJobsCompletedData = (queryClient: QueryClient, { startingAt }: {
  startingAt?: string;
} = {}) => queryClient.ensureQueryData({ queryKey: Common.UseCompletedJobsServiceGetHorizonApiJobsCompletedKeyFn({ startingAt }), queryFn: () => CompletedJobsService.getHorizonApiJobsCompleted({ startingAt }) });
/**
* Get the key performance stats for the dashboard
* @returns unknown
* @throws ApiError
*/
export const ensureUseDashboardStatsServiceGetHorizonApiStatsData = (queryClient: QueryClient) => queryClient.ensureQueryData({ queryKey: Common.UseDashboardStatsServiceGetHorizonApiStatsKeyFn(), queryFn: () => DashboardStatsService.getHorizonApiStats() });
/**
* Get all of the failed jobs
* @param data The data for the request.
* @param data.tag
* @returns unknown
* @throws ApiError
*/
export const ensureUseFailedJobsServiceGetHorizonApiJobsFailedData = (queryClient: QueryClient, { tag }: {
  tag?: string;
} = {}) => queryClient.ensureQueryData({ queryKey: Common.UseFailedJobsServiceGetHorizonApiJobsFailedKeyFn({ tag }), queryFn: () => FailedJobsService.getHorizonApiJobsFailed({ tag }) });
/**
* Get a failed job instance
* @param data The data for the request.
* @param data.id
* @returns unknown
* @throws ApiError
*/
export const ensureUseFailedJobsServiceGetHorizonApiJobsFailedByIdData = (queryClient: QueryClient, { id }: {
  id: string;
}) => queryClient.ensureQueryData({ queryKey: Common.UseFailedJobsServiceGetHorizonApiJobsFailedByIdKeyFn({ id }), queryFn: () => FailedJobsService.getHorizonApiJobsFailedById({ id }) });
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
export const ensureUseGenreServiceGetApiGenresData = (queryClient: QueryClient, { fields, librarySlug, limit, page, relations }: {
  fields?: string;
  librarySlug?: string;
  limit?: number;
  page?: number;
  relations?: string;
} = {}) => queryClient.ensureQueryData({ queryKey: Common.UseGenreServiceGetApiGenresKeyFn({ fields, librarySlug, limit, page, relations }), queryFn: () => GenreService.getApiGenres({ fields, librarySlug, limit, page, relations }) });
/**
* Get a genre
* @param data The data for the request.
* @param data.genre The genre slug
* @returns GenreResource `GenreResource`
* @throws ApiError
*/
export const ensureUseGenreServiceGetApiGenresByGenreData = (queryClient: QueryClient, { genre }: {
  genre: string;
}) => queryClient.ensureQueryData({ queryKey: Common.UseGenreServiceGetApiGenresByGenreKeyFn({ genre }), queryFn: () => GenreService.getApiGenresByGenre({ genre }) });
/**
* Get an image asset
* @param data The data for the request.
* @param data.image The image public id
* @returns string
* @throws ApiError
*/
export const ensureUseImageServiceGetApiImagesByImageData = (queryClient: QueryClient, { image }: {
  image: string;
}) => queryClient.ensureQueryData({ queryKey: Common.UseImageServiceGetApiImagesByImageKeyFn({ image }), queryFn: () => ImageService.getApiImagesByImage({ image }) });
/**
* Get all of the measured jobs
* @returns unknown
* @throws ApiError
*/
export const ensureUseJobMetricsServiceGetHorizonApiMetricsJobsData = (queryClient: QueryClient) => queryClient.ensureQueryData({ queryKey: Common.UseJobMetricsServiceGetHorizonApiMetricsJobsKeyFn(), queryFn: () => JobMetricsService.getHorizonApiMetricsJobs() });
/**
* Get metrics for a given job
* @param data The data for the request.
* @param data.id
* @returns unknown
* @throws ApiError
*/
export const ensureUseJobMetricsServiceGetHorizonApiMetricsJobsByIdData = (queryClient: QueryClient, { id }: {
  id: string;
}) => queryClient.ensureQueryData({ queryKey: Common.UseJobMetricsServiceGetHorizonApiMetricsJobsByIdKeyFn({ id }), queryFn: () => JobMetricsService.getHorizonApiMetricsJobsById({ id }) });
/**
* Get the details of a recent job by ID
* @param data The data for the request.
* @param data.id
* @returns unknown
* @throws ApiError
*/
export const ensureUseJobsServiceGetHorizonApiJobsByIdData = (queryClient: QueryClient, { id }: {
  id: string;
}) => queryClient.ensureQueryData({ queryKey: Common.UseJobsServiceGetHorizonApiJobsByIdKeyFn({ id }), queryFn: () => JobsService.getHorizonApiJobsById({ id }) });
/**
* @returns unknown
* @throws ApiError
*/
export const ensureUseLastFmServiceGetApiServicesLastfmAuthorizeData = (queryClient: QueryClient) => queryClient.ensureQueryData({ queryKey: Common.UseLastFmServiceGetApiServicesLastfmAuthorizeKeyFn(), queryFn: () => LastFmService.getApiServicesLastfmAuthorize() });
/**
* @param data The data for the request.
* @param data.token
* @param data.state This is the new token from Last.fm
* @param data.nonce
* @returns unknown
* @throws ApiError
*/
export const ensureUseLastFmServiceGetApiServicesLastfmCallbackData = (queryClient: QueryClient, { nonce, state, token }: {
  nonce?: string;
  state?: string;
  token?: string;
} = {}) => queryClient.ensureQueryData({ queryKey: Common.UseLastFmServiceGetApiServicesLastfmCallbackKeyFn({ nonce, state, token }), queryFn: () => LastFmService.getApiServicesLastfmCallback({ nonce, state, token }) });
/**
* @returns unknown
* @throws ApiError
*/
export const ensureUseLastFmServiceGetApiServicesLastfmStatusData = (queryClient: QueryClient) => queryClient.ensureQueryData({ queryKey: Common.UseLastFmServiceGetApiServicesLastfmStatusKeyFn(), queryFn: () => LastFmService.getApiServicesLastfmStatus() });
/**
* Get a collection of media libraries
* @param data The data for the request.
* @param data.page
* @param data.limit
* @returns unknown Paginated set of `LibraryResource`
* @throws ApiError
*/
export const ensureUseLibraryServiceGetApiLibrariesData = (queryClient: QueryClient, { limit, page }: {
  limit?: number;
  page?: number;
} = {}) => queryClient.ensureQueryData({ queryKey: Common.UseLibraryServiceGetApiLibrariesKeyFn({ limit, page }), queryFn: () => LibraryService.getApiLibraries({ limit, page }) });
/**
* Show library
* @param data The data for the request.
* @param data.slug
* @returns LibraryResource `LibraryResource`
* @throws ApiError
*/
export const ensureUseLibraryServiceGetApiLibrariesBySlugData = (queryClient: QueryClient, { slug }: {
  slug: string;
}) => queryClient.ensureQueryData({ queryKey: Common.UseLibraryServiceGetApiLibrariesBySlugKeyFn({ slug }), queryFn: () => LibraryService.getApiLibrariesBySlug({ slug }) });
/**
* Get a collection of log files
* @returns LogFile
* @throws ApiError
*/
export const ensureUseLogsServiceGetApiLogsData = (queryClient: QueryClient) => queryClient.ensureQueryData({ queryKey: Common.UseLogsServiceGetApiLogsKeyFn(), queryFn: () => LogsService.getApiLogs() });
/**
* Show a log file
* @param data The data for the request.
* @param data.logFile
* @returns unknown
* @throws ApiError
*/
export const ensureUseLogsServiceGetApiLogsByLogFileData = (queryClient: QueryClient, { logFile }: {
  logFile: string;
}) => queryClient.ensureQueryData({ queryKey: Common.UseLogsServiceGetApiLogsByLogFileKeyFn({ logFile }), queryFn: () => LogsService.getApiLogsByLogFile({ logFile }) });
/**
* Get log file content
* @param data The data for the request.
* @param data.logFile
* @param data.afterLine
* @param data.maxLines
* @returns unknown
* @throws ApiError
*/
export const ensureUseLogsServiceGetApiLogsByLogFileContentData = (queryClient: QueryClient, { afterLine, logFile, maxLines }: {
  afterLine?: number;
  logFile: string;
  maxLines?: number;
}) => queryClient.ensureQueryData({ queryKey: Common.UseLogsServiceGetApiLogsByLogFileContentKeyFn({ afterLine, logFile, maxLines }), queryFn: () => LogsService.getApiLogsByLogFileContent({ afterLine, logFile, maxLines }) });
/**
* Count log file lines
* @param data The data for the request.
* @param data.logFile
* @returns unknown
* @throws ApiError
*/
export const ensureUseLogsServiceGetApiLogsByLogFileLinesData = (queryClient: QueryClient, { logFile }: {
  logFile: string;
}) => queryClient.ensureQueryData({ queryKey: Common.UseLogsServiceGetApiLogsByLogFileLinesKeyFn({ logFile }), queryFn: () => LogsService.getApiLogsByLogFileLines({ logFile }) });
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
export const ensureUseLogsServiceGetApiLogsByLogFileSearchData = (queryClient: QueryClient, { caseSensitive, logFile, maxResults, pattern }: {
  caseSensitive?: boolean;
  logFile: string;
  maxResults?: number;
  pattern: string;
}) => queryClient.ensureQueryData({ queryKey: Common.UseLogsServiceGetApiLogsByLogFileSearchKeyFn({ caseSensitive, logFile, maxResults, pattern }), queryFn: () => LogsService.getApiLogsByLogFileSearch({ caseSensitive, logFile, maxResults, pattern }) });
/**
* Get log file tail
* @param data The data for the request.
* @param data.logFile
* @param data.lines
* @returns unknown
* @throws ApiError
*/
export const ensureUseLogsServiceGetApiLogsByLogFileTailData = (queryClient: QueryClient, { lines, logFile }: {
  lines?: number;
  logFile: string;
}) => queryClient.ensureQueryData({ queryKey: Common.UseLogsServiceGetApiLogsByLogFileTailKeyFn({ lines, logFile }), queryFn: () => LogsService.getApiLogsByLogFileTail({ lines, logFile }) });
/**
* Get log file head
* @param data The data for the request.
* @param data.logFile
* @param data.lines
* @returns unknown
* @throws ApiError
*/
export const ensureUseLogsServiceGetApiLogsByLogFileHeadData = (queryClient: QueryClient, { lines, logFile }: {
  lines?: number;
  logFile: string;
}) => queryClient.ensureQueryData({ queryKey: Common.UseLogsServiceGetApiLogsByLogFileHeadKeyFn({ lines, logFile }), queryFn: () => LogsService.getApiLogsByLogFileHead({ lines, logFile }) });
/**
* Get log file statistics
* @param data The data for the request.
* @param data.logFile
* @returns unknown
* @throws ApiError
*/
export const ensureUseLogsServiceGetApiLogsByLogFileStatsData = (queryClient: QueryClient, { logFile }: {
  logFile: string;
}) => queryClient.ensureQueryData({ queryKey: Common.UseLogsServiceGetApiLogsByLogFileStatsKeyFn({ logFile }), queryFn: () => LogsService.getApiLogsByLogFileStats({ logFile }) });
/**
* Download log file
* @param data The data for the request.
* @param data.logFile
* @returns string
* @throws ApiError
*/
export const ensureUseLogsServiceGetApiLogsByLogFileDownloadData = (queryClient: QueryClient, { logFile }: {
  logFile: string;
}) => queryClient.ensureQueryData({ queryKey: Common.UseLogsServiceGetApiLogsByLogFileDownloadKeyFn({ logFile }), queryFn: () => LogsService.getApiLogsByLogFileDownload({ logFile }) });
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
export const ensureUseLogsServiceGetApiLogsSearchAllData = (queryClient: QueryClient, { caseSensitive, files, maxResultsPerFile, pattern }: {
  caseSensitive?: boolean;
  files?: string[];
  maxResultsPerFile?: number;
  pattern: string;
}) => queryClient.ensureQueryData({ queryKey: Common.UseLogsServiceGetApiLogsSearchAllKeyFn({ caseSensitive, files, maxResultsPerFile, pattern }), queryFn: () => LogsService.getApiLogsSearchAll({ caseSensitive, files, maxResultsPerFile, pattern }) });
/**
* Get all of the master supervisors and their underlying supervisors
* @returns unknown
* @throws ApiError
*/
export const ensureUseMasterSupervisorServiceGetHorizonApiMastersData = (queryClient: QueryClient) => queryClient.ensureQueryData({ queryKey: Common.UseMasterSupervisorServiceGetHorizonApiMastersKeyFn(), queryFn: () => MasterSupervisorService.getHorizonApiMasters() });
/**
* Get all of the monitored tags and their job counts
* @returns unknown
* @throws ApiError
*/
export const ensureUseMonitoringServiceGetHorizonApiMonitoringData = (queryClient: QueryClient) => queryClient.ensureQueryData({ queryKey: Common.UseMonitoringServiceGetHorizonApiMonitoringKeyFn(), queryFn: () => MonitoringService.getHorizonApiMonitoring() });
/**
* Paginate the jobs for a given tag
* @param data The data for the request.
* @param data.tag
* @param data.limit
* @returns unknown
* @throws ApiError
*/
export const ensureUseMonitoringServiceGetHorizonApiMonitoringByTagData = (queryClient: QueryClient, { limit, tag }: {
  limit?: string;
  tag: string;
}) => queryClient.ensureQueryData({ queryKey: Common.UseMonitoringServiceGetHorizonApiMonitoringByTagKeyFn({ limit, tag }), queryFn: () => MonitoringService.getHorizonApiMonitoringByTag({ limit, tag }) });
/**
* Get a collection of movies
* @param data The data for the request.
* @param data.library The library slug
* @returns unknown Paginated set of `MovieResource`
* @throws ApiError
*/
export const ensureUseMovieServiceGetApiLibrariesByLibraryMoviesData = (queryClient: QueryClient, { library }: {
  library: string;
}) => queryClient.ensureQueryData({ queryKey: Common.UseMovieServiceGetApiLibrariesByLibraryMoviesKeyFn({ library }), queryFn: () => MovieService.getApiLibrariesByLibraryMovies({ library }) });
/**
* Get a movie
* @param data The data for the request.
* @param data.library The library slug
* @param data.movie The movie slug
* @returns MovieResource `MovieResource`
* @throws ApiError
*/
export const ensureUseMovieServiceGetApiLibrariesByLibraryMoviesByMovieData = (queryClient: QueryClient, { library, movie }: {
  library: string;
  movie: string;
}) => queryClient.ensureQueryData({ queryKey: Common.UseMovieServiceGetApiLibrariesByLibraryMoviesByMovieKeyFn({ library, movie }), queryFn: () => MovieService.getApiLibrariesByLibraryMoviesByMovie({ library, movie }) });
/**
* Get status
* @returns unknown
* @throws ApiError
*/
export const ensureUseOpCacheServiceGetApiOpcacheStatusData = (queryClient: QueryClient) => queryClient.ensureQueryData({ queryKey: Common.UseOpCacheServiceGetApiOpcacheStatusKeyFn(), queryFn: () => OpCacheService.getApiOpcacheStatus() });
/**
* Get config
* @returns unknown
* @throws ApiError
*/
export const ensureUseOpCacheServiceGetApiOpcacheConfigData = (queryClient: QueryClient) => queryClient.ensureQueryData({ queryKey: Common.UseOpCacheServiceGetApiOpcacheConfigKeyFn(), queryFn: () => OpCacheService.getApiOpcacheConfig() });
/**
* Get all of the pending jobs
* @param data The data for the request.
* @param data.startingAt
* @returns unknown
* @throws ApiError
*/
export const ensureUsePendingJobsServiceGetHorizonApiJobsPendingData = (queryClient: QueryClient, { startingAt }: {
  startingAt?: string;
} = {}) => queryClient.ensureQueryData({ queryKey: Common.UsePendingJobsServiceGetHorizonApiJobsPendingKeyFn({ startingAt }), queryFn: () => PendingJobsService.getHorizonApiJobsPending({ startingAt }) });
/**
* Get a collection of playlists
* @returns unknown Paginated set of `PlaylistResource`
* @throws ApiError
*/
export const ensureUsePlaylistServiceGetApiPlaylistsData = (queryClient: QueryClient) => queryClient.ensureQueryData({ queryKey: Common.UsePlaylistServiceGetApiPlaylistsKeyFn(), queryFn: () => PlaylistService.getApiPlaylists() });
/**
* Show a playlist
* @param data The data for the request.
* @param data.playlist The playlist public id
* @param data.relations
* @returns PlaylistResource `PlaylistResource`
* @throws ApiError
*/
export const ensureUsePlaylistServiceGetApiPlaylistsByPlaylistData = (queryClient: QueryClient, { playlist, relations }: {
  playlist: string;
  relations?: string;
}) => queryClient.ensureQueryData({ queryKey: Common.UsePlaylistServiceGetApiPlaylistsByPlaylistKeyFn({ playlist, relations }), queryFn: () => PlaylistService.getApiPlaylistsByPlaylist({ playlist, relations }) });
/**
* Get statistics
* @param data The data for the request.
* @param data.playlist The playlist public id
* @returns PlaylistStatistic `PlaylistStatistic`
* @throws ApiError
*/
export const ensureUsePlaylistServiceGetApiPlaylistsByPlaylistStatisticsData = (queryClient: QueryClient, { playlist }: {
  playlist: string;
}) => queryClient.ensureQueryData({ queryKey: Common.UsePlaylistServiceGetApiPlaylistsByPlaylistStatisticsKeyFn({ playlist }), queryFn: () => PlaylistService.getApiPlaylistsByPlaylistStatistics({ playlist }) });
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
export const ensureUseQueueServiceGetApiQueueMetricsData = (queryClient: QueryClient, { limit, name, page, queue, queuedFirst, status }: {
  limit?: number;
  name?: string;
  page?: number;
  queue?: string;
  queuedFirst?: boolean;
  status?: "running" | "succeeded" | "failed" | "stale" | "queued";
} = {}) => queryClient.ensureQueryData({ queryKey: Common.UseQueueServiceGetApiQueueMetricsKeyFn({ limit, name, page, queue, queuedFirst, status }), queryFn: () => QueueService.getApiQueueMetrics({ limit, name, page, queue, queuedFirst, status }) });
/**
* Get a list of queue names
* @returns unknown
* @throws ApiError
*/
export const ensureUseQueueServiceGetApiQueueMetricsQueuesData = (queryClient: QueryClient) => queryClient.ensureQueryData({ queryKey: Common.UseQueueServiceGetApiQueueMetricsQueuesKeyFn(), queryFn: () => QueueService.getApiQueueMetricsQueues() });
/**
* Get a metrics collection
* @param data The data for the request.
* @param data.aggregateDays
* @returns unknown
* @throws ApiError
*/
export const ensureUseQueueServiceGetApiQueueMetricsMetricsData = (queryClient: QueryClient, { aggregateDays }: {
  aggregateDays?: number;
} = {}) => queryClient.ensureQueryData({ queryKey: Common.UseQueueServiceGetApiQueueMetricsMetricsKeyFn({ aggregateDays }), queryFn: () => QueueService.getApiQueueMetricsMetrics({ aggregateDays }) });
/**
* Get all of the measured queues
* @returns unknown
* @throws ApiError
*/
export const ensureUseQueueMetricsServiceGetHorizonApiMetricsQueuesData = (queryClient: QueryClient) => queryClient.ensureQueryData({ queryKey: Common.UseQueueMetricsServiceGetHorizonApiMetricsQueuesKeyFn(), queryFn: () => QueueMetricsService.getHorizonApiMetricsQueues() });
/**
* Get metrics for a given queue
* @param data The data for the request.
* @param data.id
* @returns unknown
* @throws ApiError
*/
export const ensureUseQueueMetricsServiceGetHorizonApiMetricsQueuesByIdData = (queryClient: QueryClient, { id }: {
  id: string;
}) => queryClient.ensureQueryData({ queryKey: Common.UseQueueMetricsServiceGetHorizonApiMetricsQueuesByIdKeyFn({ id }), queryFn: () => QueueMetricsService.getHorizonApiMetricsQueuesById({ id }) });
/**
* @returns unknown
* @throws ApiError
*/
export const ensureUseSchemaServiceGetApiSchemasMusicbrainzData = (queryClient: QueryClient) => queryClient.ensureQueryData({ queryKey: Common.UseSchemaServiceGetApiSchemasMusicbrainzKeyFn(), queryFn: () => SchemaService.getApiSchemasMusicbrainz() });
/**
* Get all of the silenced jobs
* @param data The data for the request.
* @param data.startingAt
* @returns unknown
* @throws ApiError
*/
export const ensureUseSilencedJobsServiceGetHorizonApiJobsSilencedData = (queryClient: QueryClient, { startingAt }: {
  startingAt?: string;
} = {}) => queryClient.ensureQueryData({ queryKey: Common.UseSilencedJobsServiceGetHorizonApiJobsSilencedKeyFn({ startingAt }), queryFn: () => SilencedJobsService.getHorizonApiJobsSilenced({ startingAt }) });
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
export const ensureUseSongServiceGetApiLibrariesByLibrarySongsData = (queryClient: QueryClient, { genreNames, genreSlugs, library, limit, page, relations }: {
  genreNames?: string;
  genreSlugs?: string;
  library: string;
  limit?: number;
  page?: number;
  relations?: string;
}) => queryClient.ensureQueryData({ queryKey: Common.UseSongServiceGetApiLibrariesByLibrarySongsKeyFn({ genreNames, genreSlugs, library, limit, page, relations }), queryFn: () => SongService.getApiLibrariesByLibrarySongs({ genreNames, genreSlugs, library, limit, page, relations }) });
/**
* Get a song by public id
* @param data The data for the request.
* @param data.library The library slug
* @param data.publicId
* @param data.relations
* @returns SongResource `SongResource`
* @throws ApiError
*/
export const ensureUseSongServiceGetApiLibrariesByLibrarySongsByPublicIdData = (queryClient: QueryClient, { library, publicId, relations }: {
  library: string;
  publicId: string;
  relations?: string;
}) => queryClient.ensureQueryData({ queryKey: Common.UseSongServiceGetApiLibrariesByLibrarySongsByPublicIdKeyFn({ library, publicId, relations }), queryFn: () => SongService.getApiLibrariesByLibrarySongsByPublicId({ library, publicId, relations }) });
/**
* @returns unknown
* @throws ApiError
*/
export const ensureUseSpotifyServiceGetApiServicesSpotifyAuthorizeData = (queryClient: QueryClient) => queryClient.ensureQueryData({ queryKey: Common.UseSpotifyServiceGetApiServicesSpotifyAuthorizeKeyFn(), queryFn: () => SpotifyService.getApiServicesSpotifyAuthorize() });
/**
* @param data The data for the request.
* @param data.code
* @param data.state
* @param data.error
* @returns unknown
* @throws ApiError
*/
export const ensureUseSpotifyServiceGetApiServicesSpotifyCallbackData = (queryClient: QueryClient, { code, error, state }: {
  code?: string;
  error?: string;
  state?: string;
} = {}) => queryClient.ensureQueryData({ queryKey: Common.UseSpotifyServiceGetApiServicesSpotifyCallbackKeyFn({ code, error, state }), queryFn: () => SpotifyService.getApiServicesSpotifyCallback({ code, error, state }) });
/**
* @returns unknown
* @throws ApiError
*/
export const ensureUseSpotifyServiceGetApiServicesSpotifyStatusData = (queryClient: QueryClient) => queryClient.ensureQueryData({ queryKey: Common.UseSpotifyServiceGetApiServicesSpotifyStatusKeyFn(), queryFn: () => SpotifyService.getApiServicesSpotifyStatus() });
/**
* @returns string
* @throws ApiError
*/
export const ensureUseSpotifyServiceGetApiServicesSpotifyUserProfileData = (queryClient: QueryClient) => queryClient.ensureQueryData({ queryKey: Common.UseSpotifyServiceGetApiServicesSpotifyUserProfileKeyFn(), queryFn: () => SpotifyService.getApiServicesSpotifyUserProfile() });
/**
* @param data The data for the request.
* @param data.limit
* @param data.offset
* @returns string
* @throws ApiError
*/
export const ensureUseSpotifyServiceGetApiServicesSpotifyUserPlaylistsData = (queryClient: QueryClient, { limit, offset }: {
  limit?: string;
  offset?: string;
} = {}) => queryClient.ensureQueryData({ queryKey: Common.UseSpotifyServiceGetApiServicesSpotifyUserPlaylistsKeyFn({ limit, offset }), queryFn: () => SpotifyService.getApiServicesSpotifyUserPlaylists({ limit, offset }) });
/**
* @param data The data for the request.
* @param data.q
* @param data.type
* @param data.limit
* @param data.offset
* @param data.market
* @returns string
* @throws ApiError
*/
export const ensureUseSpotifyServiceGetApiServicesSpotifySearchData = (queryClient: QueryClient, { limit, market, offset, q, type }: {
  limit?: string;
  market?: string;
  offset?: string;
  q?: string;
  type?: string;
} = {}) => queryClient.ensureQueryData({ queryKey: Common.UseSpotifyServiceGetApiServicesSpotifySearchKeyFn({ limit, market, offset, q, type }), queryFn: () => SpotifyService.getApiServicesSpotifySearch({ limit, market, offset, q, type }) });
/**
* @returns string
* @throws ApiError
*/
export const ensureUseSpotifyServiceGetApiServicesSpotifyGenresSeedsData = (queryClient: QueryClient) => queryClient.ensureQueryData({ queryKey: Common.UseSpotifyServiceGetApiServicesSpotifyGenresSeedsKeyFn(), queryFn: () => SpotifyService.getApiServicesSpotifyGenresSeeds() });
/**
* Direct stream the song.
* Requires token with "access-stream"
* @param data The data for the request.
* @param data.song The song public id
* @returns unknown
* @throws ApiError
*/
export const ensureUseStreamServiceGetApiStreamSongBySongDirectData = (queryClient: QueryClient, { song }: {
  song: string;
}) => queryClient.ensureQueryData({ queryKey: Common.UseStreamServiceGetApiStreamSongBySongDirectKeyFn({ song }), queryFn: () => StreamService.getApiStreamSongBySongDirect({ song }) });
/**
* Get php info
* @returns unknown
* @throws ApiError
*/
export const ensureUseSystemInfoServiceGetApiSystemInfoData = (queryClient: QueryClient) => queryClient.ensureQueryData({ queryKey: Common.UseSystemInfoServiceGetApiSystemInfoKeyFn(), queryFn: () => SystemInfoService.getApiSystemInfo() });
/**
* @returns unknown
* @throws ApiError
*/
export const ensureUseSystemInfoServiceGetApiSystemInfoSysData = (queryClient: QueryClient) => queryClient.ensureQueryData({ queryKey: Common.UseSystemInfoServiceGetApiSystemInfoSysKeyFn(), queryFn: () => SystemInfoService.getApiSystemInfoSys() });
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
export const ensureUseUserServiceGetApiUsersData = (queryClient: QueryClient, { filterModes, filters, globalFilter, limit, page, sorting }: {
  filterModes?: string;
  filters?: string;
  globalFilter?: string;
  limit?: number;
  page?: number;
  sorting?: string;
} = {}) => queryClient.ensureQueryData({ queryKey: Common.UseUserServiceGetApiUsersKeyFn({ filterModes, filters, globalFilter, limit, page, sorting }), queryFn: () => UserService.getApiUsers({ filterModes, filters, globalFilter, limit, page, sorting }) });
/**
* Get small user detail info
* @param data The data for the request.
* @param data.user The user ID
* @returns UserResource `UserResource`
* @throws ApiError
*/
export const ensureUseUserServiceGetApiUsersByUserData = (queryClient: QueryClient, { user }: {
  user: number;
}) => queryClient.ensureQueryData({ queryKey: Common.UseUserServiceGetApiUsersByUserKeyFn({ user }), queryFn: () => UserService.getApiUsersByUser({ user }) });
/**
* Get the authenticated user
* @returns UserResource `UserResource`
* @throws ApiError
*/
export const ensureUseUserServiceGetApiUsersMeData = (queryClient: QueryClient) => queryClient.ensureQueryData({ queryKey: Common.UseUserServiceGetApiUsersMeKeyFn(), queryFn: () => UserService.getApiUsersMe() });
/**
* Get a collection of tokens
* @param data The data for the request.
* @param data.user
* @param data.page
* @param data.perPage
* @returns unknown Paginated set of `PersonalAccessTokenViewResource`
* @throws ApiError
*/
export const ensureUseUserTokenServiceGetApiUsersTokensByUserData = (queryClient: QueryClient, { page, perPage, user }: {
  page?: number;
  perPage?: number;
  user: string;
}) => queryClient.ensureQueryData({ queryKey: Common.UseUserTokenServiceGetApiUsersTokensByUserKeyFn({ page, perPage, user }), queryFn: () => UserTokenService.getApiUsersTokensByUser({ page, perPage, user }) });
/**
* Get the current queue workload for the application
* @returns unknown
* @throws ApiError
*/
export const ensureUseWorkloadServiceGetHorizonApiWorkloadData = (queryClient: QueryClient) => queryClient.ensureQueryData({ queryKey: Common.UseWorkloadServiceGetHorizonApiWorkloadKeyFn(), queryFn: () => WorkloadService.getHorizonApiWorkload() });
