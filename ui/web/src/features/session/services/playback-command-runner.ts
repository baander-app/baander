import type { PlaybackCommandResource, PlaybackEndpointResource } from '@/shared/api-client/gen/endpoints'
import { getPlaybackAdapters, getPlaybackAdapter, type PlaybackExecutionAdapter, type PlaybackObservation } from './playback-execution'

export interface PlaybackAcknowledgement {
  commandId: string
  attemptId: string
  revision: number
  incarnationId: string
  epoch: number
  outcome: string
  position?: number
  volume?: number
}

const outcomes: Record<PlaybackCommandResource['type'], string> = {
  prepare: 'prepared', quiesce: 'quiesced', start: 'started', stop: 'stopped',
  queue: 'applied', play: 'playing', pause: 'paused', seek: 'applied', load: 'applied', volume: 'applied', observe: 'applied',
}

export class PlaybackCommandRunner {
  private endpoint: PlaybackEndpointResource | null = null
  private adapter: PlaybackExecutionAdapter | undefined
  private idleObservation: PlaybackObservation | null = null
  private lastCommand: PlaybackCommandResource | undefined
  private generation = 0
  private revision = 0
  private pending = new Map<string, Promise<PlaybackAcknowledgement | null>>()
  private effects = new Set<AbortController>()

  private readonly beforeExecute: (command: PlaybackCommandResource, signal: AbortSignal) => Promise<void>
  private readonly acknowledge: (ack: PlaybackAcknowledgement) => Promise<void>

  constructor(acknowledge: (ack: PlaybackAcknowledgement) => Promise<void>, beforeExecute: (command: PlaybackCommandResource, signal: AbortSignal) => Promise<void> = async () => {}) {
    this.acknowledge = acknowledge
    this.beforeExecute = beforeExecute
  }

  bind(endpoint: PlaybackEndpointResource): void {
    this.endpoint = endpoint
  }

  fence(revision: number): void {
    if (revision <= this.revision) return
    this.revision = revision
    this.pending.clear()
    for (const effect of this.effects) effect.abort()
  }

  async accept(command: PlaybackCommandResource, revision: number): Promise<void> {
    this.fence(revision)
    const endpoint = this.endpoint
    if (!endpoint || command.incarnationId !== endpoint.incarnationId || command.epoch !== endpoint.epoch
      || command.revision !== revision || revision !== this.revision || Date.parse(command.expiresAt) <= Date.now()) return
    let effect = this.pending.get(command.id)
    if (!effect) {
      effect = this.execute(command)
      this.pending.set(command.id, effect)
    }
    const ack = await effect
    if (ack && this.endpoint === endpoint && this.revision === command.revision) await this.acknowledge(ack)
  }

  private async execute(command: PlaybackCommandResource): Promise<PlaybackAcknowledgement | null> {
    const generation = this.generation
    const effect = new AbortController()
    this.effects.add(effect)
    const timeout = setTimeout(() => effect.abort(), Math.max(0, Date.parse(command.expiresAt) - Date.now()))
    const current = () => generation === this.generation && !effect.signal.aborted && this.revision === command.revision
    const ack: PlaybackAcknowledgement = {
      commandId: command.id, attemptId: command.attemptId, revision: command.revision,
      incarnationId: command.incarnationId, epoch: command.epoch, outcome: 'rejected',
    }
    try {
      await this.beforeExecute(command, effect.signal)
      if (!current()) return null
      const kind = command.payload.media?.kind
      if (!kind && ['prepare', 'start', 'stop', 'quiesce', 'observe'].includes(command.type)) {
        if ((command.type === 'prepare' || command.type === 'start') && command.payload.desiredState === 'playing') throw new Error('There is no media to play.')
        let observation: PlaybackObservation = this.idleObservation ?? { observedState: 'stopped', position: command.payload.position }
        if (this.adapter) {
          observation = await this.adapter.quiesce(effect.signal)
          if (observation.observedState !== 'stopped') observation = await this.adapter.execute({ ...(this.lastCommand ?? command), type: 'stop' }, effect.signal)
          if (observation.observedState !== 'stopped') throw new Error('Playback did not stop.')
        }
        if (!current()) return null
        this.adapter = undefined
        this.lastCommand = undefined
        observation = { ...observation, observedState: command.type === 'start' ? command.payload.desiredState : 'stopped' }
        this.idleObservation = observation
        return { ...ack, outcome: outcomes[command.type], position: observation.position }
      }
      const adapter = kind ? getPlaybackAdapter(kind) : this.adapter
      if (!adapter) throw new Error('This media cannot play in this browser.')
      if (this.adapter && this.adapter !== adapter) {
        await this.adapter.quiesce(effect.signal)
        if (!current()) return null
      }
      this.idleObservation = null
      this.adapter = adapter
      this.lastCommand = command
      let observation: PlaybackObservation
      if (command.type === 'prepare') {
        await adapter.prepare(command.payload, effect.signal)
        observation = adapter.observe()
      } else if (command.type === 'quiesce') {
        observation = await adapter.quiesce(effect.signal)
        if (observation.observedState !== 'stopped' && observation.observedState !== 'paused') throw new Error('Playback did not stop.')
      } else {
        observation = await adapter.execute(command, effect.signal)
        if ((command.type === 'play' || (command.type === 'start' && command.payload.desiredState === 'playing'))
          && observation.observedState !== 'playing') throw new Error('Playback did not start.')
        if ((command.type === 'load' || command.type === 'start') && observation.observedState !== command.payload.desiredState) throw new Error('Playback did not reach its requested state.')
        if (command.type === 'stop' && observation.observedState !== 'stopped') throw new Error('Playback did not stop.')
        if (command.type === 'pause' && observation.observedState !== 'paused') throw new Error('Playback did not pause.')
      }
      if (command.type === 'volume' && (observation.volume === undefined || !Number.isFinite(observation.volume) || observation.volume < 0 || observation.volume > 1)) throw new Error('The destination did not report its volume.')
      if (!current()) return null
      return { ...ack, outcome: outcomes[command.type], position: observation.position,
        ...(observation.volume === undefined ? {} : { volume: observation.volume }) }
    } catch {
      return current() ? ack : null
    } finally {
      clearTimeout(timeout)
      this.effects.delete(effect)
    }
  }

  fenceLocalExecution(): boolean {
    this.generation++
    for (const effect of this.effects) effect.abort()
    let safe = true
    for (const adapter of getPlaybackAdapters()) {
      const observation = adapter.localRecovery?.fence()
      if (!adapter.localRecovery?.eligible || !observation || !['paused', 'stopped'].includes(observation.observedState)) safe = false
    }
    return safe
  }

  observe(): PlaybackObservation | null {
    return this.adapter?.observe() ?? this.idleObservation
  }

  async disconnect(): Promise<PlaybackObservation | null> {
    this.generation++
    for (const effect of this.effects) effect.abort()
    this.effects.clear()
    this.endpoint = null
    this.pending.clear()
    const adapter = this.adapter
    const command = this.lastCommand
    this.adapter = undefined
    this.lastCommand = undefined
    const idle = this.idleObservation
    this.idleObservation = null
    if (!adapter) return idle ? { ...idle, observedState: 'stopped' } : null
    const signal = new AbortController().signal
    const observation = await adapter.quiesce(signal)
    return observation.observedState !== 'stopped' && command
      ? adapter.execute({ ...command, type: 'stop' }, signal)
      : observation
  }
}
