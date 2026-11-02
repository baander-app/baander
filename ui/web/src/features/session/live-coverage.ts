import type { LiveCoverageDescriptor } from '@/shared/live/coverage-types'

export const sessionLiveCoverage = [
  {
    id: 'session.devices',
    family: 'devices',
    policy: {
      kind: 'stored',
      reason: 'Registered-device changes refresh the device management list.',
    },
    audiences: ['user'],
    sourceTables: ['devices', 'users'],
    deploymentTables: [],
    sources: ['[ `/api/devices` ]'],
    keyVariants: ['[ `/api/devices` ]'],
    dependentFamilies: [],
    mirrors: [],
    responseCaches: ['authoritative-read'],
    consumers: ['src/features/session/components/DeviceManagement.tsx#useGetDeviceList:generated'],
    coverageVerification: ['tests/live-updates/coverage.test.ts'],
    behaviorVerification: { unitProofs: ['src/features/session/components/__tests__/DeviceManagement.test.tsx'] },
  },
  {
    id: 'session.sessions',
    family: 'sessions',
    policy: {
      kind: 'stored',
      reason: 'The shared connection supplies complete playback snapshots; HTTP recovers endpoint authority after reconnect.',
    },
    audiences: ['user'],
    sourceTables: ['listening_sessions', 'devices'],
    deploymentTables: [],
    sources: ["'/api/session/snapshot'", 'PLAYBACK_CONNECTION_KEY'],
    keyVariants: ["PLAYBACK_SESSION_KEY=['session','current']", "PLAYBACK_CONNECTION_KEY=['session','connection']"],
    dependentFamilies: [],
    mirrors: ['The playback query cache holds server snapshots and tab connection status; disabled observers never poll.'],
    responseCaches: ['authoritative-read'],
    consumers: [
      'src/features/session/hooks/use-playback-session.ts#useQuery:PLAYBACK_SESSION_KEY',
      'src/features/session/hooks/use-playback-session.ts#useQuery:PLAYBACK_CONNECTION_KEY',
      "src/features/session/services/playback-session-coordinator.ts#AXIOS_INSTANCE.get:'/api/session/snapshot'",
    ],
    coverageVerification: ['tests/live-updates/coverage.test.ts'],
    behaviorVerification: { unitProofs: ['src/features/session/__tests__/playback-session-coordinator.test.ts', 'src/features/session/__tests__/playback-destination-selector.test.tsx'] },
  },
] as const satisfies readonly LiveCoverageDescriptor[]
