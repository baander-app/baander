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

Object.assign(window, { audioGraphFixture: { render } })
