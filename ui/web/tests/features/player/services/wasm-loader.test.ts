// @vitest-environment node
import { readFile } from 'node:fs/promises'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import {
  getAudioWorkletUrl,
  getDynamics,
  getLoudness,
  getSpectralFeatures,
  getWasmUrl,
  resetDspCache,
} from '@/features/player/services/wasm-loader'

const shippedDspDirectory = new URL('../../../../../../public/dsp/', import.meta.url)

async function fetchShippedWasm(input: RequestInfo | URL): Promise<Response> {
  const url = String(input)
  if (!/^\/dsp\/(loudness_r128|dynamics_meter|spectral_features)\.wasm$/.test(url)) {
    throw new Error(`Unexpected DSP request: ${url}`)
  }
  const bytes = await readFile(new URL(url.slice('/dsp/'.length), shippedDspDirectory))
  return new Response(new Uint8Array(bytes), {
    headers: { 'Content-Type': 'application/wasm' },
  })
}

const loaders = [
  { name: 'loudness_r128', load: getLoudness },
  { name: 'dynamics_meter', load: getDynamics },
  { name: 'spectral_features', load: getSpectralFeatures },
]

describe('wasm-loader', () => {
  const fetchMock = vi.fn(fetchShippedWasm)

  beforeEach(() => {
    resetDspCache()
    fetchMock.mockReset().mockImplementation(fetchShippedWasm)
    vi.stubGlobal('fetch', fetchMock)
  })

  afterEach(() => {
    resetDspCache()
    vi.restoreAllMocks()
    vi.unstubAllGlobals()
  })

  it('returns the static WASM and audio worklet URLs', () => {
    expect(getWasmUrl('loudness_r128.wasm')).toBe('/dsp/loudness_r128.wasm')
    expect(getWasmUrl('dynamics_meter.wasm')).toBe('/dsp/dynamics_meter.wasm')
    expect(getWasmUrl('fft2048.wasm')).toBe('/dsp/fft2048.wasm')
    expect(getAudioWorkletUrl('wasm-spectrum.js')).toBe('/audio-worklets/wasm-spectrum.js')
    expect(getAudioWorkletUrl('magic-soup-processor.js')).toBe('/audio-worklets/magic-soup-processor.js')
  })

  it.each(loaders)('shares $name compilation but creates private instances for every caller', async ({ name, load }) => {
    const compileSpy = vi.spyOn(WebAssembly, 'compile')
    const [first, second] = await Promise.all([load(), load()])
    const third = await load()

    expect(fetchMock).toHaveBeenCalledTimes(1)
    expect(fetchMock.mock.calls[0][0]).toBe(`/dsp/${name}.wasm`)
    expect(compileSpy).toHaveBeenCalledTimes(1)
    expect(first).not.toBe(second)
    expect(second).not.toBe(third)
    expect(first.memory).toBeInstanceOf(WebAssembly.Memory)
    expect(first.memory).not.toBe(second.memory)
    expect(second.memory).not.toBe(third.memory)
    expect(first.memory.buffer).not.toBe(second.memory.buffer)
  })

  it('keeps spectral configuration and flux history private', async () => {
    const [first, second] = await Promise.all([getSpectralFeatures(), getSpectralFeatures()])
    first.init(8, 48000)
    second.init(16, 32000)
    const firstPtr = first.malloc(4)
    const secondPtr = second.malloc(8)

    try {
      new Uint8Array(first.memory.buffer, firstPtr, 4).set([0, 255, 0, 0])
      new Uint8Array(second.memory.buffer, secondPtr, 8).set([0, 0, 255, 0, 0, 0, 0, 0])
      first.computeFromMag(firstPtr)
      expect(first.getCentroidHz()).toBeCloseTo(6000)
      expect(first.getFlux()).toBe(0)
      second.computeFromMag(secondPtr)
      expect(second.getCentroidHz()).toBeCloseTo(4000)
      expect(second.getFlux()).toBe(0)
      expect(first.getCentroidHz()).toBeCloseTo(6000)

      new Uint8Array(first.memory.buffer, firstPtr, 4).set([0, 0, 255, 0])
      first.computeFromMag(firstPtr)
      expect(first.getFlux()).toBeCloseTo(1)
      second.computeFromMag(secondPtr)
      expect(second.getFlux()).toBe(0)
      expect(second.getRolloffHz(0.85)).toBeCloseTo(4000)

      first.init(8, 24000)
      expect(second.getCentroidHz()).toBeCloseTo(4000)
      expect(second.getPeakIndex()).toBe(2)
    } finally {
      first.free(firstPtr)
      second.free(secondPtr)
    }
  })

  it('initializes real dynamics modules and isolates meter state and reset', async () => {
    const [first, second] = await Promise.all([getDynamics(), getDynamics()])
    first.init(1, 100, 1000)
    second.init(1, 100, 1000)
    const firstPtr = first.malloc(2 * Float32Array.BYTES_PER_ELEMENT)
    const secondPtr = second.malloc(2 * Float32Array.BYTES_PER_ELEMENT)

    try {
      new Float32Array(first.memory.buffer, firstPtr, 2).set([0.5, 0.25])
      new Float32Array(second.memory.buffer, secondPtr, 2).set([0.125, 0.0625])
      first.process(firstPtr, 1, 2)
      expect(first.rmsL()).toBeCloseTo(0.5)
      expect(first.rmsR()).toBeCloseTo(0.25)
      expect(second.rmsL()).toBe(0)
      second.process(secondPtr, 1, 2)
      expect(second.peakL()).toBeCloseTo(0.125)
      expect(first.peakL()).toBeCloseTo(0.5)
      second.reset()
      expect(second.rmsL()).toBe(0)
      expect(first.rmsL()).toBeCloseTo(0.5)
      expect(first.crestL()).toBeCloseTo(0)
    } finally {
      first.free(firstPtr)
      second.free(secondPtr)
    }
  })

  it('initializes real loudness modules and isolates signal history', async () => {
    const [first, second] = await Promise.all([getLoudness(), getLoudness()])
    first.init(48000, 4)
    second.init(48000, 4)
    const frames = 24000
    const ptr = first.malloc(frames * Float32Array.BYTES_PER_ELEMENT)

    try {
      const signal = new Float32Array(first.memory.buffer, ptr, frames)
      for (let i = 0; i < frames; i++) signal[i] = 0.5 * Math.sin(2 * Math.PI * 1000 * i / 48000)
      first.process(ptr, frames, 1)
      const measured = first.lufsM()
      expect(Number.isFinite(measured)).toBe(true)
      expect(first.truePkDbfs()).toBeGreaterThan(-10)
      expect(second.lufsM()).toBeLessThan(-60)
      second.reset()
      expect(first.lufsM()).toBe(measured)
      expect(Number.isFinite(first.lufsI())).toBe(true)
    } finally {
      first.free(ptr)
    }
  })

  it('clears compiled modules without changing a live instance', async () => {
    const first = await getSpectralFeatures()
    first.init(8, 48000)
    const ptr = first.malloc(4)

    try {
      new Uint8Array(first.memory.buffer, ptr, 4).set([0, 255, 0, 0])
      first.computeFromMag(ptr)
      const memory = first.memory
      resetDspCache()
      const replacement = await getSpectralFeatures()
      expect(fetchMock).toHaveBeenCalledTimes(2)
      expect(replacement.memory).not.toBe(memory)
      expect(first.memory).toBe(memory)
      expect(first.getCentroidHz()).toBeCloseTo(6000)
      first.computeFromMag(ptr)
      expect(first.getFlux()).toBe(0)
    } finally {
      first.free(ptr)
    }
  })

  it.each(loaders)('retries $name after an HTTP failure', async ({ load }) => {
    fetchMock.mockResolvedValueOnce(new Response(null, { status: 503 }))
    const failed = await Promise.allSettled([load(), load()])
    expect(failed.every(result => result.status === 'rejected')).toBe(true)
    expect(fetchMock).toHaveBeenCalledTimes(1)
    await expect(load()).resolves.toHaveProperty('memory')
    expect(fetchMock).toHaveBeenCalledTimes(2)
  })

  it.each(loaders)('retries $name after a rejected fetch', async ({ load }) => {
    fetchMock.mockRejectedValueOnce(new TypeError('DSP fetch failed'))
    await expect(load()).rejects.toThrow('DSP fetch failed')
    await expect(load()).resolves.toHaveProperty('memory')
    expect(fetchMock).toHaveBeenCalledTimes(2)
  })

  it.each(loaders)('retries $name after invalid WASM fails compilation', async ({ load }) => {
    fetchMock.mockResolvedValueOnce(new Response(new Uint8Array([0, 1, 2, 3])))
    await expect(load()).rejects.toBeInstanceOf(WebAssembly.CompileError)
    await expect(load()).resolves.toHaveProperty('memory')
    expect(fetchMock).toHaveBeenCalledTimes(2)
  })

  it('does not let an old failed request evict a replacement after reset', async () => {
    let rejectOldRequest!: (reason: Error) => void
    fetchMock.mockImplementationOnce(() => new Promise<Response>((_, reject) => {
      rejectOldRequest = reject
    }))
    const oldRequest = getSpectralFeatures()
    const oldFailure = expect(oldRequest).rejects.toThrow('old request failed')
    resetDspCache()
    const replacement = await getSpectralFeatures()
    rejectOldRequest(new Error('old request failed'))
    await oldFailure
    const next = await getSpectralFeatures()
    expect(fetchMock).toHaveBeenCalledTimes(2)
    expect(next.memory).not.toBe(replacement.memory)
  })
})
