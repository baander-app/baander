import { getDynamics, getLoudness, getSpectralFeatures, getWasmUrl, getAudioWorkletUrl } from './wasm-loader'
import type { LoudnessR128API, DynamicsMeterAPI, SpectralFeaturesApi } from './wasm-types'
import { createLogger } from '@/shared/lib/logger'
import { StereoMatrix } from './stereo-matrix'

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
  private rebuildGain!: GainNode
  private analysisSink!: GainNode
  private rebuildTimer: ReturnType<typeof setTimeout> | null = null
  private destroyed = false
  private workletGeneration = 0
  private workletAbort = new AbortController()
  private readonly workerAbort = new AbortController()
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
  private programmeGeneration = 0
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
      const [loudness, dynamics, spectral] = await Promise.all([
        getLoudness(),
        getDynamics(),
        getSpectralFeatures(),
      ])

      if (this.destroyed) return
      this.loudnessAPI = loudness
      this.dynamicsAPI = dynamics
      this.spectralAPI = spectral
      this.loudnessAPI.init(this.audioContext.sampleRate, 4)
      this.dynamicsAPI.init(10, 100, this.audioContext.sampleRate)
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
        } else if (msg.type === 'spectrum'
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
    this.rebuildGain = this.audioContext.createGain()
    this.analysisSink = this.audioContext.createGain()
    this.analysisSink.gain.value = 0
    this.analysisSink.connect(this.audioContext.destination)
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
    this.chainEntry ??= this.gainNode
    currentNode.connect(this.gainNode)
  }

  // --- Worker ---

  private initializeWorker() {
    try {
      const worker = new Worker(getAudioWorkletUrl('audio-analysis-worker.js'))
      this.analysisWorker = worker

      this.analysisWorker.onmessage = (e: MessageEvent) => {
        if (this.destroyed || this.analysisWorker !== worker || !this.isConnected || !this.isPlaying) return
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
        if (this.destroyed || this.analysisWorker !== worker) return
        worker.onmessage = null
        worker.onerror = null
        worker.terminate()
        this.workerAbort.abort()
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
      if (this.analysisWorker) {
        this.analysisWorker.onmessage = null
        this.analysisWorker.onerror = null
        this.analysisWorker.terminate()
      }
      this.workerAbort.abort()
      this.workerReady = false
      this.analysisWorker = null
      this.setupFallbackAnalysis()
    }
  }

  private async sendSpectralWasmToWorker() {
    const worker = this.analysisWorker
    try {
      const response = await fetch(getWasmUrl('spectral_features.wasm'), { signal: this.workerAbort.signal })
      if (!response.ok) throw new Error(`Spectral WASM request failed: ${response.status}`)
      const spectralWasm = await response.arrayBuffer()
      if (!this.destroyed && this.analysisWorker === worker) {
        worker?.postMessage({ type: 'init-spectral-wasm', spectralWasm })
      }
    } catch (error) {
      if (this.destroyed || this.analysisWorker !== worker) return
      console.warn('[AudioProcessor] Failed to send spectral WASM to worker:', error)
    }
  }

  // --- Analysis ---

  private setupFallbackAnalysis() {
    if (this.destroyed || !this.isConnected || !this.isPlaying) return
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
          const msg = event.data as { type: string; lufs?: number; programmeGeneration?: number }
          if (msg.type === 'request-dsp-init') {
            void this.sendDSPToWorklet(node, generation)
          } else if (msg.type === 'analysis' && msg.programmeGeneration === this.programmeGeneration) {
            if (typeof msg.lufs === 'number' && Number.isFinite(msg.lufs)) this.lufsBuffer.push(msg.lufs)
            if (this.lufsBuffer.length > this.LUFS_WINDOW_SIZE) this.lufsBuffer.shift()
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
    this.lufsBuffer = []
    this.audioWorkletNode?.port.postMessage({
      type: 'reset-programme', programmeGeneration: this.programmeGeneration,
    })
  }

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
    if (this.destroyed) return

    // Guard: skip if already connected to the same pair
    if (
      this.isConnected &&
      this.audioElement === elementA &&
      this.sourceNodeA && this.sourceNodeB
    ) return

    this.teardownWorklet()
    if (this.analysisInterval) {
      clearInterval(this.analysisInterval)
      this.analysisInterval = null
    }

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
    this.cancelCrossfade()

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
    this.workerAbort.abort()
    if (this.analysisWorker) {
      this.analysisWorker.onmessage = null
      this.analysisWorker.onerror = null
    }
    if (this.rebuildTimer !== null) {
      clearTimeout(this.rebuildTimer)
      this.rebuildTimer = null
    }
    if (this.analysisInterval) {
      clearInterval(this.analysisInterval)
      this.analysisInterval = null
    }
    this.analysisWorker?.terminate()
    this.analysisWorker = null
    this.workerReady = false
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
