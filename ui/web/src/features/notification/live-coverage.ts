import type { LiveCoverageDescriptor } from '@/shared/live/coverage-types'

export const notificationLiveCoverage = [
  {
    id: "notification.notifications",
    family: "notifications",
    policy: {
      kind: "stored",
      reason: "Creation, deletion and read-state changes affect the paginated list and unread count together.",
    },
    audiences: ["user"],
    sourceTables: ["notifications"],
    deploymentTables: [],
    sources: ["'/api/notifications/'", "'/api/notifications/unread-count'"],
    keyVariants: ["notificationsKey=['notifications']asconst", "unreadCountKey=['notification-unread-count']asconst"],
    dependentFamilies: ["admin-alerts"],
    mirrors: ["notification-store"],
    responseCaches: ["authoritative-read"],
    consumers: [
      "src/features/notification/api/notification-api.ts#AXIOS_INSTANCE.get:'/api/notifications/'",
      "src/features/notification/api/notification-api.ts#AXIOS_INSTANCE.get:'/api/notifications/unread-count'",
      "src/features/notification/hooks/use-notifications.ts#useInfiniteQuery:notificationsKey=['notifications']asconst",
      "src/features/notification/hooks/use-notifications.ts#useQuery:unreadCountKey=['notification-unread-count']asconst",
    ],
    coverageVerification: ["tests/live-updates/coverage.test.ts"],
    behaviorVerification: { unitProofs: ["tests/live-updates/personal-state.test.tsx", "src/features/notification/hooks/__tests__/use-notifications.test.tsx"], endToEnd: "pending" },
  },
] as const satisfies readonly LiveCoverageDescriptor[]
