import { describe, it, expect } from 'vitest'
import { formatDurationHuman, formatUptime, formatBytes } from '../format-human'

describe('formatDurationHuman', () => {
  it('formats zero seconds', () => {
    expect(formatDurationHuman(0)).toBe('0s')
  })

  it('formats seconds less than a minute', () => {
    expect(formatDurationHuman(45)).toBe('45s')
    expect(formatDurationHuman(1)).toBe('1s')
  })

  it('rounds fractional seconds', () => {
    expect(formatDurationHuman(0.7)).toBe('1s')
  })

  it('formats exact minutes', () => {
    expect(formatDurationHuman(60)).toBe('1m')
    expect(formatDurationHuman(120)).toBe('2m')
  })

  it('formats hours and minutes', () => {
    expect(formatDurationHuman(3600)).toBe('1h')
    expect(formatDurationHuman(3900)).toBe('1h 5m')
  })

  it('formats days and hours', () => {
    expect(formatDurationHuman(86400)).toBe('1d')
    expect(formatDurationHuman(93600)).toBe('1d 2h')
  })

  it('returns 0s for negative', () => {
    expect(formatDurationHuman(-5)).toBe('0s')
  })

  it('returns 0s for NaN', () => {
    expect(formatDurationHuman(NaN)).toBe('0s')
  })

  it('returns 0s for Infinity', () => {
    expect(formatDurationHuman(Infinity)).toBe('0s')
  })
})

describe('formatUptime', () => {
  it('formats days, hours, minutes', () => {
    expect(formatUptime(86400 + 3600 + 60)).toBe('1d 1h 1m')
  })

  it('formats hours and minutes', () => {
    expect(formatUptime(3600 + 180)).toBe('1h 3m')
  })

  it('formats minutes only', () => {
    expect(formatUptime(120)).toBe('2m')
  })

  it('formats zero', () => {
    expect(formatUptime(0)).toBe('0m')
  })

  it('returns 0m for negative', () => {
    expect(formatUptime(-10)).toBe('0m')
  })

  it('returns 0m for NaN', () => {
    expect(formatUptime(NaN)).toBe('0m')
  })
})

describe('formatBytes', () => {
  it('formats zero bytes', () => {
    expect(formatBytes(0)).toBe('0 B')
  })

  it('formats bytes', () => {
    expect(formatBytes(512)).toBe('512.0 B')
  })

  it('formats kilobytes', () => {
    expect(formatBytes(1024)).toBe('1.0 KB')
  })

  it('formats megabytes', () => {
    expect(formatBytes(1048576)).toBe('1.0 MB')
  })

  it('formats gigabytes', () => {
    expect(formatBytes(1073741824)).toBe('1.0 GB')
  })

  it('formats terabytes', () => {
    expect(formatBytes(1099511627776)).toBe('1.0 TB')
  })

  it('formats fractional values', () => {
    expect(formatBytes(1536)).toBe('1.5 KB')
  })

  it('returns 0 B for negative', () => {
    expect(formatBytes(-100)).toBe('0 B')
  })

  it('returns 0 B for NaN', () => {
    expect(formatBytes(NaN)).toBe('0 B')
  })
})
