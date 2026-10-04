import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { AudioProcessor } from '@/features/player/services/audio-processor'

vi.mock('@/features/player/services/wasm-loader', () => ({
  getLoudness: async () => ({ init: vi.fn() }),
  getDynamics: async () => ({ init: vi.fn() }),
  getSpectralFeatures: async () => ({ init: vi.fn() }),
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

class MockWorker {
  static instances: MockWorker[] = []
  static failInitialPost = false
  onmessage: MessageCallback | null = null
  onerror: (() => void) | null = null
  postMessage = vi.fn(() => {
    if (MockWorker.failInitialPost) throw new Error('Worker initialization failed')
  })
  terminate = vi.fn()
  constructor() { MockWorker.instances.push(this) }
}

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
  vi.useFakeTimers()
  MockWorklet.instances = []
  MockWorker.instances = []
  MockWorker.failInitialPost = false
  vi.stubGlobal('AudioContext', MockContext)
  vi.stubGlobal('AudioWorkletNode', MockWorklet)
  vi.stubGlobal('Worker', MockWorker)
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

  it('does not restart fallback analysis from a queued worker error after destroy', async () => {
    await connect()
    await settle()
    const worker = MockWorker.instances[0]
    const queuedError = worker.onerror!
    processor.destroy()

    queuedError()

    expect(worker.terminate).toHaveBeenCalledTimes(1)
    expect(vi.getTimerCount()).toBe(0)
  })

  it('terminates a constructed worker when its initial postMessage fails', () => {
    processor.destroy()
    MockWorker.failInitialPost = true
    processor = new AudioProcessor()
    const worker = MockWorker.instances.at(-1)!

    expect(worker.terminate).toHaveBeenCalledTimes(1)
    expect(worker.onmessage).toBeNull()
    expect(worker.onerror).toBeNull()
    expect(processor.getSystemInfo().workerReady).toBe(false)

    processor.destroy()
    expect(worker.terminate).toHaveBeenCalledTimes(1)
    expect(vi.getTimerCount()).toBe(0)
  })

  it.each(['pause', 'disconnect', 'destroy'] as const)('ignores a queued worker result after %s', async (stop) => {
    // Exercise the browser fallback without shared memory, where results own the data copies.
    processor.destroy()
    vi.stubGlobal('SharedArrayBuffer', undefined)
    processor = new AudioProcessor()
    await connect()
    processor.setPlayingState(true)
    const worker = MockWorker.instances.at(-1)!
    const queuedResult = worker.onmessage!

    if (stop === 'pause') processor.setPlayingState(false)
    else processor[stop]()
    const stoppedData = processor.getAnalysisData()
    const frequencyBefore = stoppedData.frequencyData[0]
    const timeBefore = stoppedData.timeDomainData[0]
    queuedResult({ data: {
      type: 'analysis-result',
      frequencyData: new Uint8Array([200]),
      timeDomainData: new Uint8Array([220]),
      peakFrequency: 1000,
    } } as MessageEvent)

    const result = processor.getAnalysisData()
    expect(result.frequencyData[0]).toBe(frequencyBefore)
    expect(result.timeDomainData[0]).toBe(timeBefore)
    expect(vi.getTimerCount()).toBe(0)
  })
})
