import type { LiveCoverageDescriptor } from '@/shared/live/coverage-types'

export const authLiveCoverage = [
  {
    id: "auth.account-demand",
    family: "account",
    policy: {
      kind: "demand",
      reason: "OAuth authorization and device verification are request-bound consent reads, fetched when that flow is opened.",
    },
    audiences: ["user"],
    sourceTables: ["users", "oauth_clients", "oauth_device_codes"],
    deploymentTables: ["oauth_scopes"],
    sources: ["[ `/api/oauth/device/verify`, ...(params ? [params] : []) ]", "AUTHORIZE_URL"],
    keyVariants: ["['oauth-authorize',query]"],
    dependentFamilies: [],
    mirrors: [],
    responseCaches: ["authoritative-read"],
    consumers: [
      "src/features/auth/api/device-authorization-api.ts#getOauthDeviceVerify:generated",
      "src/features/auth/api/oauth-authorize-api.ts#AXIOS_INSTANCE.get:AUTHORIZE_URL",
      "src/features/auth/components/OAuthConsent.tsx#useQuery:['oauth-authorize',query]",
    ],
    coverageVerification: ["tests/live-updates/coverage.test.ts"],
    behaviorVerification: { unitProofs: ["tests/live-updates/account-consumers.test.tsx"] },
  },
  {
    id: "auth.account",
    family: "account",
    policy: {
      kind: "stored",
      reason: "Principal identity, verification, passkeys and authorization state require authoritative refresh and scoped eviction.",
    },
    audiences: ["user"],
    sourceTables: ["users", "passkeys", "oauth_clients", "oauth_device_codes", "user_library_access"],
    deploymentTables: ["oauth_scopes"],
    sources: ["[ `/api/auth/me` ]"],
    keyVariants: ["[ `/api/auth/me` ]"],
    dependentFamilies: ["admin-users"],
    mirrors: ["auth-store"],
    responseCaches: ["authoritative-read"],
    consumers: [
      "src/features/auth/components/VerifyEmail.tsx#getAuthMe:generated",
      "src/features/auth/hooks/use-current-user-refresh.ts#useGetAuthMe:generated",
    ],
    coverageVerification: ["tests/live-updates/coverage.test.ts"],
    behaviorVerification: { unitProofs: ["tests/live-updates/account-consumers.test.tsx"] },
  },
] as const satisfies readonly LiveCoverageDescriptor[]
