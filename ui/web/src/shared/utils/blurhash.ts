import { decode } from 'blurhash'

/** Decode a blurhash string and extract the dominant color as hex. */
export function decodeBlurhash(
  blurhash: string,
  width: number = 4,
  height: number = 3,
): { r: number; g: number; b: number } | null {
  try {
    if (!blurhash || blurhash.length < 6) return null
    const pixels = decode(blurhash, width, height)

    let selected = { r: pixels[0], g: pixels[1], b: pixels[2] }
    let highestSaturation = -1
    for (let i = 0; i < pixels.length; i += 4) {
      const r = pixels[i]
      const g = pixels[i + 1]
      const b = pixels[i + 2]
      const max = Math.max(r, g, b) / 255
      const min = Math.min(r, g, b) / 255
      const saturation = max === min ? 0 : (max - min) / (1 - Math.abs(max + min - 1))
      if (saturation > highestSaturation) {
        highestSaturation = saturation
        selected = { r, g, b }
      }
    }
    return selected
  } catch {
    return null
  }
}

/** Convert RGB to hex string. */
export function rgbToHex(r: number, g: number, b: number): string {
  return `#${[r, g, b].map((c) => c.toString(16).padStart(2, '0')).join('')}`
}

/** Extract dominant color from blurhash. Returns null if blurhash is invalid or color is too dark/light/unsaturated. */
export function extractDominantColor(blurhash: string | null | undefined): string | null {
  if (!blurhash) return null

  const rgb = decodeBlurhash(blurhash)
  if (!rgb) return null

  // Convert to HSL to check saturation and lightness
  const { r, g, b } = rgb
  const rn = r / 255
  const gn = g / 255
  const bn = b / 255

  const max = Math.max(rn, gn, bn)
  const min = Math.min(rn, gn, bn)
  const l = (max + min) / 2

  let s = 0
  if (max !== min) {
    const d = max - min
    s = l > 0.5 ? d / (2 - max - min) : d / (max + min)
  }

  // Reject colors that are too dark, too light, or too unsaturated
  if (l < 0.1 || l > 0.9 || s < 0.1) return null

  return rgbToHex(r, g, b)
}


function channels(hex: string): number[] {
  return [1, 3, 5].map((offset) => parseInt(hex.slice(offset, offset + 2), 16))
}

/** WCAG contrast for opaque sRGB colors. */
export function colorContrast(first: string, second: string): number {
  const luminance = (hex: string) => {
    const rgb = channels(hex).map((value) => {
      const channel = value / 255
      return channel <= 0.04045 ? channel / 12.92 : ((channel + 0.055) / 1.055) ** 2.4
    })
    return rgb[0] * 0.2126 + rgb[1] * 0.7152 + rgb[2] * 0.0722
  }
  const a = luminance(first)
  const b = luminance(second)
  return (Math.max(a, b) + 0.05) / (Math.min(a, b) + 0.05)
}

/** Preserve the artwork hue while finding readable text on the supplied surfaces. */
export function readableArtworkAccent(color: string, backgrounds: string[], fallback: string): string {
  if (backgrounds.every((background) => colorContrast(color, background) >= 4.5)) return color
  const rgb = channels(color)
  for (let step = 1; step <= 100; step++) {
    for (const target of [255, 0]) {
      const mixed = rgb.map((value) => Math.round(value + (target - value) * step / 100))
      const candidate = rgbToHex(mixed[0], mixed[1], mixed[2])
      if (backgrounds.every((background) => colorContrast(candidate, background) >= 4.5)) return candidate
    }
  }
  return fallback
}
