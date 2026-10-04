import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { AudioProcessor } from '@/features/player/services/audio-processor'

vi.mock('@/features/player/services/wasm-loader', () => ({
  getLoudness: async () => ({ init: vi.fn() }),
  getDynamics: async () => ({ init: vi.fn() }),
  getSpectralFeatures: async () => ({ init: vi.fn() }),
  getWasmUrl: (file: string) => `/dsp/${file}`,
  getAudioWorkletUrl: (file: string) => `/audio-worklets/${file}`,
}))

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
  connect = vi.fn((target: MockNode) => {
    this.outputs.add(target)
    return target
  })
  disconnect = vi.fn((target?: MockNode) => {
    if (target) this.outputs.delete(target)
    else this.outputs.clear()
  })
}

class MockContext {
  state = 'running'
  currentTime = 10
  sampleRate = 48000
  destination = new MockNode()
  audioWorklet = { addModule: vi.fn().mockResolvedValue(undefined) }
  createGain = () => new MockNode()
  createAnalyser = () => new MockNode()
  createDynamicsCompressor = () => new MockNode()
  createBiquadFilter = () => new MockNode()
  createChannelSplitter = () => new MockNode()
  createChannelMerger = () => new MockNode()
  close = vi.fn(async () => { this.state = 'closed' })
}

class MockWorklet extends MockNode {
  port = { onmessage: null, postMessage: vi.fn(), close: vi.fn() }
}

type GraphInspection = {
  filters: MockNode[]
  compressorNode: MockNode
  loudnessGain: MockNode
  masterGainNode: MockNode
  sourceNodeA: MockNode | null
  setupVolumeNormalization: () => Promise<void>
  teardownWorklet: () => void
}

let processor: AudioProcessor
let graph: GraphInspection

beforeEach(() => {
  vi.useFakeTimers()
  vi.stubGlobal('AudioContext', MockContext)
  vi.stubGlobal('AudioWorkletNode', MockWorklet)
  vi.stubGlobal('Worker', class {
    postMessage = vi.fn()
    terminate = vi.fn()
  })
  vi.stubGlobal('fetch', vi.fn().mockResolvedValue({ ok: true, arrayBuffer: async () => new ArrayBuffer(0) }))
  processor = new AudioProcessor()
  graph = processor as unknown as GraphInspection
})

afterEach(() => {
  processor.destroy()
  vi.useRealTimers()
  vi.unstubAllGlobals()
})

describe('AudioProcessor rebuild lifecycle', () => {
  it('anchors both fade ramps at the current audio time', () => {
    processor.crossfadeToInactive(4)
    const outgoing = processor.getSourceGainA().gain
    const incoming = processor.getSourceGainB().gain
    expect(outgoing.cancelScheduledValues).toHaveBeenCalledWith(10)
    expect(incoming.cancelScheduledValues).toHaveBeenCalledWith(10)
    expect(outgoing.setValueAtTime).toHaveBeenCalledWith(1, 10)
    expect(incoming.setValueAtTime).toHaveBeenCalledWith(0, 10)
    expect(outgoing.linearRampToValueAtTime).toHaveBeenCalledWith(0, 14)
    expect(incoming.linearRampToValueAtTime).toHaveBeenCalledWith(1, 14)
    expect(processor.getActiveSource()).toBe('B')
  })

  it('cancels pending fades when an instant swap chooses a new active source', () => {
    processor.crossfadeToInactive(4)
    vi.clearAllMocks()
    processor.instantSwap()
    expect(processor.getActiveSource()).toBe('A')
    expect(processor.getSourceGainA().gain.cancelScheduledValues).toHaveBeenCalledWith(10)
    expect(processor.getSourceGainB().gain.cancelScheduledValues).toHaveBeenCalledWith(10)
    expect(processor.getSourceGainA().gain.setValueAtTime).toHaveBeenCalledWith(1, 10)
    expect(processor.getSourceGainB().gain.setValueAtTime).toHaveBeenCalledWith(0, 10)
  })

  it('finishes an interrupted fade on the adopted source without another swap', () => {
    processor.crossfadeToInactive(4)
    vi.clearAllMocks()
    processor.cancelCrossfade()
    expect(processor.getActiveSource()).toBe('B')
    expect(processor.getSourceGainA().gain.setValueAtTime).toHaveBeenCalledWith(0, 10)
    expect(processor.getSourceGainB().gain.setValueAtTime).toHaveBeenCalledWith(1, 10)
    expect(processor.getSourceGainA().gain.cancelScheduledValues).toHaveBeenCalledWith(10)
    expect(processor.getSourceGainB().gain.cancelScheduledValues).toHaveBeenCalledWith(10)
  })

  it.each([-1, NaN, Infinity])('rejects invalid fade duration %s without switching source', (duration) => {
    expect(() => processor.crossfadeToInactive(duration)).toThrow(RangeError)
    expect(processor.getActiveSource()).toBe('A')
    expect(processor.getSourceGainA().gain.linearRampToValueAtTime).not.toHaveBeenCalled()
    expect(processor.getSourceGainB().gain.linearRampToValueAtTime).not.toHaveBeenCalled()
  })

  it.each([
    ['eq', 'compressor', 'eq'],
    ['unknown-module'],
  ])('rejects invalid chain %j before changing the live graph', async (...chain) => {
    const disconnectsBefore = graph.filters[0].disconnect.mock.calls.length
    expect(() => processor.rebuildChain(chain)).toThrow()
    await vi.advanceTimersByTimeAsync(100)
    expect(graph.filters[0].disconnect.mock.calls.length).toBe(disconnectsBefore)
    expect(graph.filters.at(-1)!.outputs.has(graph.compressorNode)).toBe(true)
  })

  it('coalesces overlapping requests and wires only the latest chain', async () => {
    const disconnectsBefore = graph.filters[0].disconnect.mock.calls.length
    processor.rebuildChain(['eq', 'compressor', 'loudness', 'masterGain'])
    await vi.advanceTimersByTimeAsync(10)
    processor.rebuildChain(['eq', 'loudness', 'compressor', 'masterGain'])
    await vi.advanceTimersByTimeAsync(100)

    expect(graph.filters[0].disconnect.mock.calls.length - disconnectsBefore).toBe(1)
    expect(graph.filters.at(-1)!.outputs.has(graph.loudnessGain)).toBe(true)
    expect(graph.loudnessGain.outputs.has(graph.compressorNode)).toBe(true)
    expect(graph.compressorNode.outputs.has(graph.masterGainNode)).toBe(true)
  })

  it('cancels pending graph mutation when destroyed during a fade', async () => {
    processor.rebuildChain(['eq', 'loudness', 'compressor', 'masterGain'])
    processor.destroy()
    const disconnectsAfterDestroy = graph.filters[0].disconnect.mock.calls.length

    await vi.advanceTimersByTimeAsync(100)

    expect(graph.filters[0].disconnect.mock.calls.length).toBe(disconnectsAfterDestroy)
  })

  it('keeps selected processing order when analysis worklets attach and detach', async () => {
    processor.rebuildChain(['eq', 'compressor', 'loudness', 'masterGain'])
    await vi.advanceTimersByTimeAsync(100)
    // Isolate worklet setup from media-source creation and spectrum initialization.
    graph.sourceNodeA = new MockNode()

    await graph.setupVolumeNormalization()
    expect(graph.compressorNode.outputs.has(graph.loudnessGain)).toBe(true)
    expect(graph.loudnessGain.outputs.has(graph.masterGainNode)).toBe(true)

    graph.teardownWorklet()
    expect(graph.compressorNode.outputs.has(graph.loudnessGain)).toBe(true)
    expect(graph.loudnessGain.outputs.has(graph.masterGainNode)).toBe(true)
    expect(graph.compressorNode.outputs.has(graph.masterGainNode)).toBe(false)
  })
})
