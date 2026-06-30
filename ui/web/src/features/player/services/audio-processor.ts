import { getDynamics, getLoudness, getSpectralFeatures, getWasmUrl, getAudioWorkletUrl } from './wasm-loader'
import type { LoudnessR128API, DynamicsMeterAPI, SpectralFeaturesApi } from './wasm-types'
import { createLogger } from '@/shared/lib/logger'

const logger = createLogger('AudioProcessor')

// --- Message types ---

interface WasmSpectrumMessage {
  type: 'ready' | 'error' | 'spectrum'
  frequencyData?: Uint8Array
  timeDomainData?: Uint8Array
}

// --- Analysis data shape ---

export interface AnalysisData {
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
  workerReady: boolean
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
  private sourceGainA!: GainNode
  private sourceGainB!: GainNode
  private activeSource: 'A' | 'B' = 'A'
  private dummyElement: HTMLAudioElement | null = null
  private analyzerNode!: AnalyserNode
  private gainNode!: GainNode
  private masterGainNode!: GainNode
  private compressorNode!: DynamicsCompressorNode
  private filters: BiquadFilterNode[] = []

  // Stereo + Crossfeed nodes
  private channelSplitter!: ChannelSplitterNode
  private channelMerger!: ChannelMergerNode
  private leftGain!: GainNode
  private rightGain!: GainNode
  private midGain!: GainNode
  private sideGain!: GainNode
  private crossfeedLeft!: GainNode
  private crossfeedRight!: GainNode

  // Loudness contour
  private loudnessGain!: GainNode

  // WASM spectrum (AudioWorkletNode with embedded FFT WASM)
  private wasmSpectrumNode: AudioWorkletNode | null = null
  private wasmSpectrumReady = false

  // WASM DSP modules (main-thread)
  private loudnessAPI: LoudnessR128API | null = null
  private dynamicsAPI: DynamicsMeterAPI | null = null
  private spectralAPI: SpectralFeaturesApi | null = null
  private dspReady = false

  // AudioWorklet for volume/level analysis (LUFS + meters)
  private audioWorkletNode: AudioWorkletNode | null = null

  // Web Worker for background spectral analysis
  private analysisWorker: Worker | null = null
  private workerReady = false
  private lastWorkerAnalysisTime = 0

  // Data buffers
  private readonly FFT_SIZE = 2048
  private readonly TIME_SIZE = 2048
  private sharedFrequencyBuffer: SharedArrayBuffer | null = null
  private sharedTimeDomainBuffer: SharedArrayBuffer | null = null
  private frequencyData!: Uint8Array
  private timeDomainData!: Uint8Array
  private tempFrequencyData!: Uint8Array
  private tempTimeDomainData!: Uint8Array

  // Analysis results
  private peakFrequency = 0
  private spectralCentroid = 0
  private spectralRolloff = 0
  private spectralFlux = 0
  private spectralFlatness = 0
  private lufsBuffer: number[] = []
  private readonly LUFS_WINDOW_SIZE = 400
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
      this.initializeSharedBuffers()
      this.initializeNodes()
      this.setupAudioGraph()
      this.tempFrequencyData = new Uint8Array(this.FFT_SIZE / 2)
      this.tempTimeDomainData = new Uint8Array(this.FFT_SIZE)
      this.initializeDSP()
      this.initializeWorker()
    } catch (error) {
      console.error('[AudioProcessor] Constructor failed:', error)
      throw error
    }
  }

  // --- Initialization ---

  private async initializeDSP() {
    try {
      ;[this.loudnessAPI, this.dynamicsAPI, this.spectralAPI] = await Promise.all([
        getLoudness(),
        getDynamics(),
        getSpectralFeatures(),
      ])

      this.loudnessAPI.init(this.audioContext.sampleRate, 2)
      this.dynamicsAPI.init(10, 100, this.audioContext.sampleRate)
      this.spectralAPI.init(this.FFT_SIZE, this.audioContext.sampleRate)

      this.dspReady = true
    } catch (error) {
      console.warn('[AudioProcessor] Failed to initialize DSP modules:', error)
      this.dspReady = false
    }
  }

  private async initializeWasmSpectrum() {
    try {
      if (this.audioContext.state !== 'running') {
        await this.audioContext.resume()
      }

      await this.audioContext.audioWorklet.addModule(getAudioWorkletUrl('wasm-spectrum.js'))

      this.wasmSpectrumNode = new AudioWorkletNode(this.audioContext, 'wasm-spectrum', {
        numberOfInputs: 1,
        numberOfOutputs: 1,
        channelCount: 2,
        channelCountMode: 'explicit',
        channelInterpretation: 'speakers',
      })

      const wasmBytes = await fetch(getWasmUrl('fft2048.wasm')).then((r) => r.arrayBuffer())
      this.wasmSpectrumNode.port.postMessage({ type: 'wasm', bytes: wasmBytes })

      this.wasmSpectrumNode.port.onmessage = (event: MessageEvent) => {
        const msg = event.data as WasmSpectrumMessage
        if (msg.type === 'ready') {
          this.wasmSpectrumReady = true
        } else if (msg.type === 'error') {
          console.error('[AudioProcessor] WASM spectrum error:', msg)
          this.wasmSpectrumReady = false
        } else if (msg.type === 'spectrum' && msg.frequencyData && msg.timeDomainData) {
          const freqLen = Math.min(this.frequencyData.length, msg.frequencyData.length)
          const timeLen = Math.min(this.timeDomainData.length, msg.timeDomainData.length)
          for (let i = 0; i < freqLen; i++) this.frequencyData[i] = msg.frequencyData[i]
          for (let i = 0; i < timeLen; i++) this.timeDomainData[i] = msg.timeDomainData[i]

          if (this.spectralAPI && this.dspReady) {
            this.computeSpectralFeatures(msg.frequencyData)
          }
        }
      }
    } catch (error) {
      console.warn('[AudioProcessor] Failed to initialize WASM spectrum:', error)
      this.wasmSpectrumReady = false
    }
  }

  private computeSpectralFeatures(frequencyData: Uint8Array) {
    if (!this.spectralAPI || !this.dspReady) return

    try {
      const magPtr = this.spectralAPI.malloc(frequencyData.length)
      const HEAPU8 = new Uint8Array(this.spectralAPI.memory.buffer)
      HEAPU8.set(frequencyData, magPtr)

      this.spectralAPI.computeFromMag(magPtr)

      this.spectralCentroid = this.spectralAPI.getCentroidHz()
      this.spectralRolloff = this.spectralAPI.getRolloffHz(0.85)
      this.spectralFlux = this.spectralAPI.getFlux()
      this.spectralFlatness = this.spectralAPI.getFlatness()

      const peakIndex = this.spectralAPI.getPeakIndex()
      this.peakFrequency = (peakIndex / (this.FFT_SIZE / 2)) * (this.audioContext.sampleRate / 2)

      this.spectralAPI.free(magPtr)
    } catch (error) {
      console.warn('[AudioProcessor] Spectral features computation error:', error)
    }
  }

  private initializeSharedBuffers() {
    try {
      if (typeof SharedArrayBuffer !== 'undefined') {
        this.sharedFrequencyBuffer = new SharedArrayBuffer(this.FFT_SIZE / 2)
        this.sharedTimeDomainBuffer = new SharedArrayBuffer(this.TIME_SIZE)
        this.frequencyData = new Uint8Array(this.sharedFrequencyBuffer)
        this.timeDomainData = new Uint8Array(this.sharedTimeDomainBuffer)
        this.frequencyData.fill(20)
        this.timeDomainData.fill(128)
        return
      }
    } catch {
      // fall through
    }
    this.frequencyData = new Uint8Array(this.FFT_SIZE / 2)
    this.timeDomainData = new Uint8Array(this.TIME_SIZE)
    this.frequencyData.fill(20)
    this.timeDomainData.fill(128)
  }

  private initializeNodes() {
    this.analyzerNode = this.audioContext.createAnalyser()
    this.analyzerNode.fftSize = this.FFT_SIZE
    this.analyzerNode.smoothingTimeConstant = 0.8

    this.gainNode = this.audioContext.createGain()
    this.masterGainNode = this.audioContext.createGain()
    this.compressorNode = this.audioContext.createDynamicsCompressor()

    this.compressorNode.threshold.value = -24
    this.compressorNode.knee.value = 30
    this.compressorNode.ratio.value = 3
    this.compressorNode.attack.value = 0.003
    this.compressorNode.release.value = 0.25

    // Stereo width nodes
    this.channelSplitter = this.audioContext.createChannelSplitter(2)
    this.channelMerger = this.audioContext.createChannelMerger(2)
    this.leftGain = this.audioContext.createGain()
    this.rightGain = this.audioContext.createGain()
    this.midGain = this.audioContext.createGain()
    this.sideGain = this.audioContext.createGain()

    // Crossfeed nodes (default: passthrough, 0 crossfeed)
    this.crossfeedLeft = this.audioContext.createGain()
    this.crossfeedRight = this.audioContext.createGain()
    this.crossfeedLeft.gain.value = 0
    this.crossfeedRight.gain.value = 0

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
    this.currentChainOrder = ['eq', 'compressor', 'masterGain']
    this.rebuildChainInternal()
  }

  private getChainNode(module: string): AudioNode {
    switch (module) {
      case 'eq':
        // Return last filter in the chain — the filters chain is always sequential
        return this.filters[this.filters.length - 1]
      case 'compressor':
        return this.compressorNode
      case 'stereo':
        return this.channelMerger
      case 'crossfeed':
        return this.channelMerger // Crossfeed is part of the stereo path
      case 'loudness':
        return this.loudnessGain
      case 'masterGain':
        return this.masterGainNode
      default:
        return this.masterGainNode
    }
  }

  /**
   * Reconnect the processing chain in a new order.
   * Uses fade-out / fade-in to avoid clicks.
   * chainOrder lists modules from first (after source) to last (before destination).
   */
  rebuildChain(chainOrder: string[]) {
    if (this.passiveMode) return
    this.currentChainOrder = chainOrder
    this.fadeAndRebuild()
  }

  private async fadeAndRebuild() {
    const t = this.audioContext.currentTime
    const fadeTime = 0.01 // 10ms

    // Fade out
    this.gainNode.gain.setTargetAtTime(0, t, fadeTime)

    // Wait for fade out
    await new Promise((resolve) => setTimeout(resolve, 30))

    this.rebuildChainInternal()

    // Fade in
    this.gainNode.gain.setTargetAtTime(1, t + fadeTime, fadeTime)
  }

  private rebuildChainInternal() {
    // Disconnect everything after the filters
    for (const filter of this.filters) {
      filter.disconnect()
    }
    this.compressorNode.disconnect()
    this.masterGainNode.disconnect()
    this.channelSplitter.disconnect()
    this.channelMerger.disconnect()
    this.loudnessGain.disconnect()

    // Re-wire the EQ filter chain
    let currentNode: AudioNode = this.analyzerNode
    for (const filter of this.filters) {
      currentNode.connect(filter)
      currentNode = filter
    }

    // Determine if stereo processing is in the chain
    const hasStereo = this.currentChainOrder.includes('stereo') || this.currentChainOrder.includes('crossfeed')
    let stereoWired = false

    // Wire modules in order
    for (const module of this.currentChainOrder) {
      if (module === 'eq') continue // Already wired above

      if ((module === 'stereo' || module === 'crossfeed') && hasStereo) {
        if (stereoWired) continue // Already wired — skip duplicate
        stereoWired = true

        // Stereo: split → left/right + crossfeed → merge
        currentNode.connect(this.channelSplitter)
        // Left path
        this.channelSplitter.connect(this.leftGain, 0)
        this.channelSplitter.connect(this.crossfeedRight, 1) // R→L crossfeed
        this.leftGain.connect(this.channelMerger, 0, 0)
        this.crossfeedRight.connect(this.channelMerger, 0, 0)
        // Right path
        this.channelSplitter.connect(this.rightGain, 1)
        this.channelSplitter.connect(this.crossfeedLeft, 0) // L→R crossfeed
        this.rightGain.connect(this.channelMerger, 0, 1)
        this.crossfeedLeft.connect(this.channelMerger, 0, 1)

        currentNode = this.channelMerger
        continue
      }

      const nextNode = this.getChainNode(module)
      currentNode.connect(nextNode)
      currentNode = nextNode
    }

    // Final connection to output
    currentNode.connect(this.gainNode)
    this.gainNode.connect(this.audioContext.destination)
  }

  // --- Worker ---

  private initializeWorker() {
    try {
      this.analysisWorker = new Worker(getAudioWorkletUrl('audio-analysis-worker.js'))

      this.analysisWorker.onmessage = (e: MessageEvent) => {
        const data = e.data as { type: string; frequencyData?: Uint8Array; timeDomainData?: Uint8Array; peakFrequency?: number; spectralCentroid?: number; spectralRolloff?: number; spectralFlux?: number; spectralFlatness?: number }
        if (data.type === 'analysis-result') {
          if (!this.sharedFrequencyBuffer) {
            if (data.frequencyData && data.frequencyData.length > 0) {
              const len = Math.min(this.frequencyData.length, data.frequencyData.length)
              for (let i = 0; i < len; i++) this.frequencyData[i] = data.frequencyData[i]
            }
            if (data.timeDomainData && data.timeDomainData.length > 0) {
              const len = Math.min(this.timeDomainData.length, data.timeDomainData.length)
              for (let i = 0; i < len; i++) this.timeDomainData[i] = data.timeDomainData[i]
            }
          }
          this.peakFrequency = data.peakFrequency || 0
          this.spectralCentroid = data.spectralCentroid || 0
          this.spectralRolloff = data.spectralRolloff || 0
          this.spectralFlux = data.spectralFlux || 0
          this.spectralFlatness = data.spectralFlatness || 0
        }
      }

      this.analysisWorker.onerror = () => {
        this.workerReady = false
        this.analysisWorker = null
        this.setupFallbackAnalysis()
      }

      if (this.sharedFrequencyBuffer && this.sharedTimeDomainBuffer) {
        this.analysisWorker.postMessage({
          type: 'init-shared-buffers',
          frequencyBuffer: this.sharedFrequencyBuffer,
          timeDomainBuffer: this.sharedTimeDomainBuffer,
        })
      } else {
        this.analysisWorker.postMessage({
          type: 'init',
          length: { freq: this.FFT_SIZE / 2, time: this.TIME_SIZE },
        })
      }

      // Send spectral WASM to worker
      this.sendSpectralWasmToWorker()
      this.workerReady = true
    } catch {
      this.workerReady = false
      this.analysisWorker = null
      this.setupFallbackAnalysis()
    }
  }

  private async sendSpectralWasmToWorker() {
    try {
      const spectralWasm = await fetch(getWasmUrl('spectral_features.wasm')).then((r) => r.arrayBuffer())
      this.analysisWorker?.postMessage({ type: 'init-spectral-wasm', spectralWasm })
    } catch (error) {
      console.warn('[AudioProcessor] Failed to send spectral WASM to worker:', error)
    }
  }

  // --- Analysis ---

  private setupFallbackAnalysis() {
    if (this.analysisInterval) clearInterval(this.analysisInterval)
    this.analysisInterval = window.setInterval(() => this.performUnifiedAnalysis(), this.ANALYSIS_INTERVAL)
  }

  private performUnifiedAnalysis() {
    if (!this.isPlaying) {
      this.frequencyData.fill(20)
      this.timeDomainData.fill(128)
      this.peakFrequency = 0
      this.spectralCentroid = 0
      this.spectralRolloff = 0
      this.spectralFlux = 0
      this.spectralFlatness = 0
      return
    }

    const now = performance.now()

    if (this.passiveMode) {
      if (this.workerReady && this.analysisWorker && now - this.lastWorkerAnalysisTime > 100) {
        this.lastWorkerAnalysisTime = now
        this.analysisWorker.postMessage({
          type: 'analyze',
          isPassiveMode: true,
          sampleRate: this.audioContext.sampleRate,
          useSharedBuffer: !!this.sharedFrequencyBuffer,
          isPlaying: this.isPlaying,
        })
      }
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

      let sum = 0
      const step = 4
      for (let i = 0; i < this.tempTimeDomainData.length; i += step) {
        const normalized = (this.tempTimeDomainData[i] - 128) / 128
        sum += normalized * normalized
      }
      const rms = Math.sqrt(sum / (this.tempTimeDomainData.length / step))
      const estimatedLufs = -0.691 + 10 * Math.log10(rms * rms + 1e-10)

      this.lufsBuffer.push(estimatedLufs)
      if (this.lufsBuffer.length > this.LUFS_WINDOW_SIZE) this.lufsBuffer.shift()
    }
  }

  // --- Worklet for LUFS/meter analysis ---

  private async setupVolumeNormalization() {
    try {
      if (this.passiveMode) return

      if (this.audioContext.state !== 'running') {
        await this.audioContext.resume()
      }

      if (!this.audioContext.audioWorklet) {
        throw new Error('AudioWorklet not supported')
      }

      if (!this.audioWorkletNode) {
        await this.audioContext.audioWorklet.addModule(getAudioWorkletUrl('magic-soup-processor.js'))
        this.audioWorkletNode = new AudioWorkletNode(this.audioContext, 'magic-soup-processor', {
          numberOfInputs: 1,
          numberOfOutputs: 1,
          channelCount: 2,
          channelCountMode: 'explicit',
          channelInterpretation: 'speakers',
        })

        this.audioWorkletNode.port.onmessage = (event: MessageEvent) => {
          const msg = event.data as { type: string; lufs?: number }
          if (msg.type === 'request-dsp-init') {
            this.sendDSPToWorklet()
          } else if (msg?.type === 'analysis') {
            if (msg.lufs != null) this.lufsBuffer.push(msg.lufs)
            if (this.lufsBuffer.length > this.LUFS_WINDOW_SIZE) this.lufsBuffer.shift()
          }
        }
      }

      if (this.sourceNodeA || this.sourceNodeB) {
        try {
          this.compressorNode.disconnect()
        } catch {
          // ignore
        }
        this.compressorNode.connect(this.audioWorkletNode)
        this.audioWorkletNode.connect(this.masterGainNode)
      }
    } catch {
      this.setupFallbackAnalysis()
    }
  }

  private async sendDSPToWorklet() {
    try {
      const [loudnessWasm, dynamicsWasm] = await Promise.all([
        fetch(getWasmUrl('loudness_r128.wasm')).then((r) => r.arrayBuffer()),
        fetch(getWasmUrl('dynamics_meter.wasm')).then((r) => r.arrayBuffer()),
      ])

      if (this.audioWorkletNode) {
        this.audioWorkletNode.port.postMessage({ type: 'init-dsp', loudnessWasm, dynamicsWasm })
      }
    } catch (error) {
      console.warn('[AudioProcessor] Failed to send DSP to worklet:', error)
    }
  }

  private teardownWorklet() {
    if (this.audioWorkletNode) {
      try {
        this.audioWorkletNode.disconnect()
      } catch {
        // ignore
      }
      this.audioWorkletNode = null
    }

    if (this.wasmSpectrumNode) {
      try {
        this.wasmSpectrumNode.disconnect()
      } catch {
        // ignore
      }
      this.wasmSpectrumNode = null
      this.wasmSpectrumReady = false
    }

    try {
      this.compressorNode.disconnect()
    } catch {
      // ignore
    }
    this.compressorNode.connect(this.masterGainNode)
  }

  // --- Public API ---

  public setPlayingState(isPlaying: boolean) {
    if (this.isPlaying === isPlaying) return
    this.isPlaying = isPlaying

    this.analysisWorker?.postMessage({ type: 'set-playing-state', isPlaying })

    if (!isPlaying) {
      if (this.analysisInterval) {
        clearInterval(this.analysisInterval)
        this.analysisInterval = null
      }
      this.frequencyData.fill(20)
      this.timeDomainData.fill(128)
      this.peakFrequency = 0
      this.spectralCentroid = 0
      this.spectralRolloff = 0
      this.spectralFlux = 0
      this.spectralFlatness = 0
      this.lufsBuffer = []
    } else if (this.isConnected && !this.analysisInterval) {
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
    if (this.analysisInterval) clearInterval(this.analysisInterval)

    // Guard: skip if already connected to the same pair
    if (
      this.isConnected &&
      this.audioElement === elementA &&
      this.sourceNodeA && this.sourceNodeB
    ) return

    // Disconnect old gain nodes from analyzerNode (source nodes persist)
    try { this.sourceGainA.disconnect() } catch { /* ignore */ }
    try { this.sourceGainB.disconnect() } catch { /* ignore */ }

    // Create source nodes only once per element
    if (!this.sourceNodeA) {
      this.sourceNodeA = this.audioContext.createMediaElementSource(elementA)
    }
    if (!this.sourceNodeB) {
      this.sourceNodeB = this.audioContext.createMediaElementSource(elementB)
    }

    // Wire: source → sourceGain → analyzerNode (summing junction)
    this.sourceNodeA.connect(this.sourceGainA)
    this.sourceNodeB.connect(this.sourceGainB)
    this.sourceGainA.connect(this.analyzerNode)
    this.sourceGainB.connect(this.analyzerNode)

    this.activeSource = 'A'
    this.sourceGainA.gain.value = 1
    this.sourceGainB.gain.value = 0

    this.audioElement = elementA
    this.isConnected = true
    this.passiveMode = false

    if (this.isPlaying) this.setupFallbackAnalysis()
    this.initAdvancedProcessing().catch((err) => { logger.warn('Advanced processing init failed:', err) })
  }

  /**
   * Backward-compat single-element connect: creates a dummy for the B channel.
   */
  async connectAudioElement(audioElement: HTMLAudioElement) {
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
    await this.setupVolumeNormalization()
    await this.initializeWasmSpectrum()
    if (this.wasmSpectrumNode && this.wasmSpectrumReady) {
      this.analyzerNode.connect(this.wasmSpectrumNode)
    }
  }

  async initializePassiveMode() {
    this.passiveMode = true
    this.isConnected = true
    if (this.analysisInterval) clearInterval(this.analysisInterval)
    if (this.isPlaying) this.setupFallbackAnalysis()
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
  }

  // --- Crossfade / swap methods ---

  getActiveSource(): 'A' | 'B' { return this.activeSource }

  getSourceGainA(): GainNode { return this.sourceGainA }

  getSourceGainB(): GainNode { return this.sourceGainB }

  /**
   * Crossfade from the active source to the inactive one over `duration` seconds.
   */
  crossfadeToInactive(duration: number): void {
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
    const t = this.audioContext.currentTime
    if (this.activeSource === 'A') {
      this.sourceGainA.gain.setValueAtTime(0, t)
      this.sourceGainB.gain.setValueAtTime(1, t)
      this.activeSource = 'B'
    } else {
      this.sourceGainB.gain.setValueAtTime(0, t)
      this.sourceGainA.gain.setValueAtTime(1, t)
      this.activeSource = 'A'
    }
  }

  destroy() {
    if (this.analysisInterval) {
      clearInterval(this.analysisInterval)
      this.analysisInterval = null
    }
    this.analysisWorker?.terminate()
    this.analysisWorker = null
    this.workerReady = false
    this.teardownWorklet()
    this.disconnect()
    if (this.audioContext.state !== 'closed') {
      this.audioContext.close()
    }
  }

  setVolume(volume: number) {
    if (this.passiveMode) return
    const v = Math.max(0, Math.min(1, volume))
    this.gainNode.gain.setTargetAtTime(v, this.audioContext.currentTime, 0.05)
  }

  setMuted(muted: boolean) {
    if (this.passiveMode) return
    this.gainNode.gain.setTargetAtTime(muted ? 0 : 1, this.audioContext.currentTime, 0.05)
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

  setStereoWidth(width: number) {
    if (this.passiveMode) return
    // width: 0 = mono, 1 = normal, >1 = expanded
    // mid = (L+R)/2, side = (L-R)/2
    // output L = mid + side * width, R = mid - side * width
    const mid = Math.max(0, 1 - Math.abs(width - 1))
    const side = width
    this.midGain.gain.setTargetAtTime(mid, this.audioContext.currentTime, this.SMOOTHING_TIME)
    this.sideGain.gain.setTargetAtTime(side, this.audioContext.currentTime, this.SMOOTHING_TIME)
  }

  setCrossfeed(amount: number) {
    if (this.passiveMode) return
    // amount: 0 = no crossfeed, 0.3 = light, 0.5 = normal, 0.7 = heavy
    this.crossfeedLeft.gain.setTargetAtTime(amount, this.audioContext.currentTime, this.SMOOTHING_TIME)
    this.crossfeedRight.gain.setTargetAtTime(amount, this.audioContext.currentTime, this.SMOOTHING_TIME)
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

  applyVolumeNormalization(targetLufs: number, currentLufs: number): number {
    const gainDb = Math.max(-20, Math.min(20, targetLufs - currentLufs))
    if (!this.passiveMode) {
      const normGainLinear = Math.pow(10, gainDb / 20)
      const safeGain = Math.min(2.0, normGainLinear)
      this.gainNode.gain.setTargetAtTime(safeGain, this.audioContext.currentTime, this.SMOOTHING_TIME)
    }
    return gainDb
  }

  getAnalysisData(): AnalysisData {
    if (!this.isPlaying) {
      return {
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

    if (this.passiveMode) {
      const now = performance.now()
      const leftLevel = Math.abs(Math.sin(now / 200)) * 60 + 20
      const rightLevel = Math.abs(Math.cos(now / 200)) * 60 + 20
      return {
        frequencyData: this.frequencyData,
        timeDomainData: this.timeDomainData,
        leftChannel: leftLevel,
        rightChannel: rightLevel,
        lufs: this.lufsBuffer.length > 0 ? this.lufsBuffer.reduce((a, b) => a + b, 0) / this.lufsBuffer.length : -20,
        peakFrequency: this.peakFrequency,
        spectralCentroid: this.spectralCentroid,
        spectralRolloff: this.spectralRolloff,
        spectralFlux: this.spectralFlux,
        spectralFlatness: this.spectralFlatness,
        rms: 0.1,
      }
    }

    if (this.analyzerNode) {
      this.analyzerNode.getByteFrequencyData(this.tempFrequencyData as Uint8Array<ArrayBuffer>)
      this.analyzerNode.getByteTimeDomainData(this.tempTimeDomainData as Uint8Array<ArrayBuffer>)

      for (let i = 0; i < this.tempFrequencyData.length && i < this.frequencyData.length; i++) {
        this.frequencyData[i] = this.tempFrequencyData[i]
      }
      for (let i = 0; i < this.tempTimeDomainData.length && i < this.timeDomainData.length; i++) {
        this.timeDomainData[i] = this.tempTimeDomainData[i]
      }

      const bufferLength = this.analyzerNode.frequencyBinCount
      let leftSum = 0, rightSum = 0
      for (let i = 0; i < bufferLength; i += 4) {
        const value = this.tempTimeDomainData[i] / 128.0 - 1.0
        if (i % 8 === 0) leftSum += value * value
        else rightSum += value * value
      }
      const leftLevel = Math.sqrt(leftSum / (bufferLength / 8)) * 100
      const rightLevel = Math.sqrt(rightSum / (bufferLength / 8)) * 100

      const lufs = this.lufsBuffer.length > 0
        ? this.lufsBuffer.reduce((a, b) => a + b, 0) / this.lufsBuffer.length
        : -30

      return {
        frequencyData: this.frequencyData,
        timeDomainData: this.timeDomainData,
        leftChannel: leftLevel,
        rightChannel: rightLevel,
        lufs,
        peakFrequency: this.peakFrequency,
        spectralCentroid: this.spectralCentroid,
        spectralRolloff: this.spectralRolloff,
        spectralFlux: this.spectralFlux,
        spectralFlatness: this.spectralFlatness,
        rms: Math.sqrt((leftSum + rightSum) / (bufferLength / 4)),
      }
    }

    return {
      frequencyData: this.frequencyData,
      timeDomainData: this.timeDomainData,
      leftChannel: 0,
      rightChannel: 0,
      lufs: -30,
      peakFrequency: 0,
      spectralCentroid: 0,
      spectralRolloff: 0,
      spectralFlux: 0,
      spectralFlatness: 0,
      rms: 0,
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
      workerReady: this.workerReady,
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
