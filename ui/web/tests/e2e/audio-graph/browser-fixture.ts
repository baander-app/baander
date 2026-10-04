import { AudioProcessor } from '../../../src/features/player/services/audio-processor'

export interface RenderOptions {
  chain: string[]
  rebuilds?: string[][]
  width?: number
  mode?: 'normal' | 'mid' | 'side'
  crossfeed?: number
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

export interface PassiveAnalysisResult {
  activeFallback: boolean
  activeSignal: boolean
  passiveFallback: boolean[]
  passive: boolean
  playing: boolean
  elapsed: number
  readings: {
    frequencySilent: boolean
    timeDomainSilent: boolean
    leftChannel: number
    rightChannel: number
    lufs: number
    peakFrequency: number
    spectralCentroid: number
    spectralRolloff: number
    spectralFlux: number
    spectralFlatness: number
    rms: number
  }[]
}

async function passiveAnalysis(): Promise<PassiveAnalysisResult> {
  // Native nodes and clocks exercise fallback scheduling. The harness replaces
  // external WASM APIs; rejecting worklet loading selects the real fallback.
  const processor = new AudioProcessor()
  const graph = processor as unknown as {
    audioContext: AudioContext
    sourceGainA: GainNode
    analysisInterval: number | null
  }
  const context = graph.audioContext
  const oscillator = context.createOscillator()
  const gain = context.createGain()
  gain.gain.value = 0.2
  oscillator.connect(gain).connect(graph.sourceGainA)
  context.audioWorklet.addModule = async () => { throw new Error('Fixture selects analyser fallback') }
  try {
    await context.resume()
    await processor.connectDualAudioElements(new Audio(), new Audio())
    oscillator.start()
    processor.setPlayingState(true)
    await new Promise(resolve => setTimeout(resolve, 200))
    const activeFallback = graph.analysisInterval !== null
    const activeSignal = processor.getAnalysisData().frequencyData.some(value => value > 0)
    await processor.initializePassiveMode()
    const started = context.currentTime
    const readings: PassiveAnalysisResult['readings'] = []
    const passiveFallback: boolean[] = []
    for (let sample = 0; sample < 3; sample++) {
      if (sample > 0) await new Promise(resolve => setTimeout(resolve, 150))
      const { frequencyData, timeDomainData, ...metrics } = processor.getAnalysisData()
      readings.push({
        frequencySilent: frequencyData.every(value => value === 0),
        timeDomainSilent: timeDomainData.every(value => value === 128),
        ...metrics,
      })
      passiveFallback.push(graph.analysisInterval !== null)
    }
    const { passive, playing } = processor.getSystemInfo()
    return { activeFallback, activeSignal, passiveFallback, passive, playing,
      elapsed: context.currentTime - started, readings }
  } finally {
    oscillator.stop()
    oscillator.disconnect()
    gain.disconnect()
    processor.destroy()
    if (context.state !== 'closed') await context.close()
  }
}

export interface StereoAnalysisOptions {
  mode: 'fallback' | 'worklet'
  signal: 'right' | 'antiphase' | 'asymmetric' | 'mono'
}

export interface StereoAnalysisResult {
  phase: { samples: number[]; correlation: number | null } | null
  before: { leftChannel: number; rightChannel: number; rms: number; lufs: number }
  after: { leftChannel: number; rightChannel: number; rms: number; lufs: number }
  workletReports: number
  wasmLoudnessReported: boolean
  beforeWorkletFrame: StereoAnalysisResult['before'] | null
  afterWorkletFrame: StereoAnalysisResult['after'] | null
}

async function stereoAnalysis(options: StereoAnalysisOptions): Promise<StereoAnalysisResult> {
  const processor = new AudioProcessor()
  const graph = processor as unknown as {
    audioContext: AudioContext
    sourceGainA: GainNode
    audioWorkletNode: AudioWorkletNode | null
  }
  const context = graph.audioContext
  const source = context.createBufferSource()
  const buffer = context.createBuffer(options.signal === 'mono' ? 1 : 2, context.sampleRate, context.sampleRate)
  // An integer number of periods in every 2048-frame analyser window makes
  // the expected RMS independent of the browser's sampling time.
  const frequency = context.sampleRate / 64
  for (let i = 0; i < buffer.length; i++) {
    const value = Math.sin(2 * Math.PI * frequency * i / context.sampleRate)
    buffer.getChannelData(0)[i] = options.signal === 'right' ? 0 : 0.2 * value
    if (buffer.numberOfChannels > 1) buffer.getChannelData(1)[i] = (options.signal === 'antiphase' ? -0.2 : options.signal === 'asymmetric' ? 0.1 : 0.2) * value
  }
  source.buffer = buffer
  source.loop = true
  let workletReports = 0
  let wasmLoudnessReported = false
  let latestWorkletFrame: StereoAnalysisResult['before'] | null = null
  const waitFor = async (condition: () => boolean) => {
    const deadline = performance.now() + 5000
    while (!condition()) {
      if (performance.now() > deadline) throw new Error('Stereo analysis did not become ready')
      await new Promise(resolve => setTimeout(resolve, 10))
    }
  }
  if (options.mode === 'fallback') {
    context.audioWorklet.addModule = async () => { throw new Error('Fixture selects stereo analyser fallback') }
  }
  try {
    await context.resume()
    await processor.connectDualAudioElements(new Audio(), new Audio())
    source.connect(graph.sourceGainA)
    processor.setPlayingState(true)
    if (options.mode === 'worklet') {
      await waitFor(() => graph.audioWorkletNode !== null)
      // Observe native reports without replacing the production message handler.
      graph.audioWorkletNode!.port.addEventListener('message', event => {
        if (event.data.type !== 'analysis') return
        workletReports++
        const { leftChannel, rightChannel, rms, lufs } = event.data
        latestWorkletFrame = { leftChannel, rightChannel, rms, lufs }
        // Native R128 sums stereo energy and applies K weighting; the JS
        // fallback instead reports the unweighted, channel-averaged RMS LUFS.
        if (rms > 0 && lufs > -0.691 + 20 * Math.log10(rms) + 2
          && Number.isFinite(event.data.truePeak)) wasmLoudnessReported = true
      })
    }
    source.start()
    const started = context.currentTime
    await waitFor(() => context.currentTime - started > 0.45 && processor.getAnalysisData().rms > 0.05
      && (options.mode === 'fallback' || wasmLoudnessReported))
    const read = () => {
      const { leftChannel, rightChannel, rms, lufs } = processor.getAnalysisData()
      return { leftChannel, rightChannel, rms, lufs }
    }
    const before = read()
    const phaseData = processor.getAnalysisData().phase
    const phase = phaseData ? { samples: Array.from(phaseData.samples), correlation: phaseData.correlation } : null
    const beforeWorkletFrame = latestWorkletFrame
    processor.setVolume(0.1)
    processor.setMasterGain(-20)
    const changed = context.currentTime
    const reportsBefore = workletReports
    await waitFor(() => context.currentTime - changed > 0.3
      && (options.mode === 'fallback' || workletReports > reportsBefore + 2))
    return { phase, before, after: read(), workletReports, wasmLoudnessReported, beforeWorkletFrame, afterWorkletFrame: latestWorkletFrame }
  } finally {
    source.stop()
    source.disconnect()
    processor.destroy()
    if (context.state !== 'closed') await context.close()
  }
}

async function normalization() {
  const processor = new AudioProcessor()
  const graph = processor as unknown as { audioContext: AudioContext; sourceGainA: GainNode; rebuildGain: GainNode }
  const context = graph.audioContext
  const source = context.createOscillator()
  const level = context.createGain()
  const output = context.createAnalyser()
  output.fftSize = 2048
  source.frequency.value = context.sampleRate / 64
  level.gain.value = 0.2
  source.connect(level)
  const samples = new Float32Array(2048)
  const waitFor = async (condition: () => boolean) => {
    const deadline = performance.now() + 5000
    while (!condition()) {
      if (performance.now() > deadline) throw new Error('Native normalization did not settle')
      await new Promise(resolve => setTimeout(resolve, 10))
    }
  }
  const settled = async () => {
    const start = context.currentTime
    await waitFor(() => context.currentTime - start > 0.8)
    output.getFloatTimeDomainData(samples)
    return { rms: Math.sqrt(samples.reduce((sum, value) => sum + value * value, 0) / samples.length), gainDb: processor.getNormalizationGainDb() }
  }
  try {
    await context.resume()
    await processor.connectDualAudioElements(new Audio(), new Audio())
    level.connect(graph.sourceGainA)
    graph.rebuildGain.connect(output)
    processor.setCompression(false)
    processor.setPlayingState(true)
    source.start()
    const baseline = await settled()
    processor.setNormalization(true, -23)
    await waitFor(() => processor.getNormalizationGainDb() < -1)
    const normalized = await settled()
    processor.setVolume(0.25)
    const quiet = await settled()
    processor.setMuted(true)
    const muted = await settled()
    processor.setMuted(false)
    const unmuted = await settled()
    processor.rebuildChain(['masterGain', 'eq'])
    const rebuilt = await settled()
    processor.setNormalization(false, -23)
    const disabled = await settled()
    processor.setNormalization(true, -23)
    await waitFor(() => processor.getNormalizationGainDb() < -1)
    processor.resetProgramme()
    const resetGain = processor.getNormalizationGainDb()
    return { baseline, normalized, quiet, muted, unmuted, rebuilt, disabled, resetGain }
  } finally {
    source.stop()
    source.disconnect()
    level.disconnect()
    output.disconnect()
    processor.destroy()
  }
}

Object.assign(window, { audioGraphFixture: { render, lifecycle, passiveAnalysis, stereoAnalysis, normalization } })
