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

    // Sample the center pixel as representative color
    const centerIndex = (Math.floor(height / 2) * width + Math.floor(width / 2)) * 4
    const r = Math.round(pixels[centerIndex])
    const g = Math.round(pixels[centerIndex + 1])
    const b = Math.round(pixels[centerIndex + 2])

    return { r, g, b }
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
