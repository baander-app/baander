import type { LiveCoverageDescriptor } from '@/shared/live/coverage-types'

export const sharedLiveCoverage = [
  {
    id: "shared.binary-assets",
    family: "catalog",
    policy: {
      kind: "stored",
      reason: "Mounted authenticated image queries refresh after catalog changes, including stable cover URLs; palettes derive from the same owned bytes.",
    },
    audiences: ["library"],
    sourceTables: ["images"],
    deploymentTables: [],
    sources: ["authenticated image bytes", "source", "[catalog, binary-image, source, ownership.generation]"],
    keyVariants: ["[catalog, binary-image, source, ownership.generation]"],
    dependentFamilies: [],
    mirrors: ["component-owned image object URLs", "album palettes derived from authenticated image bytes"],
    responseCaches: ["authoritative-read"],
    consumers: [
      "src/shared/hooks/use-image-blob.ts#useQuery:['catalog','binary-image',source,ownership.generation]",
      "src/shared/hooks/use-image-blob.ts#AXIOS_INSTANCE.get:source",
    ],
    coverageVerification: ["tests/live-updates/coverage.test.ts"],
    behaviorVerification: {
      unitProofs: ["src/shared/hooks/__tests__/use-image-blob.test.ts", "src/features/visualizer/hooks/use-album-palette.test.tsx", "tests/live-updates/client-lifecycle.test.tsx"],
      endToEnd: 'pending',
    },
  },
] as const satisfies readonly LiveCoverageDescriptor[]

/** Protocol controls establish authority and never enter resource refresh scheduling. */
export const LIVE_PROTOCOL_CONTROLS = [{
  consumer: 'src/shared/live/recovery.ts#getLiveRecovery:generated',
  purpose: 'authorization-recovery',
  reason: 'Reconciles current allowed families and health before protected resource reads resume.',
}] as const satisfies readonly import('./coverage-types').LiveProtocolControl[]
