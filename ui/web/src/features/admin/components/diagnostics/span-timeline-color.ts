export function spanServiceColor(service: string): string {
  let hash = 0
  for (const character of service) hash = (hash * 31 + character.charCodeAt(0)) | 0
  return `var(--color-chart-${3 + (Math.abs(hash) % 3)})`
}
