import type { LiveCoverageDescriptor } from '@/shared/live/coverage-types'

export const radioLiveCoverage = [
  {
    id: "radio.radio-global",
    family: "radio",
    policy: {
      kind: "stored",
      reason: "Authenticated listeners share the imported public station, source and country catalog.",
    },
    audiences: ["global"],
    sourceTables: ["radio_stations", "radio_sources"],
    deploymentTables: [],
    sources: ["'/api/radio/sources'", "'/api/radio/countries'", "'/api/radio/stations'"],
    keyVariants: ["['radio','countries']", "[...STATIONS_KEY,{country,query}];STATIONS_KEY=['radio','stations']asconst"],
    dependentFamilies: [],
    mirrors: [],
    responseCaches: ["authoritative-read"],
    consumers: [
      "src/features/radio/api/radio-api.ts#AXIOS_INSTANCE.get:'/api/radio/sources'",
      "src/features/radio/api/radio-api.ts#AXIOS_INSTANCE.get:'/api/radio/countries'",
      "src/features/radio/api/radio-api.ts#AXIOS_INSTANCE.get:'/api/radio/stations'",
      "src/features/radio/components/CountryPicker.tsx#useQuery:['radio','countries']",
      "src/features/radio/hooks/use-radio-stations.ts#useQuery:[...STATIONS_KEY,{country,query}];STATIONS_KEY=['radio','stations']asconst",
    ],
    coverageVerification: ["tests/live-updates/coverage.test.ts"],
    behaviorVerification: "pending-adoption",
  },
  {
    id: "radio.radio-user",
    family: "radio",
    policy: {
      kind: "stored",
      reason: "Subscriptions, stars and current radio sessions are private to the authenticated user; joined station metadata also affects their presentation.",
    },
    audiences: ["user"],
    sourceTables: ["country_subscriptions", "starred_stations", "radio_sessions", "radio_stations"],
    deploymentTables: [],
    sources: ["'/api/radio/subscriptions'", "'/api/radio/starred'", "'/api/radio/session'"],
    keyVariants: [
      "[...SUBSCRIPTIONS_KEY];SUBSCRIPTIONS_KEY=['radio','subscriptions']asconst",
      "[...STARRED_KEY];STARRED_KEY=['radio','starred']asconst",
    ],
    dependentFamilies: [],
    mirrors: ["radio-store catalog starred-stations query"],
    responseCaches: ["authoritative-read"],
    consumers: [
      "src/features/radio/api/radio-api.ts#AXIOS_INSTANCE.get:'/api/radio/subscriptions'",
      "src/features/radio/api/radio-api.ts#AXIOS_INSTANCE.get:'/api/radio/starred'",
      "src/features/radio/api/radio-api.ts#AXIOS_INSTANCE.get:'/api/radio/session'",
      "src/features/radio/hooks/use-radio-stations.ts#useQuery:[...SUBSCRIPTIONS_KEY];SUBSCRIPTIONS_KEY=['radio','subscriptions']asconst",
      "src/features/radio/hooks/use-radio-stations.ts#useQuery:[...STARRED_KEY];STARRED_KEY=['radio','starred']asconst",
    ],
    coverageVerification: ["tests/live-updates/coverage.test.ts"],
    behaviorVerification: "pending-adoption",
  },
] as const satisfies readonly LiveCoverageDescriptor[]
