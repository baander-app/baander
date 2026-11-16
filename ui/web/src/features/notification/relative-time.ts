/** How long ago a moment was, for notification rows: "just now", "5m ago", "3h ago", "2d ago" or a date. */
export function relativeTime(moment: string | number): string {
  const now = Date.now()
  const then = new Date(moment).getTime()
  const diff = Math.max(0, now - then)

  const seconds = Math.floor(diff / 1000)
  if (seconds < 60) return 'just now'

  const minutes = Math.floor(seconds / 60)
  if (minutes < 60) return `${minutes}m ago`

  const hours = Math.floor(minutes / 60)
  if (hours < 24) return `${hours}h ago`

  const days = Math.floor(hours / 24)
  if (days < 30) return `${days}d ago`

  return new Date(moment).toLocaleDateString()
}
