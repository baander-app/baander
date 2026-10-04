import { AudioProcessor } from '../../../src/features/player/services/audio-processor'

export interface RenderOptions {
  chain: string[]
  rebuilds?: string[][]
  width?: number
  mode?: 'normal' | 'mid' | 'side'
  crossfeed?: number
  normalization?: boolean
  eqBoost?: boolean
  reference?: boolean
  signal?: 'left' | 'right' | 'mono' | 'antiphase'
  monoSource?: boolean
}

const sampleRate = 48_000
const length = sampleRate

function input(context: OfflineAudioContext, eqBoost: boolean, signal: RenderOptions['signal'], monoSource: boolean) {
  const buffer = context.createBuffer(monoSource ? 1 : 2, length, sampleRate)
  for (let i = 0; i < length; i++) {
    const value = 0.2 * Math.sin(2 * Math.PI * (eqBoost ? 31.5 : 440) * i / sampleRate)
    buffer.getChannelData(0)[i] = signal === 'right' ? 0 : value
    if (!monoSource) buffer.getChannelData(1)[i] = signal === 'right' || signal === 'mono' || eqBoost ? value : signal === 'antiphase' ? -value : 0
  }
  return buffer
}

async function render(options: RenderOptions) {
  const context = new OfflineAudioContext(2, length, sampleRate)
  const buffer = input(context, !!options.eqBoost, options.signal, !!options.monoSource)
  const source = context.createBufferSource()
  source.buffer = buffer
  if (options.reference) {
    let tail: AudioNode = source
    for (const module of options.chain) {
      if (module === 'eq') {
        for (const [index, frequency] of [31.5, 63, 125, 250, 500, 1000, 2000, 4000, 8000, 16000].entries()) {
          const filter = context.createBiquadFilter()
          filter.type = index === 0 ? 'lowshelf' : index === 9 ? 'highshelf' : 'peaking'
          if (index > 0 && index < 9) filter.Q.value = 0.7
          filter.frequency.value = frequency
          if (index === 0) filter.gain.setTargetAtTime(18, 0, 0.1)
          tail.connect(filter)
          tail = filter
        }
      } else if (module === 'compressor') {
        const compressor = context.createDynamicsCompressor()
        compressor.threshold.value = -24
        compressor.knee.value = 30
        compressor.ratio.value = 3
        compressor.attack.value = 0.003
        compressor.release.value = 0.25
        tail.connect(compressor)
        tail = compressor
      }
    }
    tail.connect(context.destination)
  } else {
    // The processor creates native browser nodes in this real offline context.
    // Only its external WASM/worker dependencies are replaced by the bundler.
    const original = window.AudioContext
    window.AudioContext = function () { return context } as unknown as typeof AudioContext
    let processor: AudioProcessor
    try { processor = new AudioProcessor() } finally { window.AudioContext = original }
    const nodes = processor as unknown as { sourceGainA: GainNode; analyzerNode: AnalyserNode }
    source.connect(nodes.sourceGainA)
    nodes.sourceGainA.connect(nodes.analyzerNode)
    if (options.width !== undefined) processor.setStereoWidth(options.width, options.mode)
    if (options.crossfeed !== undefined) processor.setCrossfeed(options.crossfeed)
    if (options.eqBoost) processor.updateEQBands([{ gain: 18 }])
    if (options.normalization) processor.applyVolumeNormalization(-20, -14)
    for (const chain of [options.chain, ...(options.rebuilds ?? [])]) {
      processor.rebuildChain(chain)
      await new Promise(resolve => setTimeout(resolve, 60))
    }
  }
  source.start()
  const rendered = await context.startRendering()
  const start = Math.floor(sampleRate * 0.8)
  const left = Array.from(rendered.getChannelData(0).slice(start))
  const right = Array.from(rendered.getChannelData(1).slice(start))
  const original = Array.from(buffer.getChannelData(options.signal === 'right' ? 1 : 0).slice(start))
  const energy = original.reduce((sum, value) => sum + value * value, 0)
  const projection = (channel: number[]) => channel.reduce((sum, value, i) => sum + value * original[i], 0) / energy
  return { left, right, leftGain: projection(left), rightGain: projection(right) }
}

export type LifecycleCase = 'ready-cleanup' | 'disconnect' | 'destroy'

export interface LifecycleResult {
  nativeNodes: boolean
  ready: boolean
  spectrumConnected: boolean
  sinkConnected: boolean
  closedPorts: number
  handlersCleared: boolean
  lateNodes: boolean
  moduleCalls: number
  fallbackActive: boolean
}

async function lifecycle(scenario: LifecycleCase): Promise<LifecycleResult> {
  // Nodes and ports are native; only WASM bytes and DSP APIs are fixture data.
  const processor = new AudioProcessor()
  const graph = processor as unknown as {
    audioContext: AudioContext
    analyzerNode: AnalyserNode
    analysisSink: GainNode
    audioWorkletNode: AudioWorkletNode | null
    wasmSpectrumNode: AudioWorkletNode | null
    wasmSpectrumReady: boolean
    analysisInterval: number | null
  }
  const context = graph.audioContext
  const waitFor = async (condition: () => boolean) => {
    const deadline = performance.now() + 3000
    while (!condition()) {
      if (performance.now() > deadline) throw new Error('Native worklet lifecycle timed out')
      await new Promise(resolve => setTimeout(resolve, 5))
    }
  }
  try {
    await context.resume()
    if (scenario !== 'ready-cleanup') {
      let release!: () => void
      let moduleLoaded!: () => void
      const gate = new Promise<void>(resolve => { release = resolve })
      const loaded = new Promise<void>(resolve => { moduleLoaded = resolve })
      const addModule = context.audioWorklet.addModule.bind(context.audioWorklet)
      let moduleCalls = 0
      context.audioWorklet.addModule = async (...args) => {
        moduleCalls++
        await addModule(...args)
        moduleLoaded()
        await gate
      }
      await processor.connectDualAudioElements(new Audio(), new Audio())
      await loaded
      processor[scenario]()
      release()
      await new Promise(resolve => setTimeout(resolve, 100))
      return {
        nativeNodes: true, ready: graph.wasmSpectrumReady,
        spectrumConnected: false, sinkConnected: false, closedPorts: 0,
        handlersCleared: true,
        lateNodes: !!(graph.audioWorkletNode || graph.wasmSpectrumNode),
        moduleCalls, fallbackActive: graph.analysisInterval !== null,
      }
    }

    const connections: AudioNode[] = []
    const connect = graph.analyzerNode.connect.bind(graph.analyzerNode)
    graph.analyzerNode.connect = ((destination: AudioNode) => {
      connections.push(destination)
      return connect(destination)
    }) as typeof graph.analyzerNode.connect
    await processor.connectDualAudioElements(new Audio(), new Audio())
    await waitFor(() => graph.wasmSpectrumReady)
    const meter = graph.audioWorkletNode!
    const spectrum = graph.wasmSpectrumNode!
    const nodes = [meter, spectrum]
    // The ready callback makes both native connections in the same message turn.
    // Native disconnect(destination) throws if that particular edge is absent.
    let sinkConnected = false
    try { spectrum.disconnect(graph.analysisSink); sinkConnected = true } catch { /* missing edge */ }
    if (sinkConnected) spectrum.connect(graph.analysisSink)
    let closedPorts = 0
    for (const node of nodes) {
      const close = node.port.close.bind(node.port)
      node.port.close = () => { closedPorts++; close() }
    }
    const ready = graph.wasmSpectrumReady
    const spectrumConnected = connections.includes(spectrum)
    processor.disconnect()
    return {
      nativeNodes: nodes.every(node => node instanceof AudioWorkletNode && node.port instanceof MessagePort),
      ready, spectrumConnected, sinkConnected, closedPorts,
      handlersCleared: nodes.every(node => node.port.onmessage === null),
      lateNodes: !!(graph.audioWorkletNode || graph.wasmSpectrumNode),
      moduleCalls: 2, fallbackActive: graph.analysisInterval !== null,
    }
  } finally {
    processor.destroy()
    if (context.state !== 'closed') await context.close()
  }
}

Object.assign(window, { audioGraphFixture: { render, lifecycle } })
