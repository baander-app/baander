import type { LiveCoverageDescriptor } from '@/shared/live/coverage-types'

export const visualizerLiveCoverage = [
  {
    id: "visualizer.binary-assets",
    family: "catalog",
    policy: {
      kind: "demand",
      reason: "Binary image reads follow the selected source URL; catalog refresh replaces that URL, and palette extraction is derived local work.",
    },
    audiences: ["library"],
    sourceTables: ["images"],
    deploymentTables: [],
    sources: ["authenticated image bytes", "imageUrl"],
    keyVariants: [],
    dependentFamilies: [],
    mirrors: [],
    responseCaches: ["authoritative-read"],
    consumers: ["src/features/visualizer/utils/extract-palette.ts#fetch:imageUrl"],
    coverageVerification: ["tests/live-updates/coverage.test.ts"],
    behaviorVerification: "pending-adoption",
  },
] as const satisfies readonly LiveCoverageDescriptor[]
