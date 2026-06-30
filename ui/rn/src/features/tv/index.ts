/**
 * TV feature exports.
 */

// Components
export { TVAppShell } from './components/TVAppShell';
export { TVFocusable } from './components/TVFocusable';
export { TVNavigationBar } from './components/TVNavigationBar';
export { TVCard } from './components/TVCard';
export { TVContentRow } from './components/TVContentRow';
export { TVHeroSection } from './components/TVHeroSection';
export { TVTrackRow } from './components/TVTrackRow';
export { TVSectionHeader } from './components/TVSectionHeader';
export { TVAdminShell } from './components/TVAdminShell';
export { TVAdminRoute } from './components/TVAdminRoute';
export { TVNowPlayingOverlay } from './components/TVNowPlayingOverlay';
export { TVPlaybackControls } from './components/TVPlaybackControls';
export { TVStatGrid } from './components/TVStatGrid';
export { TVJobList } from './components/TVJobList';
export type { TVFocusableProps } from './components/TVFocusable';
export type { TVNavigationBarProps } from './components/TVNavigationBar';
export type { TVCardProps } from './components/TVCard';
export type { TVContentRowProps } from './components/TVContentRow';
export type { TVHeroSectionProps } from './components/TVHeroSection';
export type { TVTrackRowProps } from './components/TVTrackRow';
export type { TVSectionHeaderProps } from './components/TVSectionHeader';
export type { TVAdminShellProps } from './components/TVAdminShell';
export type { TVAdminRouteProps } from './components/TVAdminRoute';
export type { TVPlaybackControlsProps } from './components/TVPlaybackControls';

// Pages
export { TVHomePage } from './pages/TVHomePage';
export { TVAlbumDetailPage } from './pages/TVAlbumDetailPage';
export { TVArtistDetailPage } from './pages/TVArtistDetailPage';
export { TVSearchPage } from './pages/TVSearchPage';
export { TVGenresPage } from './pages/TVGenresPage';
export { TVLoginPage } from './pages/TVLoginPage';
export { TVAdminAccessDeniedPage } from './pages/TVAdminAccessDeniedPage';
export { TVAdminDashboardPage } from './pages/TVAdminDashboardPage';
export { TVJobMonitorPage } from './pages/TVJobMonitorPage';
export { TVRateLimitersPage } from './pages/TVRateLimitersPage';
export { TVServerDiagnosticsPage } from './pages/TVServerDiagnosticsPage';
export { TVConfigurationPage } from './pages/TVConfigurationPage';
export { TVUsersPage } from './pages/TVUsersPage';
export { TVActivityPage } from './pages/TVActivityPage';
export { TVGenresAdminPage } from './pages/TVGenresPage-Admin';
export { TVMetadataPage } from './pages/TVMetadataPage';
export { TVRecommendationsPage } from './pages/TVRecommendationsPage';
export { TVTranscodePage } from './pages/TVTranscodePage';
export { TVLyricsAdminPage } from './pages/TVLyricsAdminPage';
export { TVAlbumDuplicatesPage } from './pages/TVAlbumDuplicatesPage';
export type { TVAdminAccessDeniedPageProps } from './pages/TVAdminAccessDeniedPage';

// Navigation
export { TVNavigator } from './navigation/TVNavigator';
export type { TVRouteName, TVRouteParams } from './navigation/TVRoutes';

// Hooks
export { useTVFocus, useTVFocusRestoration } from './hooks/use-tv-focus';
export { useTVNowPlaying } from './hooks/use-tv-now-playing';
export type { FocusState } from './hooks/use-tv-focus';
export type { UseTVNowPlayingResult } from './hooks/use-tv-now-playing';

// Re-export tokens for convenience
export {
  tvSpacing,
  tvRadii,
  tvFontSizes,
  tvSizes,
  tvColors,
} from './theme/tv-tokens';
