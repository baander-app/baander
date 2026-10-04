import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { AudioProcessor } from '@/features/player/services/audio-processor'

const dsp = vi.hoisted(() => ({
  getLoudness: vi.fn(),
  getDynamics: vi.fn(),
  getSpectralFeatures: vi.fn(),
  initSpectral: vi.fn(),
}))

vi.mock('@/features/player/services/wasm-loader', () => ({
  getLoudness: dsp.getLoudness,
  getDynamics: dsp.getDynamics,
  getSpectralFeatures: dsp.getSpectralFeatures,
  getWasmUrl: (file: string) => `/dsp/${file}`,
  getAudioWorkletUrl: (file: string) => `/audio-worklets/${file}`,
}))

function deferred<T>() {
  let resolve!: (value: T | PromiseLike<T>) => void
  let reject!: (reason: unknown) => void
  const promise = new Promise<T>((resolvePromise, rejectPromise) => {
    resolve = resolvePromise
    reject = rejectPromise
  })
  return { promise, resolve, reject }
}

async function settle() {
  for (let i = 0; i < 20; i++) await Promise.resolve()
}

class MockParam {
  value = 1
  setTargetAtTime = vi.fn()
  setValueAtTime = vi.fn()
  cancelScheduledValues = vi.fn()
  cancelAndHoldAtTime = vi.fn()
  linearRampToValueAtTime = vi.fn()
}

class MockNode {
  readonly outputs = new Set<MockNode>()
  gain = new MockParam()
  frequency = new MockParam()
  Q = new MockParam()
  threshold = new MockParam()
  knee = new MockParam()
  ratio = new MockParam()
  attack = new MockParam()
  release = new MockParam()
  connect = vi.fn((target: MockNode) => { this.outputs.add(target); return target })
  disconnect = vi.fn((target?: MockNode) => {
    if (target) this.outputs.delete(target)
    else this.outputs.clear()
  })
  getByteFrequencyData = vi.fn()
  getByteTimeDomainData = vi.fn()
}

class MockContext {
  state = 'running'
  currentTime = 10
  sampleRate = 48000
  destination = new MockNode()
  audioWorklet = { addModule: vi.fn().mockResolvedValue(undefined) }
  resume = vi.fn(async () => { this.state = 'running' })
  createGain = () => new MockNode()
  createAnalyser = () => new MockNode()
  createDynamicsCompressor = () => new MockNode()
  createBiquadFilter = () => new MockNode()
  createChannelSplitter = () => new MockNode()
  createChannelMerger = () => new MockNode()
  createMediaElementSource = vi.fn(() => new MockNode())
  close = vi.fn(async () => { this.state = 'closed' })
}

type MessageCallback = (event: MessageEvent) => void

class MockWorklet extends MockNode {
  static instances: MockWorklet[] = []
  readonly name: string
  port = {
    onmessage: null as MessageCallback | null,
    postMessage: vi.fn(),
    close: vi.fn(),
  }
  constructor(_context: unknown, name: string) {
    super()
    this.name = name
    MockWorklet.instances.push(this)
  }
  emit(data: unknown) { this.port.onmessage?.({ data } as MessageEvent) }
}

const workerConstructor = vi.fn(function () { throw new Error('Unexpected background analysis worker') })

type ProcessorInspection = {
  audioContext: MockContext
  analyzerNode: MockNode
  analysisSink: MockNode
  wasmSpectrumReady: boolean
}

let processor: AudioProcessor
let graph: ProcessorInspection
let elementA: HTMLAudioElement
let elementB: HTMLAudioElement
let fetchMock: ReturnType<typeof vi.fn>

beforeEach(() => {
  vi.clearAllMocks()
  dsp.getSpectralFeatures.mockResolvedValue({ init: dsp.initSpectral })
  vi.useFakeTimers()
  MockWorklet.instances = []
  vi.stubGlobal('AudioContext', MockContext)
  vi.stubGlobal('AudioWorkletNode', MockWorklet)
  vi.stubGlobal('Worker', workerConstructor)
  fetchMock = vi.fn().mockResolvedValue({ ok: true, arrayBuffer: async () => new ArrayBuffer(0) })
  vi.stubGlobal('fetch', fetchMock)
  processor = new AudioProcessor()
  graph = processor as unknown as ProcessorInspection
  elementA = document.createElement('audio')
  elementB = document.createElement('audio')
})

afterEach(() => {
  processor.destroy()
  vi.useRealTimers()
  vi.unstubAllGlobals()
})

const connect = () => processor.connectDualAudioElements(elementA, elementB)
const worklet = (name: string) => MockWorklet.instances.filter((node) => node.name === name).at(-1)!

describe('AudioProcessor asynchronous analysis lifecycle', () => {

  it('initializes spectral analysis without allocating unused main-thread meters', async () => {
    await settle()
    expect(dsp.getSpectralFeatures).toHaveBeenCalledOnce()
    expect(dsp.initSpectral).toHaveBeenCalledExactlyOnceWith(2048, 48000)
    expect(dsp.getLoudness).not.toHaveBeenCalled()
    expect(dsp.getDynamics).not.toHaveBeenCalled()
    await connect()
    await settle()
    worklet('magic-soup-processor').emit({ type: 'request-dsp-init' })
    await settle()
    expect(worklet('magic-soup-processor').port.postMessage).toHaveBeenCalledWith(expect.objectContaining({ type: 'init-dsp' }))
  })

  it('does not initialize spectral state when loading finishes after destruction', async () => {
    processor.destroy()
    await settle()
    const load = deferred<{ init: ReturnType<typeof vi.fn> }>()
    dsp.getSpectralFeatures.mockReturnValueOnce(load.promise)
    processor = new AudioProcessor()
    processor.destroy()
    const init = vi.fn()
    load.resolve({ init })
    await settle()
    expect(init).not.toHaveBeenCalled()
  })

  it('releases its owned spectral instance on destruction', async () => {
    await settle()
    const inspection = processor as unknown as { spectralAPI: unknown; dspReady: boolean }
    expect(inspection.spectralAPI).not.toBeNull()
    expect(inspection.dspReady).toBe(true)
    processor.destroy()
    expect(inspection.spectralAPI).toBeNull()
    expect(inspection.dspReady).toBe(false)
    processor.destroy()
    expect(inspection.spectralAPI).toBeNull()
  })

  it('continues worklet metering when main-thread spectral loading fails', async () => {
    processor.destroy()
    await settle()
    dsp.getSpectralFeatures.mockRejectedValueOnce(new Error('spectral load failed'))
    const warning = vi.spyOn(console, 'warn').mockImplementation(() => {})
    try {
      processor = new AudioProcessor()
      await connect()
      await settle()
      expect(warning).toHaveBeenCalledWith('[AudioProcessor] Failed to initialize DSP modules:', expect.any(Error))
      worklet('magic-soup-processor').emit({ type: 'request-dsp-init' })
      await settle()
      expect(worklet('magic-soup-processor').port.postMessage).toHaveBeenCalledWith(expect.objectContaining({ type: 'init-dsp' }))
    } finally {
      warning.mockRestore()
    }
  })

  it('rejects malformed worklet buffers before copying data or computing features', async () => {
    processor.setPlayingState(true)
    await connect()
    await settle()
    const inspection = processor as unknown as {
      frequencyData: Uint8Array
      timeDomainData: Uint8Array
      computeSpectralFeatures(data: Uint8Array): void
    }
    const compute = vi.spyOn(inspection, 'computeSpectralFeatures').mockImplementation(() => {})
    const frequencyBefore = inspection.frequencyData.slice()
    const timeBefore = inspection.timeDomainData.slice()
    for (const [frequencyData, timeDomainData] of [
      [new Uint8Array(128).fill(200), new Uint8Array(2048)],
      [new Uint8Array(1024).fill(200), new Uint8Array(256)],
      [new Float32Array(1024), new Uint8Array(2048)],
    ]) worklet('wasm-spectrum').emit({ type: 'spectrum', frequencyData, timeDomainData })
    expect(compute).not.toHaveBeenCalled()
    expect(inspection.frequencyData).toEqual(frequencyBefore)
    expect(inspection.timeDomainData).toEqual(timeBefore)
    worklet('wasm-spectrum').emit({
      type: 'spectrum', frequencyData: new Uint8Array(1024).fill(200), timeDomainData: new Uint8Array(2048).fill(220),
    })
    expect(compute).toHaveBeenCalledOnce()
    expect(inspection.frequencyData[0]).toBe(200)
    expect(inspection.timeDomainData[0]).toBe(220)
  })

  it.each(['disconnect', 'destroy'] as const)('ignores addModule completion after %s', async (stop) => {
    const module = deferred<void>()
    graph.audioContext.audioWorklet.addModule.mockReturnValueOnce(module.promise)
    await connect()
    expect(graph.audioContext.audioWorklet.addModule).toHaveBeenCalledTimes(1)

    processor[stop]()
    module.resolve()
    await settle()

    expect(MockWorklet.instances).toHaveLength(0)
    expect(graph.audioContext.audioWorklet.addModule).toHaveBeenCalledTimes(1)
    expect(vi.getTimerCount()).toBe(0)
  })

  it.each(['disconnect', 'destroy'] as const)('ignores resume completion after %s', async (stop) => {
    const resumed = deferred<void>()
    graph.audioContext.state = 'suspended'
    graph.audioContext.resume.mockReturnValueOnce(resumed.promise)
    await connect()
    expect(graph.audioContext.resume).toHaveBeenCalledTimes(1)

    processor[stop]()
    resumed.resolve()
    await settle()

    expect(graph.audioContext.audioWorklet.addModule).not.toHaveBeenCalled()
    expect(MockWorklet.instances).toHaveLength(0)
    expect(vi.getTimerCount()).toBe(0)
  })

  it('ignores obsolete addModule rejection after reconnection', async () => {
    const module = deferred<void>()
    graph.audioContext.audioWorklet.addModule.mockReturnValueOnce(module.promise)
    await connect()
    processor.disconnect()
    await connect()
    await settle()
    const currentNodes = [...MockWorklet.instances]

    module.reject(new Error('obsolete module failure'))
    await settle()

    expect(MockWorklet.instances).toEqual(currentNodes)
    expect(vi.getTimerCount()).toBe(0)
  })

  it('does not create a spectrum node when its module completes after disconnect', async () => {
    const module = deferred<void>()
    graph.audioContext.audioWorklet.addModule.mockImplementation(async (url: string) => {
      if (url.endsWith('wasm-spectrum.js')) await module.promise
    })
    await connect()
    await settle()
    expect(worklet('magic-soup-processor')).toBeDefined()
    processor.disconnect()
    module.resolve()
    await settle()

    expect(MockWorklet.instances.filter((node) => node.name === 'wasm-spectrum')).toHaveLength(0)
    expect(vi.getTimerCount()).toBe(0)
  })

  it('keeps old FFT fetch completion away from a replacement spectrum node', async () => {
    const fftBytes = deferred<ArrayBuffer>()
    fetchMock.mockImplementation(async (url: string) => ({
      ok: true,
      arrayBuffer: () => url.endsWith('fft2048.wasm') ? fftBytes.promise : Promise.resolve(new ArrayBuffer(0)),
    }))
    await connect()
    await settle()
    const oldSpectrum = worklet('wasm-spectrum')
    processor.disconnect()
    fetchMock.mockResolvedValue({ ok: true, arrayBuffer: async () => new ArrayBuffer(1) })
    await connect()
    await settle()
    const replacement = worklet('wasm-spectrum')
    expect(replacement).not.toBe(oldSpectrum)
    const replacementPosts = replacement.port.postMessage.mock.calls.length

    fftBytes.resolve(new ArrayBuffer(8))
    await settle()

    expect(oldSpectrum.port.postMessage).not.toHaveBeenCalled()
    expect(replacement.port.postMessage).toHaveBeenCalledTimes(replacementPosts)
  })

  it('keeps old DSP fetch completion away from a replacement metering node', async () => {
    await connect()
    await settle()
    const oldMeter = worklet('magic-soup-processor')
    const dspBytes = deferred<ArrayBuffer>()
    fetchMock.mockResolvedValue({ ok: true, arrayBuffer: () => dspBytes.promise })
    oldMeter.emit({ type: 'request-dsp-init' })
    await settle()
    processor.disconnect()
    fetchMock.mockResolvedValue({ ok: true, arrayBuffer: async () => new ArrayBuffer(1) })
    await connect()
    await settle()
    const replacement = worklet('magic-soup-processor')

    dspBytes.resolve(new ArrayBuffer(8))
    await settle()

    expect(oldMeter.port.postMessage).not.toHaveBeenCalled()
    expect(replacement.port.postMessage).not.toHaveBeenCalledWith(expect.objectContaining({ type: 'init-dsp' }))
  })

  it('connects the spectrum analysis branch when ready arrives asynchronously', async () => {
    await connect()
    await settle()
    const spectrum = worklet('wasm-spectrum')
    expect(graph.analyzerNode.outputs.has(spectrum)).toBe(false)

    spectrum.emit({ type: 'ready' })

    expect(graph.analyzerNode.outputs.has(spectrum)).toBe(true)
    expect(spectrum.outputs.has(graph.analysisSink)).toBe(true)
    expect(graph.wasmSpectrumReady).toBe(true)
  })

  it('closes analysis ports and ignores a queued ready message after disconnect', async () => {
    await connect()
    await settle()
    const nodes = [...MockWorklet.instances]
    const spectrum = worklet('wasm-spectrum')
    const queuedReady = spectrum.port.onmessage!
    processor.disconnect()

    for (const node of nodes) {
      expect(node.port.close).toHaveBeenCalledTimes(1)
      expect(node.port.onmessage).toBeNull()
    }
    queuedReady({ data: { type: 'ready' } } as MessageEvent)
    expect(graph.wasmSpectrumReady).toBe(false)
    expect(graph.analyzerNode.outputs.has(spectrum)).toBe(false)
  })

  it('does not send a failed HTTP response body to the spectrum worklet', async () => {
    const failedBody = vi.fn(async () => new ArrayBuffer(8))
    fetchMock.mockImplementation(async (url: string) => url.endsWith('fft2048.wasm')
      ? { ok: false, status: 404, arrayBuffer: failedBody }
      : { ok: true, arrayBuffer: async () => new ArrayBuffer(0) })

    await connect()
    await settle()

    expect(failedBody).not.toHaveBeenCalled()
    expect(worklet('wasm-spectrum').port.postMessage).not.toHaveBeenCalled()
    expect(graph.wasmSpectrumReady).toBe(false)
  })

  it('does not construct an unused worker or fetch its ignored WASM payload', async () => {
    await settle()
    expect(workerConstructor).not.toHaveBeenCalled()
    expect(fetchMock).not.toHaveBeenCalled()
  })

  it('clears stale measurements and never fabricates passive levels or starts polling', async () => {
    await connect()
    await settle()
    processor.setPlayingState(true)
    const state = processor as unknown as { frequencyData: Uint8Array; timeDomainData: Uint8Array }
    state.frequencyData.fill(200)
    state.timeDomainData.fill(220)
    Object.assign(processor, { peakFrequency: 1000, spectralCentroid: 2000, spectralRolloff: 4000,
      spectralFlux: 1, spectralFlatness: 0.5, lufsBuffer: [-12] })
    await processor.initializePassiveMode()
    expect(vi.getTimerCount()).toBe(0)
    for (let i = 0; i < 3; i++) {
      vi.advanceTimersByTime(1000)
      const data = processor.getAnalysisData()
      expect(data.frequencyData.every(value => value === 0)).toBe(true)
      expect(data.timeDomainData.every(value => value === 128)).toBe(true)
      expect(data).toMatchObject({ leftChannel: 0, rightChannel: 0, rms: 0, lufs: -60,
        peakFrequency: 0, spectralCentroid: 0, spectralRolloff: 0, spectralFlux: 0, spectralFlatness: 0 })
    }
    processor.setPlayingState(false)
    processor.setPlayingState(true)
    expect(vi.getTimerCount()).toBe(0)
    expect(workerConstructor).not.toHaveBeenCalled()
    expect(processor.getSystemInfo()).toMatchObject({ connected: true, passive: true })
  })

  it('keeps the active analyser fallback polling when worklets are unavailable', async () => {
    graph.audioContext.audioWorklet.addModule.mockRejectedValue(new Error('Worklet unavailable'))
    await connect()
    await settle()
    processor.setPlayingState(true)
    expect(vi.getTimerCount()).toBe(1)
    vi.advanceTimersByTime(80)
    expect(graph.analyzerNode.getByteFrequencyData).toHaveBeenCalled()
    expect(graph.analyzerNode.getByteTimeDomainData).toHaveBeenCalled()
    processor.disconnect()
    expect(vi.getTimerCount()).toBe(0)
  })

  it.each(['pause', 'disconnect', 'destroy', 'passive'] as const)('ignores a queued spectrum after %s', async (stop) => {
    await connect()
    await settle()
    processor.setPlayingState(true)
    const spectrum = worklet('wasm-spectrum')
    const queuedResult = spectrum.port.onmessage!
    if (stop === 'pause') processor.setPlayingState(false)
    else if (stop === 'passive') await processor.initializePassiveMode()
    else processor[stop]()
    queuedResult({ data: { type: 'spectrum', frequencyData: new Uint8Array(1024).fill(200),
      timeDomainData: new Uint8Array(2048).fill(220) } } as MessageEvent)
    const result = processor.getAnalysisData()
    expect(result.frequencyData.every(value => value === 0)).toBe(true)
    expect(result.timeDomainData.every(value => value === 128)).toBe(true)
    expect(result.rms).toBe(0)
    expect(vi.getTimerCount()).toBe(0)
  })

})


describe('programme measurement ownership', () => {
  it('clears readings and rejects queued reports from older programmes', async () => {
    await connect()
    await settle()
    const meter = worklet('magic-soup-processor')
    const readings = processor as unknown as { lufsBuffer: number[] }
    meter.emit({ type: 'analysis', programmeGeneration: 0, lufs: -12 })
    expect(readings.lufsBuffer).toEqual([-12])
    processor.resetProgramme()
    expect(readings.lufsBuffer).toEqual([])
    expect(meter.port.postMessage).toHaveBeenCalledWith({ type: 'reset-programme', programmeGeneration: 1 })
    meter.emit({ type: 'analysis', programmeGeneration: 0, lufs: -1 })
    meter.emit({ type: 'analysis', lufs: -1 })
    meter.emit({ type: 'analysis', programmeGeneration: 1, lufs: NaN })
    expect(readings.lufsBuffer).toEqual([])
    meter.emit({ type: 'analysis', programmeGeneration: 1, lufs: -24 })
    expect(readings.lufsBuffer).toEqual([-24])
    processor.resetProgramme()
    meter.emit({ type: 'analysis', programmeGeneration: 1, lufs: -2 })
    meter.emit({ type: 'analysis', programmeGeneration: 2, lufs: -30 })
    expect(readings.lufsBuffer).toEqual([-30])
  })

  it('carries the latest boundary through delayed worklet creation and reconnection', async () => {
    const module = deferred<void>()
    graph.audioContext.audioWorklet.addModule.mockReturnValue(module.promise)
    await connect()
    processor.resetProgramme()
    processor.resetProgramme()
    module.resolve()
    await settle()
    expect(worklet('magic-soup-processor').port.postMessage).toHaveBeenCalledWith({ type: 'reset-programme', programmeGeneration: 2 })
    processor.disconnect()
    await connect()
    await settle()
    expect(worklet('magic-soup-processor').port.postMessage).toHaveBeenCalledWith({ type: 'reset-programme', programmeGeneration: 2 })
  })

  it('ignores reset calls after destruction', async () => {
    await connect()
    await settle()
    const meter = worklet('magic-soup-processor')
    processor.destroy()
    processor.resetProgramme()
    expect(meter.port.postMessage).not.toHaveBeenCalledWith(expect.objectContaining({ type: 'reset-programme' }))
  })
})
