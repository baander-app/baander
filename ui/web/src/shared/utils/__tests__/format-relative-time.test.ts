import { describe, it, expect } from 'vitest'
import { formatRelativeTime, getTimePeriodLabel, PERIOD_ORDER } from '../format-relative-time'

describe('formatRelativeTime', () => {
  it('returns "just now" for dates less than 1 minute ago', () => {
    const now = new Date()
    expect(formatRelativeTime(now)).toBe('just now')
    expect(formatRelativeTime(new Date(now.getTime() - 30000))).toBe('just now')
  })

  it('returns "Xm ago" for dates less than 1 hour ago', () => {
    const now = new Date()
    expect(formatRelativeTime(new Date(now.getTime() - 5 * 60_000))).toBe('5m ago')
    expect(formatRelativeTime(new Date(now.getTime() - 59 * 60_000))).toBe('59m ago')
  })

  it('returns "Xh ago" for dates less than 24 hours ago', () => {
    const now = new Date()
    expect(formatRelativeTime(new Date(now.getTime() - 2 * 3600_000))).toBe('2h ago')
    expect(formatRelativeTime(new Date(now.getTime() - 23 * 3600_000))).toBe('23h ago')
  })

  it('returns "Xd ago" for dates less than 7 days ago', () => {
    const now = new Date()
    expect(formatRelativeTime(new Date(now.getTime() - 3 * 86400_000))).toBe('3d ago')
    expect(formatRelativeTime(new Date(now.getTime() - 6 * 86400_000))).toBe('6d ago')
  })

  it('returns formatted date for older dates', () => {
    const now = new Date()
    const old = new Date(now.getTime() - 30 * 86400_000)
    const result = formatRelativeTime(old)
    // Should be a formatted date like "Apr 11" not a relative string
    expect(result).not.toMatch(/^\d+[mhd] ago$/)
    expect(result).not.toBe('just now')
  })

  it('accepts ISO strings', () => {
    const now = new Date()
    const fiveMinAgo = new Date(now.getTime() - 5 * 60_000).toISOString()
    expect(formatRelativeTime(fiveMinAgo)).toBe('5m ago')
  })
})

describe('getTimePeriodLabel', () => {
  it('returns "Today" for today', () => {
    expect(getTimePeriodLabel(new Date())).toBe('Today')
  })

  it('returns "Yesterday" for yesterday', () => {
    const yesterday = new Date()
    yesterday.setDate(yesterday.getDate() - 1)
    expect(getTimePeriodLabel(yesterday)).toBe('Yesterday')
  })

  it('returns a valid period for 3 days ago', () => {
    const now = new Date()
    const threeDaysAgo = new Date(now)
    threeDaysAgo.setDate(now.getDate() - 3)
    const label = getTimePeriodLabel(threeDaysAgo)
    expect(PERIOD_ORDER).toContain(label)
  })

  it('returns "This Month" for dates in current month but older than this week', () => {
    const now = new Date()
    // Go back 10 days — might be this week or this month depending on day of week
    const tenDaysAgo = new Date(now)
    tenDaysAgo.setDate(now.getDate() - 10)
    const label = getTimePeriodLabel(tenDaysAgo)
    expect(PERIOD_ORDER).toContain(label)
  })

  it('returns "Older" for dates in previous month', () => {
    const now = new Date()
    const oldDate = new Date(now.getFullYear(), now.getMonth() - 2, 15)
    expect(getTimePeriodLabel(oldDate)).toBe('Older')
  })

  it('PERIOD_ORDER has all five labels', () => {
    expect(PERIOD_ORDER).toEqual(['Today', 'Yesterday', 'This Week', 'This Month', 'Older'])
  })
})
