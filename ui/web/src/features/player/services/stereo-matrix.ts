/** Independent two-channel stage: L' = direct L + cross R, R' = cross L + direct R. */
export class StereoMatrix {
  readonly input: GainNode
  readonly output: ChannelMergerNode
  private readonly direct: GainNode[]
  private readonly cross: GainNode[]

  constructor(context: BaseAudioContext) {
    // Speaker upmix duplicates mono into L/R before the discrete splitter.
    this.input = context.createGain()
    this.input.channelCount = 2
    this.input.channelCountMode = 'explicit'
    this.input.channelInterpretation = 'speakers'
    const splitter = context.createChannelSplitter(2)
    this.input.connect(splitter)
    this.output = context.createChannelMerger(2)
    this.direct = [context.createGain(), context.createGain()]
    this.cross = [context.createGain(), context.createGain()]
    for (let channel = 0; channel < 2; channel++) {
      this.cross[channel].gain.value = 0
      splitter.connect(this.direct[channel], channel)
      this.direct[channel].connect(this.output, 0, channel)
      splitter.connect(this.cross[channel], channel)
      this.cross[channel].connect(this.output, 0, 1 - channel)
    }
  }

  setCoefficients(direct: number, cross: number, time: number, smoothing: number) {
    for (const node of this.direct) node.gain.setTargetAtTime(direct, time, smoothing)
    for (const node of this.cross) node.gain.setTargetAtTime(cross, time, smoothing)
  }
}
