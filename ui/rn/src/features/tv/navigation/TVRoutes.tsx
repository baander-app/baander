/**
 * TV routes -- route names and type definitions.
 *
 * All TV screens are defined here. Add new routes to the TVRouteName union type.
 */

export type TVRouteName =
  | 'TVHome'
  | 'TVAlbumDetail'
  | 'TVArtistDetail'
  | 'TVSearch'
  | 'TVGenres'
  | 'TVAdminDashboard'
  | 'TVAdminJobs'
  | 'TVAdminRateLimiters'
  | 'TVAdminDiagnostics'
  | 'TVAdminConfiguration'
  | 'TVAdminUsers'
  | 'TVAdminActivity'
  | 'TVAdminGenres'
  | 'TVAdminMetadata'
  | 'TVAdminRecommendations'
  | 'TVAdminTranscode'
  | 'TVAdminQoL'
  | 'TVAdminLyrics'
  | 'TVAdminDuplicates'
  | 'TVLogin';

export interface TVRouteParams {
  TVHome: undefined;
  TVAlbumDetail: { publicId: string };
  TVArtistDetail: { publicId: string };
  TVSearch: undefined;
  TVGenres: undefined;
  TVAdminDashboard: undefined;
  TVAdminJobs: undefined;
  TVAdminRateLimiters: undefined;
  TVAdminDiagnostics: undefined;
  TVAdminConfiguration: undefined;
  TVAdminUsers: undefined;
  TVAdminActivity: undefined;
  TVAdminGenres: undefined;
  TVAdminMetadata: undefined;
  TVAdminRecommendations: undefined;
  TVAdminTranscode: undefined;
  TVAdminQoL: undefined;
  TVAdminLyrics: undefined;
  TVAdminDuplicates: undefined;
  TVLogin: undefined;
}
