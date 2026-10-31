import type { LiveFamily } from './protocol'

export type RefreshPolicy =
  | { kind: 'stored'; reason: string }
  | { kind: 'sampled'; intervalMs: 5000 | 10000 | 30000; reason: string }
  | { kind: 'demand'; reason: string }
  | { kind: 'deployment'; reason: string }

export interface LiveCoverageDescriptor {
  id: string
  family: LiveFamily
  policy: RefreshPolicy
  audiences: readonly ('user' | 'library' | 'playlist' | 'admin' | 'global')[]
  sourceTables: readonly string[]
  deploymentTables: readonly string[]
  sources: readonly string[]
  keyVariants: readonly string[]
  dependentFamilies: readonly LiveFamily[]
  mirrors: readonly string[]
  responseCaches: readonly string[]
  consumers: readonly string[]
  coverageVerification: readonly string[]
  behaviorVerification: {
    unitProofs: readonly [string, ...string[]]
  }
}

export interface LiveProtocolControl {
  consumer: string
  purpose: 'authorization-recovery'
  reason: string
}
