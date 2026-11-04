import { afterEach, describe, expect, it, vi } from 'vitest'
import { loadPrimaryFonts } from '../load-primary-fonts'

function fontSet(load: (font: string, text?: string) => Promise<FontFace[]>): FontFaceSet {
  return { load } as unknown as FontFaceSet
}

describe('loadPrimaryFonts', () => {
  afterEach(() => {
    vi.useRealTimers()
  })

  it('loads Geist and Geist Mono for Latin, Latin Extended and Vietnamese text', async () => {
    const load = vi.fn(async () => [] as FontFace[])

    await loadPrimaryFonts(fontSet(load))

    expect(load).toHaveBeenCalledWith('400 1em "Geist Variable"', 'Aa Ąą Ơơ')
    expect(load).toHaveBeenCalledWith('400 1em "Geist Mono Variable"', 'Aa Ąą Ơơ')
  })

  it('resolves when a font fails to load', async () => {
    const load = vi.fn(async () => {
      throw new Error('network')
    })

    await expect(loadPrimaryFonts(fontSet(load))).resolves.toBeUndefined()
  })

  it('stops waiting after three seconds when a font never arrives', async () => {
    vi.useFakeTimers()
    const load = vi.fn(() => new Promise<FontFace[]>(() => {}))
    let settled = false

    const pending = loadPrimaryFonts(fontSet(load)).then(() => {
      settled = true
    })
    await vi.advanceTimersByTimeAsync(2999)
    expect(settled).toBe(false)
    await vi.advanceTimersByTimeAsync(1)
    await pending

    expect(settled).toBe(true)
  })

  it('resolves at once where the browser has no font loading API', async () => {
    await expect(loadPrimaryFonts(undefined)).resolves.toBeUndefined()
  })
})
