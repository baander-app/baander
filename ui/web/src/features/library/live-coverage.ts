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
    keyVariants: ["['libraries',type]", "['libraries',id,'stats']", "['libraries',id]"],
    dependentFamilies: ["catalog", "activity", "recommendations"],
    mirrors: [],
    responseCaches: ["authoritative-read"],
    consumers: [
      "src/features/library/api/library-api.ts#AXIOS_INSTANCE.get:'/api/libraries'",
      "src/features/library/api/library-api.ts#AXIOS_INSTANCE.get:`/api/libraries/${id}`",
      "src/features/library/api/library-api.ts#AXIOS_INSTANCE.get:`/api/libraries/${id}/stats`",
      "src/features/library/hooks/use-libraries.ts#useQuery:['libraries',type]",
      "src/features/library/hooks/use-library-stats.ts#useQuery:['libraries',id,'stats']",
      "src/features/library/hooks/use-library.ts#useQuery:['libraries',id]",
    ],
    coverageVerification: ["tests/live-updates/coverage.test.ts"],
    behaviorVerification: "pending-adoption",
  },
] as const satisfies readonly LiveCoverageDescriptor[]
