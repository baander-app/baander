import type { PlaybackCommandResource, PlaybackMediaResource } from '@/shared/api-client/gen/endpoints'

export interface PlaybackObservation {
  observedState: 'playing' | 'paused' | 'stopped' | 'unknown'
  position: number
  volume?: number
}

export interface PlaybackExecutionAdapter {
  readonly kind: 'song' | 'video'
  readonly supportsVolume: boolean
  readonly localRecovery?: {
    readonly eligible: boolean
    fence(): PlaybackObservation | null
    rearmAfterRegistration(): void
  }
  prepare(payload: PlaybackCommandResource['payload'], signal: AbortSignal): Promise<void>
  execute(command: PlaybackCommandResource, signal: AbortSignal): Promise<PlaybackObservation>
  quiesce(signal: AbortSignal): Promise<PlaybackObservation>
  observe(): PlaybackObservation
}

export type PlaybackIntent =
  | { action: 'load'; media: PlaybackMediaResource; queue?: string[]; currentTrackIndex?: number; position?: number; desiredState?: 'playing' | 'paused' | 'stopped' }
  | { action: 'play' | 'pause' | 'stop' }
  | { action: 'stop-local-video'; incarnationId: string; videoId: string }
  | { action: 'next' | 'previous'; navigation?: { shuffle: boolean; repeat: 'off' | 'all' | 'one'; shuffleBag: number[]; unavailablePublicIds: string[] } }
  | { action: 'queue'; queue: string[]; currentTrackIndex: number }
  | { action: 'queue'; operation: PlaybackQueueOperation }
  | { action: 'seek'; position: number }
  | { action: 'volume'; volume: number }

export type PlaybackQueueOperation =
  | { type: 'clear' }
  | { type: 'append'; tracks: string[] }
  | { type: 'insert'; tracks: string[]; afterPublicId: string | null; afterOccurrence?: number }
  | { type: 'remove'; publicId: string; occurrence?: number }
  | { type: 'reorder'; publicId: string; occurrence?: number; beforePublicId: string | null; beforeOccurrence?: number }

const adapters = new Map<'song' | 'video', PlaybackExecutionAdapter>()
let intentHandler: ((intent: PlaybackIntent) => Promise<void>) | undefined

export function registerPlaybackAdapter(adapter: PlaybackExecutionAdapter): () => void {
  adapters.set(adapter.kind, adapter)
  return () => {
    if (adapters.get(adapter.kind) === adapter) adapters.delete(adapter.kind)
  }
}

export function getPlaybackAdapter(kind: 'song' | 'video'): PlaybackExecutionAdapter | undefined {
  return adapters.get(kind)
}

export function requestPlayback(intent: PlaybackIntent): Promise<void> {
  return intentHandler ? intentHandler(intent) : Promise.reject(new Error('Playback connection is unavailable.'))
}

export function registerPlaybackIntentHandler(handler: (intent: PlaybackIntent) => Promise<void>): () => void {
  intentHandler = handler
  return () => {
    if (intentHandler === handler) intentHandler = undefined
  }
}


export function getPlaybackAdapters(): PlaybackExecutionAdapter[] {
  return [...adapters.values()]
}
