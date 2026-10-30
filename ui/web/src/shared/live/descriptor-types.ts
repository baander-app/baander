import type { LiveFamily } from './protocol'

export interface LiveQueryDescriptor {
  family: LiveFamily
  matches(queryKey: readonly unknown[]): boolean
  sampleIntervalMs?: 5000 | 10000 | 30000
  deploymentFixed?: boolean
  refreshPolicy?: 'stored' | 'sampled' | 'demand' | 'deployment'
}

/** Match key segments rather than serialized keys, preserving filter and page variants. */
export function keyStartsWith(key: readonly unknown[], ...prefix: readonly unknown[]): boolean {
  return prefix.every((segment, index) => key[index] === segment)
}

export function firstKeyMatches(key: readonly unknown[], pattern: RegExp): boolean {
  return typeof key[0] === 'string' && pattern.test(key[0])
}
