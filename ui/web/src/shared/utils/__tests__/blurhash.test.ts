import { describe, it, expect, vi } from 'vitest'
import { decode, encode } from 'blurhash'
import { decodeBlurhash, rgbToHex, extractDominantColor, readableArtworkAccent, colorContrast } from '../blurhash'
import { resolveTheme, VALID_MOODS, VALID_ACCENTS } from '@/shared/theme'

vi.mock('blurhash', async (importOriginal) => {
  const original = await importOriginal<typeof import('blurhash')>()
  return { ...original, decode: vi.fn(original.decode) }
})

function solidHash(r: number, g: number, b: number): string {
  return encode(new Uint8ClampedArray([r, g, b, 255]), 1, 1, 1, 1)
}

describe('artwork color extraction', () => {
  it('samples the most saturated pixel rather than the center', () => {
    const pixels = new Uint8ClampedArray(4 * 3 * 4).fill(128)
    pixels.set([210, 30, 60, 255], 0)
    vi.mocked(decode).mockReturnValueOnce(pixels)
    expect(decodeBlurhash('LEHV6nWB2yk8pyo0adR*.7kCMdnj')).toEqual({ r: 210, g: 30, b: 60 })
  })

  it('rejects missing, malformed, neutral, very dark and very bright artwork', () => {
    for (const value of [null, undefined, '', 'x', solidHash(128, 128, 128), solidHash(20, 0, 0), solidHash(255, 245, 245)]) {
      expect(extractDominantColor(value)).toBeNull()
    }
  })

  it('retains saturated usable artwork identity', () => {
    expect(extractDominantColor(solidHash(210, 30, 60))).toBe('#d21e3c')
    expect(rgbToHex(1, 2, 3)).toBe('#010203')
  })
})

describe('artwork foreground contrast', () => {
  it('matches known WCAG reference ratios', () => {
    expect(colorContrast('#000000', '#ffffff')).toBe(21)
    expect(colorContrast('#767676', '#767676')).toBe(1)
    expect(colorContrast('#767676', '#ffffff')).toBeCloseTo(4.5422, 4)
  })
  for (const mood of VALID_MOODS) {
    for (const accent of VALID_ACCENTS) {
      it(`keeps artwork foreground readable in ${mood}/${accent}`, () => {
        const { colors } = resolveTheme(mood, accent)
        const surfaces = [colors.background, colors.card, colors.highlight]
        for (const artwork of ['#ffff00', '#000080', '#d21e3c', '#767676']) {
          const foreground = readableArtworkAccent(artwork, surfaces, colors.accentText)
          for (const surface of surfaces) expect(colorContrast(foreground, surface)).toBeGreaterThanOrEqual(4.5)
        }
      })
    }
  }

  it('keeps an already readable color unchanged', () => {
    expect(readableArtworkAccent('#ffff00', ['#000000'], '#ffffff')).toBe('#ffff00')
  })
})
