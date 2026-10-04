import { describe, it, expect } from 'vitest'
import { formatDuration } from '../format-duration'

describe('formatDuration', () => {
  it('formats 0 seconds', () => {
    expect(formatDuration(0)).toBe('00:00')
  })

  it('formats seconds less than a minute', () => {
    expect(formatDuration(59)).toBe('00:59')
  })

  it('formats 1 minute 1 second', () => {
    expect(formatDuration(61)).toBe('01:01')
  })

  it('formats 1 hour', () => {
    expect(formatDuration(3600)).toBe('01:00:00')
  })

  it.each([
    [9, '00:09'], [609, '10:09'], [32409, '09:00:09'], [36000, '10:00:00'],
    [3599, '59:59'], [3601, '01:00:01'], [3661, '01:01:01'],
    [7530, '02:05:30'], [86400, '24:00:00'],
    [3599.4, '59:59'], [3599.7, '01:00:00'],
  ])('formats %s seconds as %s', (seconds, expected) => {
    expect(formatDuration(seconds)).toBe(expected)
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
    expect(formatDuration(30)).toBe('00:30')
  })

  it('formats exactly 1 minute', () => {
    expect(formatDuration(60)).toBe('01:00')
  })

  it('rounds fractional seconds', () => {
    expect(formatDuration(90.7)).toBe('01:31')
  })
})
