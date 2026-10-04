import { getSpectralFeatures, getWasmUrl, getAudioWorkletUrl } from './wasm-loader'
import type { SpectralFeaturesApi } from './wasm-types'
import { createLogger } from '@/shared/lib/logger'
import { StereoMatrix } from './stereo-matrix'

const logger = createLogger('AudioProcessor')

// --- Message types ---

interface WasmSpectrumMessage {
  type: 'ready' | 'error' | 'spectrum'
  frequencyData?: Uint8Array
  timeDomainData?: Uint8Array
}

export interface PhaseAnalysis {
  /** 64 chronological interleaved L/R samples captured together in the worklet. */
  samples: Float32Array
  /** Normalized cross-product; null when either channel has no energy. */
  correlation: number | null
}

interface MeterFrame {
  loudnessReady: boolean
  phase: PhaseAnalysis | null
  leftChannel: number
  rightChannel: number
  rms: number
  lufs: number
  receivedAt: number
}

// --- Analysis data shape ---

export interface AnalysisData {
  phase: PhaseAnalysis | null
  frequencyData: Uint8Array
  timeDomainData: Uint8Array
  leftChannel: number
  rightChannel: number
  lufs: number
  peakFrequency: number
  spectralCentroid: number
  spectralRolloff: number
  spectralFlux: number
  spectralFlatness: number
  rms: number
}

export interface AudioSystemInfo {
  contextState: AudioContextState
  sampleRate: number
  baseLatency: number | null
  outputLatency: number | null
  currentTime: number
  connected: boolean
  passive: boolean
  playing: boolean
  dspReady: boolean
  wasmSpectrumReady: boolean
  workletActive: boolean
  fftSize: number
  filterCount: number
  compressorActive: boolean
}

export class AudioProcessor {
  private audioContext: AudioContext

  // Audio graph nodes — dual source for gapless/crossfade
  private sourceNodeA: MediaElementAudioSourceNode | null = null
  private sourceNodeB: MediaElementAudioSourceNode | null = null
  private readonly mediaSources = new WeakMap<HTMLMediaElement, MediaElementAudioSourceNode>()
  private sourceGainA!: GainNode
  private sourceGainB!: GainNode
  private activeSource: 'A' | 'B' = 'A'
  private dummyElement: HTMLAudioElement | null = null
  private analyzerNode!: AnalyserNode
  private meterSplitter!: ChannelSplitterNode
  private leftMeterAnalyzer!: AnalyserNode
  private rightMeterAnalyzer!: AnalyserNode
  private gainNode!: GainNode
  private normalizationGain!: GainNode
  private normalizationEnabled = false
  private normalizationTargetLufs = -14
  private normalizationGainDb = 0
  private volume = 1
  private muted = false
  private rebuildGain!: GainNode
  private analysisSink!: GainNode
  private rebuildTimer: ReturnType<typeof setTimeout> | null = null
  private destroyed = false
  private workletGeneration = 0
  private workletAbort = new AbortController()
  private chainEntry: AudioNode | null = null
  private masterGainNode!: GainNode
  private compressorNode!: DynamicsCompressorNode
  private filters: BiquadFilterNode[] = []

  // Independent stages can occupy different positions in the processing chain.
  private stereoStage!: StereoMatrix
  private crossfeedStage!: StereoMatrix

  // Loudness contour
  private loudnessGain!: GainNode

  // WASM spectrum (AudioWorkletNode with embedded FFT WASM)
  private wasmSpectrumNode: AudioWorkletNode | null = null
  private wasmSpectrumReady = false

  // WASM DSP modules (main-thread)
  private spectralAPI: SpectralFeaturesApi | null = null
  private dspReady = false

  // AudioWorklet for volume/level analysis (LUFS + meters)
  private audioWorkletNode: AudioWorkletNode | null = null

  // Data buffers
  private readonly FFT_SIZE = 2048
  private readonly TIME_SIZE = 2048
  private frequencyData!: Uint8Array
  private timeDomainData!: Uint8Array
  private tempFrequencyData!: Uint8Array
  private tempTimeDomainData!: Uint8Array
  private readonly leftMeterData = new Float32Array(2048)
  private readonly rightMeterData = new Float32Array(2048)

  // Analysis results
  private peakFrequency = 0
  private spectralCentroid = 0
  private spectralRolloff = 0
  private spectralFlux = 0
  private spectralFlatness = 0
  private latestMeterFrame: MeterFrame | null = null
  private programmeGeneration = 0
  private readonly METER_FRAME_MAX_AGE = 0.25 // Seconds of processed audio.
  private readonly SMOOTHING_TIME = 0.1

  // State
  private isConnected = false
  private passiveMode = false
  private audioElement: HTMLAudioElement | null = null
  private isPlaying = false

  private readonly frequencies = [31.5, 63, 125, 250, 500, 1000, 2000, 4000, 8000, 16000]
  private analysisInterval: number | null = null
  private readonly ANALYSIS_INTERVAL = 40 // ~25fps

  constructor() {
    try {
      this.audioContext = new AudioContext()
      this.frequencyData = new Uint8Array(this.FFT_SIZE / 2)
      this.timeDomainData = new Uint8Array(this.TIME_SIZE).fill(128)
      this.initializeNodes()
      this.setupAudioGraph()
      this.tempFrequencyData = new Uint8Array(this.FFT_SIZE / 2)
      this.tempTimeDomainData = new Uint8Array(this.FFT_SIZE)
      this.initializeDSP()
    } catch (error) {
      console.error('[AudioProcessor] Constructor failed:', error)
      throw error
    }
  }

  // --- Initialization ---

  private async initializeDSP() {
    try {
      const spectral = await getSpectralFeatures()

      if (this.destroyed) return
      this.spectralAPI = spectral
      this.spectralAPI.init(this.FFT_SIZE, this.audioContext.sampleRate)

      this.dspReady = true
    } catch (error) {
      if (this.destroyed) return
      console.warn('[AudioProcessor] Failed to initialize DSP modules:', error)
      this.dspReady = false
    }
  }

  private ownsWorkletGeneration(generation: number): boolean {
    return !this.destroyed && !this.passiveMode && generation === this.workletGeneration
  }

  private async initializeWasmSpectrum(generation = this.workletGeneration) {
    const signal = this.workletAbort.signal
    let node: AudioWorkletNode | null = null
    try {
      if (!this.ownsWorkletGeneration(generation)) return
      if (this.audioContext.state !== 'running') await this.audioContext.resume()
      if (!this.ownsWorkletGeneration(generation)) return

      await this.audioContext.audioWorklet.addModule(getAudioWorkletUrl('wasm-spectrum.js'))
      if (!this.ownsWorkletGeneration(generation) || this.wasmSpectrumNode) return
      node = new AudioWorkletNode(this.audioContext, 'wasm-spectrum', {
        numberOfInputs: 1,
        numberOfOutputs: 1,
        channelCount: 2,
        channelCountMode: 'explicit',
        channelInterpretation: 'speakers',
      })
      this.wasmSpectrumNode = node
      const ownedNode = node
      node.port.onmessage = (event: MessageEvent) => {
        if (!this.ownsWorkletGeneration(generation) || this.wasmSpectrumNode !== ownedNode) return
        const msg = event.data as WasmSpectrumMessage
        if (msg.type === 'ready') {
          if (!this.wasmSpectrumReady) {
            this.analyzerNode.connect(ownedNode)
            ownedNode.connect(this.analysisSink)
          }
          this.wasmSpectrumReady = true
        } else if (msg.type === 'error') {
          console.error('[AudioProcessor] WASM spectrum error:', msg)
          this.wasmSpectrumReady = false
        } else if (msg.type === 'spectrum' && this.isConnected && this.isPlaying
          && msg.frequencyData instanceof Uint8Array && msg.frequencyData.length === this.FFT_SIZE / 2
          && msg.timeDomainData instanceof Uint8Array && msg.timeDomainData.length === this.FFT_SIZE) {
          const freqLen = Math.min(this.frequencyData.length, msg.frequencyData.length)
          const timeLen = Math.min(this.timeDomainData.length, msg.timeDomainData.length)
          for (let i = 0; i < freqLen; i++) this.frequencyData[i] = msg.frequencyData[i]
          for (let i = 0; i < timeLen; i++) this.timeDomainData[i] = msg.timeDomainData[i]
          if (this.spectralAPI && this.dspReady) this.computeSpectralFeatures(msg.frequencyData)
        }
      }

      const response = await fetch(getWasmUrl('fft2048.wasm'), { signal })
      if (!response.ok) throw new Error(`Spectrum WASM request failed: ${response.status}`)
      const wasmBytes = await response.arrayBuffer()
      if (!this.ownsWorkletGeneration(generation) || this.wasmSpectrumNode !== node) return
      node.port.postMessage({ type: 'wasm', bytes: wasmBytes })
    } catch (error) {
      if (!this.ownsWorkletGeneration(generation)) return
      if (node && this.wasmSpectrumNode === node) {
        node.port.onmessage = null
        node.port.close()
        node.disconnect()
        this.wasmSpectrumNode = null
      }
      console.warn('[AudioProcessor] Failed to initialize WASM spectrum:', error)
      this.wasmSpectrumReady = false
    }
  }

  private computeSpectralFeatures(frequencyData: Uint8Array) {
    if (!this.spectralAPI || !this.dspReady || frequencyData.length !== this.FFT_SIZE / 2) return

    let magPtr = 0
    try {
      magPtr = this.spectralAPI.malloc(frequencyData.length)
      if (!magPtr) throw new Error('Spectral allocation failed')
      const HEAPU8 = new Uint8Array(this.spectralAPI.memory.buffer)
      HEAPU8.set(frequencyData, magPtr)

      this.spectralAPI.computeFromMag(magPtr)

      this.spectralCentroid = this.spectralAPI.getCentroidHz()
      this.spectralRolloff = this.spectralAPI.getRolloffHz(0.85)
      this.spectralFlux = this.spectralAPI.getFlux()
      this.spectralFlatness = this.spectralAPI.getFlatness()

      const peakIndex = this.spectralAPI.getPeakIndex()
      this.peakFrequency = (peakIndex / (this.FFT_SIZE / 2)) * (this.audioContext.sampleRate / 2)
    } catch (error) {
      console.warn('[AudioProcessor] Spectral features computation error:', error)
    } finally {
      if (magPtr) this.spectralAPI.free(magPtr)
    }
  }

  private initializeNodes() {
    this.analyzerNode = this.audioContext.createAnalyser()
    this.analyzerNode.fftSize = this.FFT_SIZE
    this.analyzerNode.smoothingTimeConstant = 0.8
    // Both meter branches observe the same stereo mix, including mono upmix.
    this.analyzerNode.channelCount = 2
    this.analyzerNode.channelCountMode = 'explicit'
    this.analyzerNode.channelInterpretation = 'speakers'

    this.gainNode = this.audioContext.createGain()
    this.normalizationGain = this.audioContext.createGain()
    this.normalizationGain.connect(this.gainNode)
    this.rebuildGain = this.audioContext.createGain()
    this.analysisSink = this.audioContext.createGain()
    this.analysisSink.gain.value = 0
    this.analysisSink.connect(this.audioContext.destination)
    // Split before waveform analysis so stereo energy survives opposite phase.
    this.meterSplitter = this.audioContext.createChannelSplitter(2)
    this.leftMeterAnalyzer = this.audioContext.createAnalyser()
    this.rightMeterAnalyzer = this.audioContext.createAnalyser()
    for (const analyzer of [this.leftMeterAnalyzer, this.rightMeterAnalyzer]) {
      analyzer.fftSize = this.TIME_SIZE
      analyzer.channelCount = 1
      analyzer.channelCountMode = 'explicit'
      analyzer.connect(this.analysisSink)
    }
    this.analyzerNode.connect(this.meterSplitter)
    this.meterSplitter.connect(this.leftMeterAnalyzer, 0)
    this.meterSplitter.connect(this.rightMeterAnalyzer, 1)
    this.gainNode.connect(this.rebuildGain)
    this.rebuildGain.connect(this.audioContext.destination)
    this.masterGainNode = this.audioContext.createGain()
    this.compressorNode = this.audioContext.createDynamicsCompressor()

    this.compressorNode.threshold.value = -24
    this.compressorNode.knee.value = 30
    this.compressorNode.ratio.value = 3
    this.compressorNode.attack.value = 0.003
    this.compressorNode.release.value = 0.25

    this.stereoStage = new StereoMatrix(this.audioContext)
    this.crossfeedStage = new StereoMatrix(this.audioContext)

    // Loudness contour gain
    this.loudnessGain = this.audioContext.createGain()
    this.loudnessGain.gain.value = 1.0

    // Dual-source gain nodes for crossfade/gapless
    this.sourceGainA = this.audioContext.createGain()
    this.sourceGainB = this.audioContext.createGain()
    this.sourceGainA.gain.value = 1
    this.sourceGainB.gain.value = 0 // inactive starts silent

    this.initializeEQFilters()
  }

  private initializeEQFilters() {
    this.filters = []
    this.frequencies.forEach((freq, index) => {
      const filter = this.audioContext.createBiquadFilter()
      if (index === 0) {
        filter.type = 'lowshelf'
      } else if (index === this.frequencies.length - 1) {
        filter.type = 'highshelf'
      } else {
        filter.type = 'peaking'
        filter.Q.value = 0.7
      }
      filter.frequency.value = freq
      filter.gain.value = 0
      this.filters.push(filter)
    })
  }

  private currentChainOrder: string[] = []

  private setupAudioGraph() {
    // Default chain order
    this.currentChainOrder = ['eq', 'compressor', 'stereo', 'crossfeed', 'loudness', 'masterGain']
    this.rebuildChainInternal()
  }

  private getChainNode(module: string): { input: AudioNode; output: AudioNode } {
    switch (module) {
      case 'eq':
        return { input: this.filters[0], output: this.filters[this.filters.length - 1] }
      case 'compressor':
        return { input: this.compressorNode, output: this.compressorNode }
      case 'stereo':
        return this.stereoStage
      case 'crossfeed':
        return this.crossfeedStage
      case 'loudness':
        return { input: this.loudnessGain, output: this.loudnessGain }
      case 'masterGain':
        return { input: this.masterGainNode, output: this.masterGainNode }
      default:
        throw new Error(`Unknown audio processing module: ${module}`)
    }
  }

  /** Reconnect stages in order, fading through a gain independent of output settings. */
  rebuildChain(chainOrder: string[]) {
    if (this.passiveMode || this.destroyed) return
    // Validate before altering the live graph. Duplicate stages would create feedback.
    if (new Set(chainOrder).size !== chainOrder.length) {
      throw new Error('Duplicate audio processing module')
    }
    for (const module of chainOrder) this.getChainNode(module)
    this.currentChainOrder = [...chainOrder]
    this.fadeAndRebuild()
  }

  private fadeAndRebuild() {
    if (this.rebuildTimer !== null) clearTimeout(this.rebuildTimer)
    const gain = this.rebuildGain.gain
    const t = this.audioContext.currentTime
    gain.cancelScheduledValues(t)
    gain.setTargetAtTime(0, t, 0.01)
    this.rebuildTimer = setTimeout(() => {
      this.rebuildTimer = null
      if (this.destroyed || this.audioContext.state === 'closed') return
      this.rebuildChainInternal()
      const now = this.audioContext.currentTime
      gain.cancelScheduledValues(now)
      gain.setTargetAtTime(1, now, 0.01)
    }, 30)
  }

  private rebuildChainInternal() {
    // Only detach the old chain entry: metering branches on the analyzer stay connected.
    if (this.chainEntry) this.analyzerNode.disconnect(this.chainEntry)
    for (const filter of this.filters) filter.disconnect()
    this.compressorNode.disconnect()
    this.masterGainNode.disconnect()
    this.stereoStage.output.disconnect()
    this.crossfeedStage.output.disconnect()
    this.loudnessGain.disconnect()

    for (let i = 1; i < this.filters.length; i++) {
      this.filters[i - 1].connect(this.filters[i])
    }

    let currentNode: AudioNode = this.analyzerNode
    this.chainEntry = null
    for (const module of this.currentChainOrder) {
      const stage = this.getChainNode(module)
      this.chainEntry ??= stage.input
      currentNode.connect(stage.input)
      currentNode = stage.output
    }
    this.chainEntry ??= this.normalizationGain
    currentNode.connect(this.normalizationGain)
  }

  // --- Analysis ---

  private clearAnalysis() {
    this.commandNormalizationGain(0)
    this.frequencyData.fill(0)
    this.timeDomainData.fill(128)
    this.peakFrequency = 0
    this.spectralCentroid = 0
    this.spectralRolloff = 0
    this.spectralFlux = 0
    this.spectralFlatness = 0
    this.latestMeterFrame = null
  }

  private setupFallbackAnalysis() {
    if (this.destroyed || this.passiveMode || !this.isConnected || !this.isPlaying) return
    if (this.analysisInterval) clearInterval(this.analysisInterval)
    this.analysisInterval = window.setInterval(() => this.performUnifiedAnalysis(), this.ANALYSIS_INTERVAL)
  }

  private performUnifiedAnalysis() {
    if (this.destroyed || this.passiveMode || !this.isConnected) return
    this.updateNormalization()
    if (!this.isPlaying) {
      this.clearAnalysis()
      return
    }

    if (this.wasmSpectrumReady) return // handled automatically

    if (this.analyzerNode) {
      this.analyzerNode.getByteFrequencyData(this.tempFrequencyData as Uint8Array<ArrayBuffer>)
      this.analyzerNode.getByteTimeDomainData(this.tempTimeDomainData as Uint8Array<ArrayBuffer>)

      const freqLen = Math.min(this.frequencyData.length, this.tempFrequencyData.length)
      const timeLen = Math.min(this.timeDomainData.length, this.tempTimeDomainData.length)
      for (let i = 0; i < freqLen; i++) this.frequencyData[i] = this.tempFrequencyData[i]
      for (let i = 0; i < timeLen; i++) this.timeDomainData[i] = this.tempTimeDomainData[i]

      if (this.spectralAPI && this.dspReady) {
        this.computeSpectralFeatures(this.tempFrequencyData)
      }
    }
  }

  // --- Worklet for LUFS/meter analysis ---

  private async setupVolumeNormalization(generation = this.workletGeneration) {
    try {
      if (!this.ownsWorkletGeneration(generation)) return
      if (this.audioContext.state !== 'running') await this.audioContext.resume()
      if (!this.ownsWorkletGeneration(generation)) return
      if (!this.audioContext.audioWorklet) throw new Error('AudioWorklet not supported')

      if (!this.audioWorkletNode) {
        await this.audioContext.audioWorklet.addModule(getAudioWorkletUrl('magic-soup-processor.js'))
        if (!this.ownsWorkletGeneration(generation) || this.audioWorkletNode) return
        const node = new AudioWorkletNode(this.audioContext, 'magic-soup-processor', {
          numberOfInputs: 1,
          numberOfOutputs: 1,
          channelCount: 2,
          channelCountMode: 'explicit',
          channelInterpretation: 'speakers',
        })
        this.audioWorkletNode = node
        node.port.onmessage = (event: MessageEvent) => {
          if (!this.ownsWorkletGeneration(generation) || this.audioWorkletNode !== node) return
          if (event.data === null || typeof event.data !== 'object') return
          const msg = event.data as { type: string; programmeGeneration?: number } & Partial<MeterFrame>
          if (msg.type === 'request-dsp-init') {
            this.sendDSPToWorklet(node, generation)
          } else if (msg.type === 'analysis' && msg.programmeGeneration === this.programmeGeneration
            && this.isConnected && this.isPlaying
            && typeof msg.leftChannel === 'number' && Number.isFinite(msg.leftChannel) && msg.leftChannel >= 0 && msg.leftChannel <= 100
            && typeof msg.rightChannel === 'number' && Number.isFinite(msg.rightChannel) && msg.rightChannel >= 0 && msg.rightChannel <= 100
            && typeof msg.rms === 'number' && Number.isFinite(msg.rms) && msg.rms >= 0
            && typeof msg.lufs === 'number' && Number.isFinite(msg.lufs)) {
            this.latestMeterFrame = {
              loudnessReady: msg.loudnessReady === true,
              leftChannel: msg.leftChannel, rightChannel: msg.rightChannel, rms: msg.rms,
              lufs: msg.lufs, receivedAt: this.audioContext.currentTime,
              phase: this.validatePhase(msg.phase),
            }
            this.updateNormalization()
          }
        }
      }
      if (this.programmeGeneration > 0) {
        this.audioWorkletNode.port.postMessage({ type: 'reset-programme', programmeGeneration: this.programmeGeneration })
      }
      this.analyzerNode.connect(this.audioWorkletNode)
      this.audioWorkletNode.connect(this.analysisSink)
    } catch {
      if (this.ownsWorkletGeneration(generation)) this.setupFallbackAnalysis()
    }
  }

  private validatePhase(value: unknown): PhaseAnalysis | null {
    if (value === null || typeof value !== 'object') return null
    const phase = value as Partial<PhaseAnalysis>
    if (!(phase.samples instanceof Float32Array) || phase.samples.length !== 128
      || !phase.samples.every(Number.isFinite)
      || !(phase.correlation === null || (typeof phase.correlation === 'number'
        && Number.isFinite(phase.correlation) && phase.correlation >= -1 && phase.correlation <= 1))) return null
    return { samples: phase.samples, correlation: phase.correlation }
  }

  private async sendDSPToWorklet(node: AudioWorkletNode, generation: number) {
    const signal = this.workletAbort.signal
    try {
      const [loudnessWasm, dynamicsWasm] = await Promise.all([
        getWasmUrl('loudness_r128.wasm'), getWasmUrl('dynamics_meter.wasm'),
      ].map(async url => {
        const response = await fetch(url, { signal })
        if (!response.ok) throw new Error(`DSP WASM request failed: ${response.status}`)
        return response.arrayBuffer()
      }))
      if (this.ownsWorkletGeneration(generation) && this.audioWorkletNode === node) {
        node.port.postMessage({ type: 'init-dsp', loudnessWasm, dynamicsWasm })
      }
    } catch (error) {
      if (!this.ownsWorkletGeneration(generation) || this.audioWorkletNode !== node) return
      console.warn('[AudioProcessor] Failed to send DSP to worklet:', error)
    }
  }

  private teardownWorklet() {
    this.latestMeterFrame = null
    this.commandNormalizationGain(0)
    // Invalidate continuations before disconnecting nodes or aborting requests.
    this.workletGeneration++
    this.workletAbort.abort()
    this.workletAbort = new AbortController()
    for (const node of [this.audioWorkletNode, this.wasmSpectrumNode]) {
      if (!node) continue
      node.port.onmessage = null
      node.port.close()
      try { this.analyzerNode.disconnect(node) } catch { /* not yet attached */ }
      node.disconnect()
    }
    this.audioWorkletNode = null
    this.wasmSpectrumNode = null
    this.wasmSpectrumReady = false
  }

  // --- Public API ---

  /** Start a new output-mix measurement; crossfade overlap belongs to the new programme. */
  public resetProgramme() {
    if (this.destroyed) return
    this.programmeGeneration++
    this.latestMeterFrame = null
    this.commandNormalizationGain(0)
    this.audioWorkletNode?.port.postMessage({
      type: 'reset-programme', programmeGeneration: this.programmeGeneration,
    })
  }

  public setPlayingState(isPlaying: boolean) {
    if (this.isPlaying === isPlaying) return
    this.isPlaying = isPlaying

    if (!isPlaying) {
      if (this.analysisInterval) {
        clearInterval(this.analysisInterval)
        this.analysisInterval = null
      }
      this.clearAnalysis()
    } else if (this.isConnected && !this.passiveMode && !this.analysisInterval) {
      this.setupFallbackAnalysis()
    }
  }

  async resumeContextIfNeeded(): Promise<void> {
    if (this.audioContext.state === 'suspended') {
      await this.audioContext.resume()
    }
  }

  /**
   * Connect two audio elements for dual-source gapless/crossfade playback.
   * Source nodes are created once per element (createMediaElementSource is one-shot).
   * Each source → its own GainNode → analyzerNode (summing junction).
   */
  async connectDualAudioElements(elementA: HTMLAudioElement, elementB: HTMLAudioElement) {
    if (this.destroyed) return
    if (elementA === elementB) throw new TypeError('Dual audio sources must use distinct media elements')

    // Guard: skip if already connected to the same pair
    if (
      this.isConnected &&
      !this.passiveMode &&
      this.audioElement === elementA &&
      this.sourceNodeA === this.mediaSources.get(elementA) &&
      this.sourceNodeB === this.mediaSources.get(elementB)
    ) return

    // Allocation is one-shot per element. Retain a successful first allocation if
    // the second fails, while leaving the current graph and analysis untouched.
    const nextA = this.getMediaSource(elementA)
    const nextB = this.getMediaSource(elementB)
    this.connectSourcePair(nextA, nextB)

    this.teardownWorklet()
    if (this.analysisInterval) {
      clearInterval(this.analysisInterval)
      this.analysisInterval = null
    }

    this.sourceNodeA = nextA
    this.sourceNodeB = nextB

    this.activeSource = 'A'
    this.cancelCrossfade()

    this.audioElement = elementA
    this.isConnected = true
    this.passiveMode = false

    if (this.isPlaying) this.setupFallbackAnalysis()
    this.initAdvancedProcessing().catch((err) => { logger.warn('Advanced processing init failed:', err) })
  }

  private getMediaSource(element: HTMLMediaElement): MediaElementAudioSourceNode {
    const existing = this.mediaSources.get(element)
    if (existing) return existing
    const source = this.audioContext.createMediaElementSource(element)
    this.mediaSources.set(element, source)
    return source
  }

  private connectSourcePair(nextA: MediaElementAudioSourceNode, nextB: MediaElementAudioSourceNode): void {
    const previousA = this.sourceNodeA, previousB = this.sourceNodeB
    const wasActive = this.isConnected && !this.passiveMode
    // Remove source edges too: otherwise reusing a cached source in the other
    // slot would send it through both crossfade gains.
    try { previousA?.disconnect(this.sourceGainA) } catch { /* edge may already be absent */ }
    try { previousB?.disconnect(this.sourceGainB) } catch { /* edge may already be absent */ }
    this.sourceGainA.disconnect()
    this.sourceGainB.disconnect()
    try {
      nextA.connect(this.sourceGainA)
      nextB.connect(this.sourceGainB)
      this.sourceGainA.connect(this.analyzerNode)
      this.sourceGainB.connect(this.analyzerNode)
    } catch (error) {
      try { nextA.disconnect(this.sourceGainA) } catch { /* partial connection */ }
      try { nextB.disconnect(this.sourceGainB) } catch { /* partial connection */ }
      this.sourceGainA.disconnect()
      this.sourceGainB.disconnect()
      if (wasActive) {
        try {
          previousA?.connect(this.sourceGainA)
          previousB?.connect(this.sourceGainB)
          this.sourceGainA.connect(this.analyzerNode)
          this.sourceGainB.connect(this.analyzerNode)
        } catch {
          // A failed restoration cannot remain advertised as connected. Cache
          // entries survive disconnect so a later retry reuses one-shot nodes.
          this.disconnect()
        }
      }
      throw error
    }
  }

  /**
   * Backward-compat single-element connect: creates a dummy for the B channel.
   */
  async connectAudioElement(audioElement: HTMLAudioElement) {
    if (this.destroyed) return
    if (!this.dummyElement) {
      this.dummyElement = new Audio()
      this.dummyElement.crossOrigin = 'anonymous'
    }
    await this.connectDualAudioElements(audioElement, this.dummyElement)
  }

  /**
   * Advanced processing: worklets + WASM spectrum analysis.
   * Runs in the background after the core audio graph is wired.
   */
  private async initAdvancedProcessing() {
    const generation = this.workletGeneration
    await this.setupVolumeNormalization(generation)
    if (!this.ownsWorkletGeneration(generation)) return
    await this.initializeWasmSpectrum(generation)
  }

  async initializePassiveMode() {
    if (this.destroyed) return
    this.teardownWorklet()
    this.passiveMode = true
    this.isConnected = true
    if (this.analysisInterval) {
      clearInterval(this.analysisInterval)
      this.analysisInterval = null
    }
    this.clearAnalysis()
  }

  disconnect() {
    // Disconnect gain nodes from analyzerNode — source nodes persist for element lifetime
    // (createMediaElementSource is one-shot; disconnecting sourceNode would orphan the element).
    try { this.sourceGainA.disconnect() } catch { /* ignore */ }
    try { this.sourceGainB.disconnect() } catch { /* ignore */ }
    if (this.analysisInterval) {
      clearInterval(this.analysisInterval)
      this.analysisInterval = null
    }
    this.teardownWorklet()
    this.audioElement = null
    this.isConnected = false
    this.passiveMode = false
    this.isPlaying = false
    this.clearAnalysis()
  }

  // --- Crossfade / swap methods ---

  getActiveSource(): 'A' | 'B' { return this.activeSource }

  getSourceGainA(): GainNode { return this.sourceGainA }

  getSourceGainB(): GainNode { return this.sourceGainB }

  /**
   * Crossfade from the active source to the inactive one over `duration` seconds.
   */
  crossfadeToInactive(duration: number): void {
    if (!Number.isFinite(duration) || duration < 0) throw new RangeError('Invalid crossfade duration')
    this.cancelCrossfade()
    const t = this.audioContext.currentTime
    if (this.activeSource === 'A') {
      this.sourceGainA.gain.linearRampToValueAtTime(0, t + duration)
      this.sourceGainB.gain.linearRampToValueAtTime(1, t + duration)
      this.activeSource = 'B'
    } else {
      this.sourceGainB.gain.linearRampToValueAtTime(0, t + duration)
      this.sourceGainA.gain.linearRampToValueAtTime(1, t + duration)
      this.activeSource = 'A'
    }
  }

  /**
   * Instantly swap active source (no ramp — for gapless without crossfade).
   */
  instantSwap(): void {
    this.activeSource = this.activeSource === 'A' ? 'B' : 'A'
    this.cancelCrossfade()
  }

  /** Finish an interrupted transition on the current active source. */
  cancelCrossfade(): void {
    const t = this.audioContext.currentTime
    this.sourceGainA.gain.cancelScheduledValues(t)
    this.sourceGainB.gain.cancelScheduledValues(t)
    this.sourceGainA.gain.setValueAtTime(this.activeSource === 'A' ? 1 : 0, t)
    this.sourceGainB.gain.setValueAtTime(this.activeSource === 'B' ? 1 : 0, t)
  }

  destroy() {
    if (this.destroyed) return
    this.destroyed = true
    this.spectralAPI = null
    this.dspReady = false
    if (this.rebuildTimer !== null) {
      clearTimeout(this.rebuildTimer)
      this.rebuildTimer = null
    }
    if (this.analysisInterval) {
      clearInterval(this.analysisInterval)
      this.analysisInterval = null
    }
    this.disconnect()
    if (this.audioContext.state !== 'closed') {
      this.audioContext.close()
    }
  }

  setVolume(volume: number) {
    if (!Number.isFinite(volume)) return
    this.volume = Math.max(0, Math.min(1, volume))
    if (this.passiveMode || this.destroyed) return
    this.applyOutputGain()
  }

  setMuted(muted: boolean) {
    this.muted = muted
    if (this.passiveMode || this.destroyed) return
    this.applyOutputGain()
  }

  private applyOutputGain(): void {
    const gain = this.gainNode.gain
    const now = this.audioContext.currentTime
    if (!this.isConnected || this.muted) {
      gain.cancelScheduledValues(now)
      gain.setValueAtTime(this.muted ? 0 : this.volume, now)
    } else {
      gain.setTargetAtTime(this.volume, now, 0.05)
    }
  }

  setMasterGain(gainDb: number) {
    if (this.passiveMode) return
    const linearGain = Math.pow(10, gainDb / 20)
    this.masterGainNode.gain.setTargetAtTime(linearGain, this.audioContext.currentTime, this.SMOOTHING_TIME)
  }

  updateEQBands(bands: Array<{ gain: number; q?: number }>) {
    if (this.passiveMode) return
    bands.forEach((band, index) => {
      if (index < this.filters.length) {
        this.filters[index].gain.setTargetAtTime(band.gain, this.audioContext.currentTime, this.SMOOTHING_TIME)
        if (band.q !== undefined) {
          this.filters[index].Q.setTargetAtTime(band.q, this.audioContext.currentTime, this.SMOOTHING_TIME)
        }
      }
    })
  }

  setCompression(enabled: boolean) {
    if (this.passiveMode) return
    if (enabled) {
      this.compressorNode.threshold.value = -24
      this.compressorNode.ratio.value = 3
    } else {
      this.compressorNode.threshold.value = -50
      this.compressorNode.ratio.value = 1
    }
  }

  setCompressorParams(params: { threshold?: number; ratio?: number; knee?: number; attack?: number; release?: number }) {
    if (this.passiveMode) return
    const t = this.audioContext.currentTime
    if (params.threshold !== undefined) this.compressorNode.threshold.setTargetAtTime(params.threshold, t, this.SMOOTHING_TIME)
    if (params.ratio !== undefined) this.compressorNode.ratio.setTargetAtTime(params.ratio, t, this.SMOOTHING_TIME)
    if (params.knee !== undefined) this.compressorNode.knee.setTargetAtTime(params.knee, t, this.SMOOTHING_TIME)
    if (params.attack !== undefined) this.compressorNode.attack.setTargetAtTime(params.attack / 1000, t, this.SMOOTHING_TIME)
    if (params.release !== undefined) this.compressorNode.release.setTargetAtTime(params.release / 1000, t, this.SMOOTHING_TIME)
  }

  setStereoWidth(width: number, mode: 'normal' | 'mid' | 'side' = 'normal') {
    if (this.passiveMode) return
    // M=(L+R)/2, S=(L-R)/2. Width preserves M; isolation selects M or S.
    const mid = mode === 'side' ? 0 : 1
    const side = mode === 'mid' ? 0 : mode === 'side' ? 1 : width
    this.stereoStage.setCoefficients(
      (mid + side) / 2, (mid - side) / 2, this.audioContext.currentTime, this.SMOOTHING_TIME,
    )
  }

  setCrossfeed(amount: number) {
    if (this.passiveMode) return
    this.crossfeedStage.setCoefficients(1, amount, this.audioContext.currentTime, this.SMOOTHING_TIME)
  }

  setLoudnessContour(enabled: boolean, volume?: number) {
    if (this.passiveMode) return
    if (enabled && volume !== undefined) {
      // ISO 226 approx: boost lows and highs at low volume
      // Simple approximation: gain = 1 + (50 - volume) / 100 for bass boost
      const boost = Math.max(0, (50 - volume) / 100)
      this.loudnessGain.gain.setTargetAtTime(1 + boost, this.audioContext.currentTime, this.SMOOTHING_TIME)
    } else {
      this.loudnessGain.gain.setTargetAtTime(1.0, this.audioContext.currentTime, this.SMOOTHING_TIME)
    }
  }

  setNormalization(enabled: boolean, targetLufs: number): void {
    if (!Number.isFinite(targetLufs)) return
    this.normalizationEnabled = enabled
    this.normalizationTargetLufs = targetLufs
    this.updateNormalization()
  }

  getNormalizationGainDb(): number {
    return this.normalizationGainDb
  }

  private updateNormalization(): void {
    const frame = this.latestMeterFrame
    const age = frame ? this.audioContext.currentTime - frame.receivedAt : Infinity
    if (!this.normalizationEnabled || this.destroyed || this.passiveMode || !this.isConnected || !this.isPlaying
      || !frame?.loudnessReady || age < 0 || age > this.METER_FRAME_MAX_AGE
      || frame.rms <= 1e-6 || frame.lufs <= -60) {
      this.commandNormalizationGain(0)
      return
    }
    this.commandNormalizationGain(Math.max(-20, Math.min(20 * Math.log10(2), this.normalizationTargetLufs - frame.lufs)))
  }

  private commandNormalizationGain(gainDb: number): void {
    if (gainDb === this.normalizationGainDb) return
    this.normalizationGainDb = gainDb
    if (this.audioContext.state !== 'closed') {
      this.normalizationGain.gain.setTargetAtTime(Math.pow(10, gainDb / 20), this.audioContext.currentTime, this.SMOOTHING_TIME)
    }
  }

  getAnalysisData(): AnalysisData {
    if (this.destroyed || this.passiveMode || !this.isConnected || !this.isPlaying) {
      return {
        phase: null,
        frequencyData: this.frequencyData,
        timeDomainData: this.timeDomainData,
        leftChannel: 0,
        rightChannel: 0,
        lufs: -60,
        peakFrequency: 0,
        spectralCentroid: 0,
        spectralRolloff: 0,
        spectralFlux: 0,
        spectralFlatness: 0,
        rms: 0,
      }
    }

    if (!this.wasmSpectrumReady) this.performUnifiedAnalysis()

    const frame = this.latestMeterFrame
    const age = frame ? this.audioContext.currentTime - frame.receivedAt : Infinity
    let leftChannel: number, rightChannel: number, rms: number, lufs: number
    let phase: PhaseAnalysis | null = null
    if (frame && age >= 0 && age <= this.METER_FRAME_MAX_AGE) {
      // Worklet LUFS and RMS already have their own audio-time windows.
      phase = frame.phase
      leftChannel = frame.leftChannel
      rightChannel = frame.rightChannel
      rms = frame.rms
      lufs = frame.lufs
    } else {
      this.leftMeterAnalyzer.getFloatTimeDomainData(this.leftMeterData)
      this.rightMeterAnalyzer.getFloatTimeDomainData(this.rightMeterData)
      let leftSum = 0, rightSum = 0
      for (let i = 0; i < this.TIME_SIZE; i++) {
        leftSum += this.leftMeterData[i] * this.leftMeterData[i]
        rightSum += this.rightMeterData[i] * this.rightMeterData[i]
      }
      leftChannel = Math.min(100, Math.sqrt(leftSum / this.TIME_SIZE) * 100)
      rightChannel = Math.min(100, Math.sqrt(rightSum / this.TIME_SIZE) * 100)
      rms = Math.sqrt((leftSum + rightSum) / (2 * this.TIME_SIZE))
      // Unweighted stereo energy estimate, not EBU R128 loudness.
      lufs = rms < 1e-6 ? -60 : -0.691 + 20 * Math.log10(rms)
    }

    return {
      phase,
      frequencyData: this.frequencyData,
      timeDomainData: this.timeDomainData,
      leftChannel,
      rightChannel,
      lufs,
      peakFrequency: this.peakFrequency,
      spectralCentroid: this.spectralCentroid,
      spectralRolloff: this.spectralRolloff,
      spectralFlux: this.spectralFlux,
      spectralFlatness: this.spectralFlatness,
      rms,
    }
  }

  getSystemInfo(): AudioSystemInfo {
    const ctx = this.audioContext
    return {
      contextState: ctx.state,
      sampleRate: ctx.sampleRate,
      baseLatency: ctx.baseLatency ?? null,
      outputLatency: ctx.outputLatency ?? null,
      currentTime: ctx.currentTime,
      connected: this.isConnected,
      passive: this.passiveMode,
      playing: this.isPlaying,
      dspReady: this.dspReady,
      wasmSpectrumReady: this.wasmSpectrumReady,
      workletActive: this.audioWorkletNode !== null,
      fftSize: this.FFT_SIZE,
      filterCount: this.filters.length,
      compressorActive: this.compressorNode.ratio.value > 1,
    }
  }

  get isActive(): boolean {
    return this.isConnected
  }

  get passive(): boolean {
    return this.passiveMode
  }

  get playing(): boolean {
    return this.isPlaying
  }

  get context(): AudioContext {
    return this.audioContext
  }
}
