import type { LiveCoverageDescriptor } from '@/shared/live/coverage-types'

export const libraryLiveCoverage = [
  {
    id: "library.libraries",
    family: "libraries",
    policy: {
      kind: "stored",
      reason: "Library lifecycle, access, indexing and media counts affect lists, details and stats.",
    },
    audiences: ["library", "user"],
    sourceTables: ["libraries", "user_library_access", "library_file_index", "albums", "songs", "movies", "videos"],
    deploymentTables: [],
    sources: ["'/api/libraries'", "`/api/libraries/${id}`", "`/api/libraries/${id}/stats`"],
    keyVariants: ["libraryKeys.list(type)", "libraryKeys.stats(id)", "libraryKeys.detail(id)"],
    dependentFamilies: ["catalog", "activity", "recommendations"],
    mirrors: [],
    responseCaches: ["authoritative-read"],
    consumers: [
      "src/features/library/api/library-api.ts#AXIOS_INSTANCE.get:'/api/libraries'",
      "src/features/library/api/library-api.ts#AXIOS_INSTANCE.get:`/api/libraries/${id}`",
      "src/features/library/api/library-api.ts#AXIOS_INSTANCE.get:`/api/libraries/${id}/stats`",
      "src/features/library/hooks/use-libraries.ts#useQuery:libraryKeys.list(type)",
      "src/features/library/hooks/use-library-stats.ts#useQuery:libraryKeys.stats(id)",
      "src/features/library/hooks/use-library.ts#useQuery:libraryKeys.detail(id)",
      "src/features/library/hooks/use-scan-polling.ts#useQuery:libraryKeys.list()",
    ],
    coverageVerification: ["tests/live-updates/coverage.test.ts"],
    behaviorVerification: {
      unitProofs: ['tests/live-updates/library-consumers.test.tsx'],
    },
  },
] as const satisfies readonly LiveCoverageDescriptor[]
