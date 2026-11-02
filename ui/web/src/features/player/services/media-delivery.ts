import i18n from 'i18next'
import { postStreamDelivery, type DeliveryRequest } from '@/shared/api-client/gen/endpoints'
import translations from '@/shared/i18n/locales/en/translation.json'

export type DestinationCapabilities = Omit<DeliveryRequest, 'kind' | 'id' | 'requestId'>

export interface MediaDeliverySelection {
  container: string
  transport: 'file' | 'hls' | 'dash'
  videoStreamIndex: number | null
  audioStreamIndex: number | null
}

interface DeliveryIdentity {
  selection: MediaDeliverySelection | null
  requestId: string | null
  jobId: string | null
}

export type NegotiatedDelivery = DeliveryIdentity & (
  | { kind: 'original' | 'cached' | 'copy'; url: string; reason: string | null }
  | { kind: 'encoded'; url: null; reason: string | null }
  | { kind: 'incompatible'; url: null; reason: string }
)

export class MediaCompatibilityError extends Error {
  readonly reason: string

  constructor(reason: string) {
    const messages = translations.player.compatibility
    const key = Object.hasOwn(messages, reason) ? reason as keyof typeof messages : 'unknown'
    super(i18n.t(`player.compatibility.${key}`, { defaultValue: messages[key] }) || messages[key])
    this.name = 'MediaCompatibilityError'
    this.reason = key
  }
}

const audioFormats = [
  ['audio/mpeg', 'mp3', 'mp3'],
  ['audio/flac', 'flac', 'flac'],
  ['audio/ogg; codecs="opus"', 'ogg', 'opus'],
  ['audio/ogg; codecs="vorbis"', 'ogg', 'vorbis'],
  ['audio/mp4; codecs="mp4a.40.2"', 'mp4', 'aac'],
  ['audio/aac', 'aac', 'aac'],
  ['audio/webm; codecs="opus"', 'webm', 'opus'],
  ['audio/wav; codecs="1"', 'wav', 'pcm_s16le'],
] as const

const videoFormats = [
  ['video/mp4; codecs="avc1.42E01E"', 'mp4', 'h264'],
  ['video/mp4; codecs="hvc1.1.6.L93.B0"', 'mp4', 'hevc'],
  ['video/mp4; codecs="av01.0.04M.08"', 'mp4', 'av1'],
  ['video/webm; codecs="vp8"', 'webm', 'vp8'],
  ['video/webm; codecs="vp09.00.10.08"', 'webm', 'vp9'],
] as const

export async function detectDestinationCapabilities(
  element: HTMLAudioElement | HTMLVideoElement,
  kind: 'track' | 'video',
): Promise<DestinationCapabilities> {
  const supports = (mime: string) => {
    const support = element.canPlayType(mime)
    return support === 'maybe' || support === 'probably'
  }
  const audio = audioFormats.filter(([mime]) => supports(mime))
  const video = kind === 'video' ? videoFormats.filter(([mime]) => supports(mime)) : []
  const transports = ['file']
  if (kind === 'video') {
    let hlsSupported = supports('application/vnd.apple.mpegurl')
    if (!hlsSupported) {
      const { default: Hls } = await import('hls.js')
      hlsSupported = Hls.isSupported()
    }
    if (hlsSupported) transports.push('hls')
  }
  return {
    containers: [...new Set((kind === 'track' ? audio : video).map(([, container]) => container))],
    audioCodecs: [...new Set(audio.map(([, , codec]) => codec))],
    videoCodecs: [...new Set(video.map(([, , codec]) => codec))],
    transports,
    maxBitDepth: 8,
    supportsHdr: false,
    pixelFormats: ['yuv420p', 'yuvj420p', 'nv12'],
  }
}

function invalidResponse(): never {
  throw new Error('Invalid media delivery response')
}

function readSelection(value: unknown): MediaDeliverySelection | null {
  if (value == null) return null
  if (typeof value !== 'object') return invalidResponse()
  const selection = value as Record<string, unknown>
  const streamIndex = (index: unknown): index is number | null => index === null
    || (typeof index === 'number' && Number.isInteger(index) && index >= 0)
  if (typeof selection.container !== 'string' || !selection.container
    || !['file', 'hls', 'dash'].includes(String(selection.transport))
    || !streamIndex(selection.videoStreamIndex) || !streamIndex(selection.audioStreamIndex)) {
    return invalidResponse()
  }
  return selection as unknown as MediaDeliverySelection
}

export async function negotiateMediaDelivery(
  input: { kind: 'track' | 'video'; id: string; capabilities: DestinationCapabilities; requestId?: string },
  signal: AbortSignal,
): Promise<NegotiatedDelivery> {
  signal.throwIfAborted()
  if (input.kind === 'video' && !input.requestId) throw new Error('Video delivery requires a client session ID')
  if (input.capabilities.containers.length === 0) {
    return { kind: 'incompatible', reason: 'destination_media_unsupported', url: null,
      selection: null, requestId: input.requestId ?? null, jobId: null }
  }
  const response = await postStreamDelivery({
    ...input.capabilities,
    kind: input.kind,
    id: input.id,
    ...(input.requestId ? { requestId: input.requestId } : {}),
  }, { signal })
  signal.throwIfAborted()
  if (!response || (input.kind === 'video' && response.requestId !== input.requestId)) return invalidResponse()
  const selection = readSelection(response.selection)
  const identity: DeliveryIdentity = {
    selection, requestId: response.requestId ?? null, jobId: response.jobId ?? null,
  }
  const reason = typeof response.reason === 'string' ? response.reason : null
  switch (response.kind) {
    case 'incompatible':
      return { ...identity, kind: response.kind, reason: reason || 'unknown', url: null }
    case 'encoded':
      return { ...identity, kind: response.kind, reason, url: null }
    case 'original':
    case 'cached':
    case 'copy':
      if (typeof response.url !== 'string' || !response.url.trim()
        || (response.kind === 'copy' && (!selection || (input.kind === 'video' && !identity.jobId)))
        || (response.kind === 'cached' && input.kind === 'video' && !selection)) return invalidResponse()
      return { ...identity, kind: response.kind, reason, url: response.url }
    default:
      return invalidResponse()
  }
}
