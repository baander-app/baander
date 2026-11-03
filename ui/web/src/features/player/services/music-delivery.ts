import { detectDestinationCapabilities, MediaCompatibilityError, negotiateMediaDelivery } from './media-delivery'

const sources = new WeakMap<HTMLAudioElement, { id: string; url: string }>()

export type TranscodeFormat = 'opus' | 'aac' | 'mp3'

/** Stable preference order; each MIME type matches the server's transcoded response. */
const TRANSCODE_FORMATS: ReadonlyArray<readonly [TranscodeFormat, string]> = [
  ['opus', 'audio/ogg; codecs=opus'],
  ['aac', 'audio/aac'],
  ['mp3', 'audio/mpeg'],
]

/** Narrows a `format` query value to a transcoded format; anything else is undefined. */
export function parseTranscodeFormat(value: string | null): TranscodeFormat | undefined {
  return TRANSCODE_FORMATS.find(([format]) => format === value)?.[0]
}

/** MP3 remains the last resort when the browser reports no transcoded format as playable. */
export function chooseTranscodeFormat(element: HTMLAudioElement): TranscodeFormat {
  return TRANSCODE_FORMATS.find(([, type]) => element.canPlayType(type) !== '')?.[0] ?? 'mp3'
}

/** Without a bitrate the server applies its own transcoding cap. */
export function buildStreamUrl(publicId: string, format?: TranscodeFormat): string {
  const url = `/api/stream/track?id=${encodeURIComponent(publicId)}`
  return format ? `${url}&format=${format}` : url
}

export async function resolveMusicSource(
  element: HTMLAudioElement,
  id: string,
  signal: AbortSignal,
  format?: TranscodeFormat,
  failedSource?: string,
): Promise<string> {
  const capabilities = await detectDestinationCapabilities(element, 'track')
  signal.throwIfAborted()
  if (failedSource) capabilities.sourcePlaybackFailed = true
  const decision = await negotiateMediaDelivery({ kind: 'track', id, capabilities }, signal)
  signal.throwIfAborted()
  if (decision.kind === 'incompatible') throw new MediaCompatibilityError(decision.reason)
  const url = decision.kind === 'encoded'
    ? buildStreamUrl(id, format ?? chooseTranscodeFormat(element))
    : decision.url
  if (failedSource && sourceIdentity(url) === sourceIdentity(failedSource)) {
    throw new MediaCompatibilityError('browser_rejected_source')
  }
  return url
}

export function assignMusicSource(element: HTMLAudioElement, id: string, url: string): void {
  element.src = url
  sources.set(element, { id, url: element.src })
}

export function isAssignedMusicSource(element: HTMLAudioElement, id: string): boolean {
  const source = sources.get(element)
  return source?.id === id && source.url === element.src
}

function sourceIdentity(source: string): string {
  const url = new URL(source, document.baseURI)
  return JSON.stringify([url.origin, url.pathname, url.searchParams.get('id'), url.searchParams.get('format'), url.searchParams.get('mode')])
}
