/**
 * Format a date/ISO string as a relative time label.
 * - < 1 hour: "Xm ago"
 * - < 24 hours: "Xh ago"
 * - < 7 days: "Xd ago"
 * - Otherwise: formatted date (e.g. "May 3, 2026")
 */
export function formatRelativeTime(dateInput: string | Date): string {
  const date = typeof dateInput === 'string' ? new Date(dateInput) : dateInput
  const now = new Date()
  const diffMs = now.getTime() - date.getTime()

  if (diffMs < 0) return 'just now'

  const seconds = Math.floor(diffMs / 1000)
  const minutes = Math.floor(seconds / 60)
  const hours = Math.floor(minutes / 60)
  const days = Math.floor(hours / 24)

  if (minutes < 1) return 'just now'
  if (hours < 1) return `${minutes}m ago`
  if (days < 1) return `${hours}h ago`
  if (days < 7) return `${days}d ago`

  return date.toLocaleDateString(undefined, {
    month: 'short',
    day: 'numeric',
    year: date.getFullYear() !== now.getFullYear() ? 'numeric' : undefined,
  })
}

/**
 * Determine which time-period group an activity entry belongs to.
 * Returns: "Today" | "Yesterday" | "This Week" | "This Month" | "Older"
 */
export function getTimePeriodLabel(dateInput: string | Date): TimePeriod {
  const date = typeof dateInput === 'string' ? new Date(dateInput) : dateInput
  const now = new Date()

  const today = new Date(now.getFullYear(), now.getMonth(), now.getDate())
  const yesterday = new Date(today.getTime() - 86400000)
  const weekStart = new Date(today.getTime() - today.getDay() * 86400000)
  const monthStart = new Date(now.getFullYear(), now.getMonth(), 1)

  const entryDay = new Date(date.getFullYear(), date.getMonth(), date.getDate())

  if (entryDay.getTime() >= today.getTime()) return 'Today'
  if (entryDay.getTime() >= yesterday.getTime()) return 'Yesterday'
  if (entryDay.getTime() >= weekStart.getTime()) return 'This Week'
  if (entryDay.getTime() >= monthStart.getTime()) return 'This Month'
  return 'Older'
}

/** Ordered period labels for consistent group ordering. */
export const PERIOD_ORDER = ['Today', 'Yesterday', 'This Week', 'This Month', 'Older'] as const
export type TimePeriod = (typeof PERIOD_ORDER)[number]
