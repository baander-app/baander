import type { LiveCoverageDescriptor } from '@/shared/live/coverage-types'

export const settingsLiveCoverage = [
  {
    id: "settings.preferences",
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
    sources: [
      "'/api/user/theme-mood/'",
      "'/api/user/accent-color/'",
      "baseUrl",
      "`${baseUrl.replace(/\\/$/,'')}/history`",
      "[ `/api/user/settings` ]",
    ],
    keyVariants: ["['preference-history',label]", "[ `/api/user/settings` ]"],
    dependentFamilies: [],
    mirrors: [
      "src/features/player/stores/player-store.ts",
      "src/features/layout/stores/context-panel-store.ts",
      "src/features/equalizer/stores/eq-bands-store.ts",
      "src/features/equalizer/stores/eq-processing-store.ts",
      "theme/accent localStorage",
    ],
    responseCaches: ["authoritative-read"],
    consumers: [
      "src/features/settings/components/PreferenceHistory.tsx#useQuery:['preference-history',label]",
      "src/features/settings/hooks/use-preference-bootstrap.tsx#AXIOS_INSTANCE.get:'/api/user/theme-mood/'",
      "src/features/settings/hooks/use-preference-bootstrap.tsx#AXIOS_INSTANCE.get:'/api/user/accent-color/'",
      "src/features/settings/hooks/use-preference-sync.ts#AXIOS_INSTANCE.get:baseUrl",
      "src/features/settings/hooks/use-preference-sync.ts#AXIOS_INSTANCE.get:`${baseUrl.replace(/\\/$/,'')}/history`",
      "src/features/settings/hooks/use-user-settings.ts#useGetUserSettingsIndex:generated",
    ],
    coverageVerification: ["tests/live-updates/coverage.test.ts"],
    behaviorVerification: {
      unitProofs: [
        "tests/live-updates/preferences-consumers.test.tsx",
        "src/features/settings/__tests__/preference-conflict-lifecycle.test.ts",
        "src/features/settings/__tests__/use-preference-bootstrap.test.tsx",
      ],
    },
  },
  {
    id: "settings.account",
    family: "account",
    policy: {
      kind: "stored",
      reason: "Principal identity, verification, passkeys and authorization state require authoritative refresh and scoped eviction.",
    },
    audiences: ["user"],
    sourceTables: ["users", "passkeys", "oauth_clients", "oauth_device_codes", "user_library_access"],
    deploymentTables: ["oauth_scopes"],
    sources: ["'/api/auth/passkey'"],
    keyVariants: ["PASSKEYS_QUERY_KEY=['passkeys']asconst"],
    dependentFamilies: ["admin-users"],
    mirrors: ["auth-store"],
    responseCaches: ["authoritative-read"],
    consumers: [
      "src/features/settings/hooks/use-passkey-list.ts#AXIOS_INSTANCE.get:'/api/auth/passkey'",
      "src/features/settings/hooks/use-passkey-list.ts#useQuery:PASSKEYS_QUERY_KEY=['passkeys']asconst",
    ],
    coverageVerification: ["tests/live-updates/coverage.test.ts"],
    behaviorVerification: {
      unitProofs: [
        "tests/live-updates/preferences-consumers.test.tsx",
        "src/features/settings/hooks/__tests__/use-passkey-registration.test.ts",
      ],
    },
  },
] as const satisfies readonly LiveCoverageDescriptor[]
