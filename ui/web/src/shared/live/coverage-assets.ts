import type { LiveCoverageDescriptor } from '@/shared/live/coverage-types'

export const sharedLiveCoverage = [
  {
    id: "shared.binary-assets",
    family: "catalog",
    policy: {
      kind: "demand",
      reason: "Binary image reads follow the selected source URL; catalog refresh replaces that URL, and palette extraction is derived local work.",
    },
    audiences: ["library"],
    sourceTables: ["images"],
    deploymentTables: [],
    sources: ["authenticated image bytes", "source"],
    keyVariants: [],
    dependentFamilies: [],
    mirrors: [],
    responseCaches: ["authoritative-read"],
    consumers: ["src/shared/hooks/use-image-blob.ts#AXIOS_INSTANCE.get:source"],
    coverageVerification: ["tests/live-updates/coverage.test.ts"],
    behaviorVerification: "pending-adoption",
  },
] as const satisfies readonly LiveCoverageDescriptor[]
