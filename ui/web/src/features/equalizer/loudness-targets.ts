export const LOUDNESS_PRESETS = [
  { name: 'Quiet', value: -23 },
  { name: 'Relaxed', value: -18 },
  { name: 'Balanced', value: -16 },
  { name: 'Louder', value: -14 },
] as const

export function isLoudnessTarget(value: unknown): value is number {
  return typeof value === 'number' && Number.isFinite(value)
}
