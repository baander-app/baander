import type { LiveCoverageDescriptor } from '@/shared/live/coverage-types'

// Palette extraction consumes the authenticated binary query and performs no HTTP reads.
export const visualizerLiveCoverage = [] as const satisfies readonly LiveCoverageDescriptor[]
