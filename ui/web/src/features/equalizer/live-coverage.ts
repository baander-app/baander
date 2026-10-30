import type { LiveCoverageDescriptor } from '@/shared/live/coverage-types'

export const equalizerLiveCoverage = [
  {
    id: "equalizer.preferences",
    family: "preferences",
    policy: {
      kind: "stored",
      reason: "Server preferences, sidebar schemas, profile lists and history refresh through draft-aware store adapters.",
    },
    audiences: ["user"],
    sourceTables: [
      "audio_preferences",
      "player_preferences",
      "layout_preferences",
      "preference_history",
      "user_theme_moods",
      "user_accent_colors",
      "user_settings",
      "user_sidebar_configs",
      "eq_device_profiles",
      "notification_preferences",
    ],
    deploymentTables: [],
    sources: ["'/api/user/eq-profiles/'"],
    keyVariants: [],
    dependentFamilies: [],
    mirrors: ["src/features/equalizer/stores/eq-profiles-store.ts"],
    responseCaches: ["authoritative-read"],
    consumers: ["src/features/equalizer/components/ProfileSelector.tsx#AXIOS_INSTANCE.get:'/api/user/eq-profiles/'"],
    coverageVerification: ["tests/live-updates/coverage.test.ts"],
    behaviorVerification: {
      unitProofs: ["tests/live-updates/preferences-consumers.test.tsx"],
      endToEnd: 'pending',
    },
  },
] as const satisfies readonly LiveCoverageDescriptor[]
