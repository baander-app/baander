/**
 * Files allowed to bypass the type roles, each for one narrow reason.
 *
 * Admin table, badge and chip files may set text at the 11px dense size. Canvas
 * text and SVG text measured in `viewBox` units are drawn rather than laid out by
 * CSS, so their sizes are not in pixels the type roles can express.
 */
export type CensusExceptionKind = 'admin-table' | 'badge' | 'chip' | 'canvas' | 'svg-viewbox'

export interface CensusException {
  readonly file: string
  readonly kind: CensusExceptionKind
  readonly reason: string
}

export const TYPOGRAPHY_ALLOWLIST: readonly CensusException[] = [
  { file: 'src/features/admin/components/diagnostics/diagnostics-styles.ts', kind: 'admin-table', reason: 'Header cells of the worker and coroutine diagnostics tables.' },
  { file: 'src/features/admin/pages/ActivityPage.tsx', kind: 'admin-table', reason: 'Header row of the activity log table.' },
  { file: 'src/features/admin/pages/AdminUsersPage.tsx', kind: 'admin-table', reason: 'Column labels and date cells of the user table.' },
  { file: 'src/features/admin/pages/GenresPage.tsx', kind: 'admin-table', reason: 'Header row of the genre table.' },
  { file: 'src/features/admin/pages/LoginBlocksPage.tsx', kind: 'admin-table', reason: 'Header row of the login block table.' },
  { file: 'src/features/admin/pages/OAuthClientsPage.tsx', kind: 'admin-table', reason: 'Column labels, client IDs and date cells of the OAuth client table.' },
  { file: 'src/features/admin/pages/RadioAdminPage.tsx', kind: 'admin-table', reason: 'Header row of the radio station table.' },
  { file: 'src/features/admin/pages/RecommendationsPage.tsx', kind: 'admin-table', reason: 'Header row of the recommendation table.' },
  { file: 'src/features/admin/pages/SchedulerPage.tsx', kind: 'admin-table', reason: 'Column labels, descriptions and date cells of the scheduled job table.' },
  { file: 'src/features/admin/components/layout/AdminNotificationBell.tsx', kind: 'badge', reason: 'Unread count badge on the admin notification bell.' },
  { file: 'src/features/admin/components/users/RoleBadge.tsx', kind: 'badge', reason: 'User role badge.' },
  { file: 'src/features/library/components/LibraryCard.tsx', kind: 'badge', reason: 'Scan status and library type badges.' },
  { file: 'src/features/library/pages/LibraryDetailPage.tsx', kind: 'badge', reason: 'Scan status badge.' },
  { file: 'src/features/notification/components/NotificationBell.tsx', kind: 'badge', reason: 'Unread count badge on the notification bell.' },
  { file: 'src/features/playlist/components/SmartPlaylistEditor.tsx', kind: 'badge', reason: 'Rule operator badge.' },
  { file: 'src/features/playlist/pages/PlaylistDetailPage.tsx', kind: 'badge', reason: 'Smart playlist badge.' },
  { file: 'src/features/playlist/pages/PlaylistsPage.tsx', kind: 'badge', reason: 'Shared playlist badge.' },
  { file: 'src/features/radio/components/RadioPlayerBar.tsx', kind: 'badge', reason: 'Live badge in the radio player bar.' },
  { file: 'src/features/radio/components/StationCard.tsx', kind: 'chip', reason: 'Genre chips on a station card.' },
  { file: 'src/features/visualizer/renderers/spectrogram.ts', kind: 'canvas', reason: 'Peak frequency label drawn on the spectrogram canvas.' },
  { file: 'src/features/equalizer/components/EqualizerAnalyzer.tsx', kind: 'svg-viewbox', reason: 'Goniometer axis labels sized in units of a 100-unit viewBox.' },
]
