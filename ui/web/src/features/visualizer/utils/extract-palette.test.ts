import { afterEach, describe, expect, it, vi } from 'vitest'
import { extractPalette } from './extract-palette'

afterEach(() => vi.unstubAllGlobals())

describe('extractPalette', () => {
  it('derives colors directly from authenticated bytes and closes the bitmap', async () => {
    const blob = new Blob(['authenticated image'])
    const close = vi.fn()
    const bitmap = { close }
    const createBitmap = vi.fn().mockResolvedValue(bitmap)
    const drawImage = vi.fn()
    const pixels = new Uint8ClampedArray(64 * 64 * 4)
    for (let offset = 0; offset < pixels.length; offset += 4) {
      pixels[offset] = 255
      pixels[offset + 3] = 255
    }
    vi.stubGlobal('createImageBitmap', createBitmap)
    vi.stubGlobal('OffscreenCanvas', class {
      getContext() { return { drawImage, getImageData: () => ({ data: pixels }) } }
    })
    const fetch = vi.fn()
    vi.stubGlobal('fetch', fetch)
    expect(await extractPalette(blob)).toEqual({
      primary: '#ff0000', secondary: '#ff0000', accent: '#ff0000', background: '#ff0000', isDark: false,
    })
    expect(createBitmap).toHaveBeenCalledWith(blob)
    expect(drawImage).toHaveBeenCalledWith(bitmap, 0, 0, 64, 64)
    expect(fetch).not.toHaveBeenCalled()
    expect(close).toHaveBeenCalledTimes(1)
  })

  it('releases the bitmap when pixel sampling fails', async () => {
    const close = vi.fn()
    vi.stubGlobal('createImageBitmap', vi.fn().mockResolvedValue({ close }))
    vi.stubGlobal('OffscreenCanvas', class {
      getContext() { throw new Error('Canvas unavailable') }
    })
    expect(await extractPalette(new Blob(['image']))).toBeNull()
    expect(close).toHaveBeenCalledTimes(1)
  })
})
