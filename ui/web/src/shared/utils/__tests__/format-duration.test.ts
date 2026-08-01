import { describe, it, expect } from 'vitest'
import { formatDuration } from '../format-duration'

describe('formatDuration', () => {
  it('formats 0 seconds', () => {
    expect(formatDuration(0)).toBe('0:00')
  })

  it('formats seconds less than a minute', () => {
    expect(formatDuration(59)).toBe('0:59')
  })

  it('formats 1 minute 1 second', () => {
    expect(formatDuration(61)).toBe('1:01')
  })

  it('formats 1 hour', () => {
    expect(formatDuration(3600)).toBe('60:00')
  })

  it('returns em dash for NaN', () => {
    expect(formatDuration(NaN)).toBe('—')
  })

  it('returns em dash for Infinity', () => {
    expect(formatDuration(Infinity)).toBe('—')
  })

  it('returns em dash for negative', () => {
    expect(formatDuration(-5)).toBe('—')
  })

  it('formats 30 seconds', () => {
    expect(formatDuration(30)).toBe('0:30')
  })

  it('formats exactly 1 minute', () => {
    expect(formatDuration(60)).toBe('1:00')
  })

  it('rounds fractional seconds', () => {
    expect(formatDuration(90.7)).toBe('1:31')
  })
})
