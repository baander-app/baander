/** Renders a counter the server may not report; a missing figure shows as an em dash. */
export function formatCount(value: number | null | undefined): string {
  return value == null ? '—' : String(value)
}

/** Renders a pair such as "used / peak", with an optional unit after the pair. */
export function formatPair(
  first: number | null | undefined,
  second: number | null | undefined,
  unit?: string,
): string {
  const pair = `${formatCount(first)} / ${formatCount(second)}`
  return unit ? `${pair} ${unit}` : pair
}

/** Converts a span duration in microseconds to milliseconds with one decimal. */
export function formatDurationMs(durationUs: number): string {
  return `${(durationUs / 1000).toFixed(1)} ms`
}
