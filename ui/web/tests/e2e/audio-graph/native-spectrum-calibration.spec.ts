import { writeFile } from 'node:fs/promises'
import { test as audioGraphTest, expect } from './audio-graph-harness'

// Offline rendering never reaches hardware; mute Chromium as an additional guard.
const test = audioGraphTest.extend({
  browser: async ({ playwright }, provide) => {
    const browser = await playwright.chromium.launch({ args: [
      '--mute-audio', '--host-resolver-rules=MAP audio.baander.app 127.0.0.1', '--no-proxy-server',
    ] })
    try { await provide(browser) } finally { await browser.close() }
  },
})

for (const sampleRate of [44100, 48000]) {
  test(`native analyser and shipped FFT/worklet share calibrated levels at ${sampleRate} Hz`, async ({ page, origin }, testInfo) => {
    const errors: string[] = []
    page.on('pageerror', error => errors.push(error.message))
    await page.goto(origin + '/real/')
    const result = await page.evaluate(async sampleRate => {
      const size = 2048
      const bytes = await (await fetch('/dsp/fft2048.wasm')).arrayBuffer()
      const { instance } = await WebAssembly.instantiate(bytes, {})
      const api = instance.exports as {
        memory: WebAssembly.Memory
        _initialize?: () => void
        wasm_malloc: (size: number) => number
        wasm_free: (pointer: number) => void
        process_display_spectrum: (input: number, magnitude: number, waveform: number) => void
      }
      api._initialize?.()
      const input = api.wasm_malloc(size * 4)
      const magnitude = api.wasm_malloc(size / 2)
      const waveform = api.wasm_malloc(size)
      const cases = [
        ...[3, 43, 1021].flatMap(bin => [1, 0.1, 0.01, 0.001].map(amplitude => ({ bin, amplitude }))),
        ...[3.4, 43.4, 1020.6].map(bin => ({ bin, amplitude: 0.01 })),
        { bin: 43, amplitude: 0 },
      ]
      const readings = []
      try {
        for (const { bin, amplitude } of cases) {
          const context = new OfflineAudioContext(1, size * 4, sampleRate)
          const buffer = context.createBuffer(1, size * 4, sampleRate)
          const signal = buffer.getChannelData(0)
          for (let i = 0; i < signal.length; i++) signal[i] = amplitude * Math.sin(2 * Math.PI * bin * i / size)
          const source = context.createBufferSource()
          source.buffer = buffer
          const analyser = context.createAnalyser()
          analyser.fftSize = size
          analyser.minDecibels = -100
          analyser.maxDecibels = 0
          analyser.smoothingTimeConstant = 0
          source.connect(analyser)
          analyser.connect(context.destination)
          source.start()
          const suspended = context.suspend(size * 2 / sampleRate)
          const rendering = context.startRendering()
          await suspended
          const nativeBytes = new Uint8Array(size / 2)
          const nativeDb = new Float32Array(size / 2)
          analyser.getByteFrequencyData(nativeBytes)
          analyser.getFloatFrequencyData(nativeDb)
          new Float32Array(api.memory.buffer, input, size).set(signal.subarray(size, size * 2))
          api.process_display_spectrum(input, magnitude, waveform)
          const wasm = new Uint8Array(api.memory.buffer, magnitude, size / 2)
          const nativePeak = nativeBytes.indexOf(Math.max(...nativeBytes))
          const wasmPeak = wasm.indexOf(Math.max(...wasm))
          readings.push({
            bin, amplitude, nativePeak, wasmPeak,
            nativePeakDb: nativeDb[nativePeak],
            wasmPeakDb: -100 + wasm[wasmPeak] * 100 / 255,
            nativeSilence: nativeBytes.every(value => value === 0),
            wasmSilence: wasm.every(value => value === 0),
          })
          await context.resume()
          await rendering
        }

        // The actual shipped worklet consumes the same known signal in its audio thread.
        const context = new OfflineAudioContext(1, size * 4, sampleRate)
        await context.audioWorklet.addModule('/real/wasm-spectrum.js')
        const worklet = new AudioWorkletNode(context, 'wasm-spectrum')
        const spectra: { sampleRate: number; fftSize: number; minDecibels: number; maxDecibels: number; peak: number; db: number }[] = []
        await new Promise<void>((resolve, reject) => {
          worklet.port.onmessage = event => {
            if (event.data.type === 'ready') resolve()
            if (event.data.type === 'error') reject(new Error('FFT worklet initialization failed'))
            if (event.data.type === 'spectrum') {
              const data = event.data.frequencyData as Uint8Array
              const peak = data.indexOf(Math.max(...data))
              spectra.push({ sampleRate: event.data.sampleRate, fftSize: event.data.fftSize,
                minDecibels: event.data.minDecibels, maxDecibels: event.data.maxDecibels,
                peak, db: -100 + data[peak] * 100 / 255 })
            }
          }
          worklet.port.postMessage({ type: 'wasm', bytes })
        })
        const buffer = context.createBuffer(1, size * 4, sampleRate)
        const signal = buffer.getChannelData(0)
        for (let i = 0; i < signal.length; i++) signal[i] = 0.01 * Math.sin(2 * Math.PI * 43 * i / size)
        const source = context.createBufferSource()
        source.buffer = buffer
        source.connect(worklet)
        worklet.connect(context.destination)
        source.start()
        await context.startRendering()
        await new Promise(resolve => setTimeout(resolve, 0))
        worklet.port.close()
        return { readings, spectra, sampleRate }
      } finally {
        for (const pointer of [input, magnitude, waveform]) api.wasm_free(pointer)
      }
    }, sampleRate)

    const evidencePath = testInfo.outputPath(`calibration-${sampleRate}.json`)
    await writeFile(evidencePath, JSON.stringify({ browser: page.context().browser()?.version(), ...result }, null, 2))
    await testInfo.attach(`calibration-${sampleRate}.json`, { path: evidencePath, contentType: 'application/json' })
    for (const reading of result.readings) {
      if (reading.amplitude === 0) {
        expect(reading.nativeSilence).toBe(true)
        expect(reading.wasmSilence).toBe(true)
        continue
      }
      expect(reading.nativePeak).toBe(reading.wasmPeak)
      expect(Math.abs(reading.nativePeak - reading.bin)).toBeLessThan(0.6)
      // One byte spans 100/255 dB. Float FFT rounding adds less than 0.008 dB.
      expect(Math.abs(reading.nativePeakDb - reading.wasmPeakDb)).toBeLessThan(0.4)
      if (Number.isInteger(reading.bin)) {
        expect(reading.nativePeakDb).toBeCloseTo(20 * Math.log10(reading.amplitude * 0.21), 2)
      }
    }
    expect(result.spectra.length).toBeGreaterThan(0)
    for (const spectrum of result.spectra) {
      expect(spectrum).toMatchObject({ sampleRate, fftSize: 2048, minDecibels: -100, maxDecibels: 0, peak: 43 })
      expect(Math.abs(spectrum.db - 20 * Math.log10(0.01 * 0.21))).toBeLessThan(0.4)
    }
    expect(errors).toEqual([])
  })
}
