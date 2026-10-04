import { test, expect } from './audio-graph-harness'

test('production WASM loader shares downloads and isolates native DSP instances', async ({ page, origin }) => {
  const downloads: string[] = []
  const requestFailures: string[] = []
  const wasmStatuses: number[] = []
  page.on('requestfailed', request => requestFailures.push(request.url()))
  page.on('response', response => {
    if (new URL(response.url()).pathname.endsWith('.wasm')) wasmStatuses.push(response.status())
  })
  page.on('request', request => {
    if (new URL(request.url()).pathname.endsWith('.wasm')) downloads.push(new URL(request.url()).pathname)
  })
  await page.goto(origin)

  const result = await page.evaluate(async () => {
    const loaderUrl = '/wasm-loader.js'
    const loader = await import(loaderUrl) as typeof import('../../../src/features/player/services/wasm-loader')
    const [spectralA, spectralB, dynamicsA, dynamicsB, loudnessA, loudnessB] = await Promise.all([
      loader.getSpectralFeatures(), loader.getSpectralFeatures(),
      loader.getDynamics(), loader.getDynamics(), loader.getLoudness(), loader.getLoudness(),
    ])
    const privateMemories = [
      spectralA.memory !== spectralB.memory,
      dynamicsA.memory !== dynamicsB.memory,
      loudnessA.memory !== loudnessB.memory,
    ]

    spectralA.init(8, 48000)
    spectralB.init(16, 32000)
    dynamicsA.init(1, 100, 1000)
    dynamicsB.init(1, 100, 1000)
    loudnessA.init(48000, 4)
    loudnessB.init(48000, 4)
    const spectralAPtr = spectralA.malloc(4)
    const spectralBPtr = spectralB.malloc(8)
    const dynamicsPtr = dynamicsA.malloc(8)
    const loudnessFrames = 24000
    const loudnessPtr = loudnessA.malloc(loudnessFrames * Float32Array.BYTES_PER_ELEMENT)
    try {
      new Uint8Array(spectralA.memory.buffer, spectralAPtr, 4).set([0, 255, 0, 0])
      new Uint8Array(spectralB.memory.buffer, spectralBPtr, 8).set([0, 0, 255, 0, 0, 0, 0, 0])
      spectralA.computeFromMag(spectralAPtr)
      spectralB.computeFromMag(spectralBPtr)
      new Uint8Array(spectralA.memory.buffer, spectralAPtr, 4).set([0, 0, 255, 0])
      spectralA.computeFromMag(spectralAPtr)
      spectralB.computeFromMag(spectralBPtr)
      new Float32Array(dynamicsA.memory.buffer, dynamicsPtr, 2).set([0.5, 0.25])
      dynamicsA.process(dynamicsPtr, 1, 2)
      dynamicsB.reset()
      const signal = new Float32Array(loudnessA.memory.buffer, loudnessPtr, loudnessFrames)
      for (let i = 0; i < loudnessFrames; i++) signal[i] = 0.5 * Math.sin(2 * Math.PI * 1000 * i / 48000)
      loudnessA.process(loudnessPtr, loudnessFrames, 1)
      loudnessB.reset()
      return {
        privateMemories,
        spectralCentroids: [spectralA.getCentroidHz(), spectralB.getCentroidHz()],
        spectralFlux: [spectralA.getFlux(), spectralB.getFlux()],
        dynamicsLevels: [dynamicsA.rmsL(), dynamicsA.rmsR(), dynamicsB.rmsL()],
        loudnessLevels: [loudnessA.lufsM(), loudnessB.lufsM()],
      }
    } finally {
      spectralA.free(spectralAPtr)
      spectralB.free(spectralBPtr)
      dynamicsA.free(dynamicsPtr)
      loudnessA.free(loudnessPtr)
    }
  })

  expect(result.privateMemories).toEqual([true, true, true])
  expect(result.spectralCentroids).toEqual([12000, 4000])
  expect(result.spectralFlux).toEqual([1, 0])
  expect(result.dynamicsLevels).toEqual([0.5, 0.25, 0])
  expect(Number.isFinite(result.loudnessLevels[0])).toBe(true)
  expect(result.loudnessLevels[0]).toBeGreaterThan(-15)
  expect(result.loudnessLevels[1]).toBeLessThan(-60)
  expect(requestFailures).toEqual([])
  expect(wasmStatuses).toEqual([200, 200, 200])
  expect(downloads.sort()).toEqual([
    '/dsp/dynamics_meter.wasm', '/dsp/loudness_r128.wasm', '/dsp/spectral_features.wasm',
  ])
})
