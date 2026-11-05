import type { PlaybackCommandResource, PlaybackCommandResourcePayload } from '@/shared/api-client/gen/endpoints'
import { PlaybackCommandRunner, type PlaybackAcknowledgement } from '@/features/session/services/playback-command-runner'
import { registerPlaybackIntentHandler, type PlaybackIntent } from '@/features/session/services/playback-execution'
import { MediaCompatibilityError } from '@/features/player/services/media-delivery'

type Control = Extract<PlaybackIntent, { action: 'load' | 'play' | 'pause' | 'stop' | 'seek' | 'volume' }>

/** The server keeps a runner command this long before the runner must give it up. */
const COMMAND_LIFETIME_MS = 20_000
const endpoint = { destinationId: 'fixture-destination', incarnationId: 'fixture-browser', epoch: 1 }

/**
 * Stands in for the server's listening session with this browser as its active destination.
 * Each control becomes the same runner command the server would issue, and requests run one
 * at a time because the browser waits for each result before it sends the next control.
 */
export function startPlaybackSession(): () => void {
  const session: Omit<PlaybackCommandResourcePayload, 'volume'> = {
    media: null,
    queue: [],
    currentTrackIndex: 0,
    position: 0,
    desiredState: 'stopped',
  }
  let revision = 0
  const acknowledgements = new Map<string, PlaybackAcknowledgement>()
  const runner = new PlaybackCommandRunner(async ack => {
    acknowledgements.set(ack.commandId, ack)
  })
  runner.bind(endpoint)

  const navigate = (intent: Extract<PlaybackIntent, { action: 'next' | 'previous' }>): Control => {
    if (intent.action === 'previous' && session.position > 3) {
      return { action: 'seek', position: 0 }
    }
    const unavailable = new Set(intent.navigation?.unavailablePublicIds)
    const playable = (index: number) => session.queue[index] !== undefined && !unavailable.has(session.queue[index])
    let index: number | undefined
    if (intent.action === 'previous') {
      for (let offset = 1; offset <= session.queue.length; offset++) {
        const previous = (session.currentTrackIndex - offset + session.queue.length) % session.queue.length
        if (playable(previous)) {
          index = previous
          break
        }
      }
    } else {
      const order = intent.navigation?.shuffle ? intent.navigation.shuffleBag : session.queue.map((_, position) => position)
      const position = order.indexOf(session.currentTrackIndex)
      const following = order.slice(position + 1)
      const candidates = intent.navigation?.repeat === 'all' ? [...following, ...order.slice(0, position + 1)] : following
      index = candidates.find(playable)
    }
    if (index === undefined) return { action: 'stop' }
    return {
      action: 'load',
      media: { kind: 'song', publicId: session.queue[index] },
      queue: session.queue,
      currentTrackIndex: index,
      position: 0,
      desiredState: 'playing',
    }
  }

  const toControl = (intent: PlaybackIntent): Control => {
    switch (intent.action) {
      case 'next':
      case 'previous':
        return navigate(intent)
      case 'queue':
      case 'stop-local-video':
        throw new Error(`The playback fixture does not support ${intent.action} controls.`)
      default:
        return intent
    }
  }

  const control = async (requested: PlaybackIntent) => {
    let intent = toControl(requested)
    if (intent.action === 'load' && intent.queue === undefined) {
      const queue = [...session.queue]
      let index = queue[session.currentTrackIndex] === intent.media.publicId ? session.currentTrackIndex : queue.indexOf(intent.media.publicId)
      if (index < 0) {
        index = queue.length
        queue.push(intent.media.publicId)
      }
      intent = { ...intent, queue, currentTrackIndex: index }
    }
    let extra: Partial<PlaybackCommandResourcePayload> = {}
    if (intent.action === 'volume') {
      extra = { volume: intent.volume }
    } else {
      if (intent.action === 'load') {
        session.media = intent.media
        session.queue = intent.queue ?? session.queue
        session.currentTrackIndex = intent.currentTrackIndex ?? session.currentTrackIndex
        session.desiredState = intent.desiredState ?? session.desiredState
      }
      if ((intent.action === 'load' || intent.action === 'seek') && intent.position !== undefined) session.position = intent.position
      if (intent.action === 'play') session.desiredState = 'playing'
      if (intent.action === 'pause') session.desiredState = 'paused'
      if (intent.action === 'stop') session.desiredState = 'stopped'
    }
    revision++
    const id = crypto.randomUUID()
    const command: PlaybackCommandResource = {
      id,
      attemptId: id,
      revision,
      incarnationId: endpoint.incarnationId,
      epoch: endpoint.epoch,
      type: intent.action,
      expiresAt: new Date(Date.now() + COMMAND_LIFETIME_MS).toISOString(),
      payload: { ...extra, ...session },
    }
    try {
      await runner.accept(command, revision)
      const ack = acknowledgements.get(id)
      if (!ack) throw new Error('The playback request was superseded.')
      if (ack.outcome === 'rejected') {
        throw ack.rejectionReason ? new MediaCompatibilityError(ack.rejectionReason) : new Error('The destination rejected the playback request.')
      }
      if (intent.action !== 'stop' && ack.position !== undefined) session.position = ack.position
    } finally {
      acknowledgements.delete(id)
    }
  }

  let tail = Promise.resolve()
  return registerPlaybackIntentHandler(intent => {
    const captured = structuredClone(intent)
    const result = tail.then(() => control(captured))
    tail = result.catch(() => {})
    return result
  })
}
