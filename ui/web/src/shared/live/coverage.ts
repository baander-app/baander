import { adminLiveCoverage } from '@/features/admin/live-coverage'
import { authLiveCoverage } from '@/features/auth/live-coverage'
import { catalogLiveCoverage } from '@/features/catalog/live-coverage'
import { equalizerLiveCoverage } from '@/features/equalizer/live-coverage'
import { layoutLiveCoverage } from '@/features/layout/live-coverage'
import { libraryLiveCoverage } from '@/features/library/live-coverage'
import { notificationLiveCoverage } from '@/features/notification/live-coverage'
import { playerLiveCoverage } from '@/features/player/live-coverage'
import { playlistLiveCoverage } from '@/features/playlist/live-coverage'
import { radioLiveCoverage } from '@/features/radio/live-coverage'
import { sessionLiveCoverage } from '@/features/session/live-coverage'
import { settingsLiveCoverage } from '@/features/settings/live-coverage'
import { visualizerLiveCoverage } from '@/features/visualizer/live-coverage'
import { sharedLiveCoverage } from './coverage-assets'

export const LIVE_COVERAGE = [
  ...adminLiveCoverage,
  ...authLiveCoverage,
  ...catalogLiveCoverage,
  ...equalizerLiveCoverage,
  ...layoutLiveCoverage,
  ...libraryLiveCoverage,
  ...notificationLiveCoverage,
  ...playerLiveCoverage,
  ...playlistLiveCoverage,
  ...radioLiveCoverage,
  ...sessionLiveCoverage,
  ...settingsLiveCoverage,
  ...visualizerLiveCoverage,
  ...sharedLiveCoverage,
] as const
