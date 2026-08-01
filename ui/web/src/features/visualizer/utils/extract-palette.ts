import type { PaletteColors } from '../types'

/** RGB color tuple [0-255]. */
type RGB = [number, number, number]

/** Extract a color palette from an image URL using OffscreenCanvas pixel sampling. */
export async function extractPalette(imageUrl: string): Promise<PaletteColors | null> {
  try {
    const response = await fetch(imageUrl, { credentials: 'include' })
    if (!response.ok) return null

    const blob = await response.blob()
    const bitmap = await createImageBitmap(blob)

    // Sample at small resolution for speed
    const sampleSize = 64
    const canvas = new OffscreenCanvas(sampleSize, sampleSize)
    const ctx = canvas.getContext('2d')
    if (!ctx) return null

    ctx.drawImage(bitmap, 0, 0, sampleSize, sampleSize)
    const imageData = ctx.getImageData(0, 0, sampleSize, sampleSize)
    bitmap.close()

    return analyzePixels(imageData.data, sampleSize, sampleSize)
  } catch {
    return null
  }
}

// NOTE: extractPaletteFromBlurhash removed — dead code. Add back when blurhash fallback is wired in a future phase.

/** Analyze pixel data to produce a color palette. */
function analyzePixels(data: Uint8Array | Uint8ClampedArray, width: number, height: number): PaletteColors | null {
  if (data.length < width * height * 4) return null

  // Grid sampling: divide image into regions and compute average color per region
  const gridCols = 4
  const gridRows = 4
  const cellW = Math.floor(width / gridCols)
  const cellH = Math.floor(height / gridRows)

  const regionColors: RGB[] = []

  for (let row = 0; row < gridRows; row++) {
    for (let col = 0; col < gridCols; col++) {
      let rSum = 0, gSum = 0, bSum = 0, count = 0

      for (let y = row * cellH; y < (row + 1) * cellH && y < height; y++) {
        for (let x = col * cellW; x < (col + 1) * cellW && x < width; x++) {
          const i = (y * width + x) * 4
          rSum += data[i]!
          gSum += data[i + 1]!
          bSum += data[i + 2]!
          count++
        }
      }

      if (count > 0) {
        regionColors.push([rSum / count, gSum / count, bSum / count])
      }
    }
  }

  if (regionColors.length === 0) return null

  // Sort by saturation (most saturated first)
  const sorted = regionColors.sort((a, b) => saturation(b) - saturation(a))

  // Primary: most saturated color
  const primary = sorted[0]!

  // Secondary: second most saturated that's sufficiently different from primary
  const secondary = sorted.find((c) => colorDistance(c, primary) > 80) ?? sorted[Math.min(1, sorted.length - 1)]!

  // Accent: brightest saturated color
  const brightSorted = [...regionColors].sort((a, b) => lightness(b) - lightness(a))
  const accent = brightSorted[0]!

  // Background: darkest color
  const darkSorted = [...regionColors].sort((a, b) => lightness(a) - lightness(b))
  const background = darkSorted[0]!

  const avgLightness = regionColors.reduce((s, c) => s + lightness(c), 0) / regionColors.length

  return {
    primary: rgbToHex(primary),
    secondary: rgbToHex(secondary),
    accent: rgbToHex(accent),
    background: rgbToHex(background),
    isDark: avgLightness < 0.5,
  }
}

function saturation(rgb: RGB): number {
  const [r, g, b] = rgb.map((v) => v / 255) as [number, number, number]
  const max = Math.max(r, g, b)
  const min = Math.min(r, g, b)
  if (max === 0) return 0
  return (max - min) / max
}

function lightness(rgb: RGB): number {
  const [r, g, b] = rgb.map((v) => v / 255) as [number, number, number]
  return (Math.max(r, g, b) + Math.min(r, g, b)) / 2
}

function colorDistance(a: RGB, b: RGB): number {
  return Math.sqrt((a[0] - b[0]) ** 2 + (a[1] - b[1]) ** 2 + (a[2] - b[2]) ** 2)
}

function rgbToHex(rgb: RGB): string {
  return '#' + rgb.map((v) => Math.round(Math.min(255, Math.max(0, v))).toString(16).padStart(2, '0')).join('')
}
