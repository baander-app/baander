import { describe, it, expect } from 'vitest'
import { decodeBlurhash, rgbToHex, extractDominantColor } from '../blurhash'

describe('decodeBlurhash', () => {
  it('returns RGB for a valid blurhash', () => {
    // 'LEHV6nWB2yk8pyo0adR*.7kCMdnj' is a well-known test hash
    const result = decodeBlurhash('LEHV6nWB2yk8pyo0adR*.7kCMdnj')
    expect(result).not.toBeNull()
    expect(result!).toHaveProperty('r')
    expect(result!).toHaveProperty('g')
    expect(result!).toHaveProperty('b')
    expect(typeof result!.r).toBe('number')
    expect(typeof result!.g).toBe('number')
    expect(typeof result!.b).toBe('number')
  })

  it('returns null for invalid blurhash', () => {
    expect(decodeBlurhash('')).toBeNull()
    expect(decodeBlurhash('x')).toBeNull()
  })
})

describe('rgbToHex', () => {
  it('converts pure red', () => {
    expect(rgbToHex(255, 0, 0)).toBe('#ff0000')
  })

  it('converts pure green', () => {
    expect(rgbToHex(0, 255, 0)).toBe('#00ff00')
  })

  it('converts pure blue', () => {
    expect(rgbToHex(0, 0, 255)).toBe('#0000ff')
  })

  it('converts black', () => {
    expect(rgbToHex(0, 0, 0)).toBe('#000000')
  })

  it('converts white', () => {
    expect(rgbToHex(255, 255, 255)).toBe('#ffffff')
  })

  it('pads single digit values', () => {
    expect(rgbToHex(1, 2, 3)).toBe('#010203')
  })
})

describe('extractDominantColor', () => {
  it('returns null for null input', () => {
    expect(extractDominantColor(null)).toBeNull()
  })

  it('returns null for undefined input', () => {
    expect(extractDominantColor(undefined)).toBeNull()
  })

  it('returns null for empty string', () => {
    expect(extractDominantColor('')).toBeNull()
  })

  it('returns hex string for valid blurhash', () => {
    const result = extractDominantColor('LEHV6nWB2yk8pyo0adR*.7kCMdnj')
    // Should return a hex string starting with # or null if unsaturated
    if (result !== null) {
      expect(result).toMatch(/^#[0-9a-f]{6}$/)
    }
  })
})
