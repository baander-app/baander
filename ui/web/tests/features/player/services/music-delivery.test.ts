import { beforeEach, describe, expect, it, vi } from 'vitest'
import { usePlayerStore } from '@/features/player/stores/player-store'
import { canSeekStream, isTrackStream, isTranscodedStream, isUnsupportedOriginalStream } from '@/features/player/stores/player-playback-runtime'
import { resolveMusicSource } from '@/features/player/services/music-delivery'
import { negotiateMediaDelivery } from '@/features/player/services/media-delivery'

vi.mock('@/features/player/services/media-delivery', () => ({
  detectDestinationCapabilities: vi.fn(async () => ({ containers: ['mp3'], audioCodecs: ['mp3'] })),
  negotiateMediaDelivery: vi.fn(),
  MediaCompatibilityError: class extends Error {},
}))
vi.mock('@/features/player/services/activity-service', () => ({ activityService: { recordPlay: vi.fn() } }))
vi.mock('sonner', () => ({ toast: { error: vi.fn(), info: vi.fn() } }))

const track = { publicId: 'song_delivery', title: 'Track', available: true }
const selection = { container: 'mp3', transport: 'file', videoStreamIndex: null, audioStreamIndex: 0 }
const decision = (kind: 'original' | 'cached' | 'copy') => ({
  kind,
  url: `https://baander.app/api/stream/original/track?id=song_delivery&owner=listener${kind === 'copy' ? '&format=aac&mode=copy&container=mp4&index=0' : kind === 'cached' ? '&format=mp3&bitrate=192' : ''}&expires=9999999999&signature=signed`,
  selection, reason: null, requestId: null, jobId: null,
})

beforeEach(() => {
  vi.clearAllMocks()
  usePlayerStore.setState({ queue: [], currentTrack: null, currentIndex: -1, isPlaying: false, audioElement: null })
})

describe('music delivery integration', () => {
  it.each(['original', 'cached', 'copy'] as const)('assigns the signed %s decision before playing', async kind => {
    vi.mocked(negotiateMediaDelivery).mockResolvedValue(decision(kind))
    const audio = { src: '', play: vi.fn(async () => {}), pause: vi.fn(), paused: false, ended: false } as unknown as HTMLAudioElement
    usePlayerStore.getState().runtimeActions.setAudioElement(audio)
    usePlayerStore.getState().runtimeActions.playTrack(track)
    await vi.waitFor(() => expect(audio.src).toBe(decision(kind).url))
    expect(audio.play).toHaveBeenCalledOnce()
  })

  it('does not play or assign a conversion URL when incompatible', async () => {
    vi.mocked(negotiateMediaDelivery).mockResolvedValue({ kind: 'incompatible', url: null, reason: 'audio_codec_unsupported', selection: null, requestId: null, jobId: null })
    const audio = { src: '', play: vi.fn(async () => {}), pause: vi.fn() } as unknown as HTMLAudioElement
    usePlayerStore.getState().runtimeActions.setAudioElement(audio)
    usePlayerStore.getState().runtimeActions.playTrack(track)
    await vi.waitFor(() => expect(usePlayerStore.getState().isPlaying).toBe(false))
    expect(audio.src).toBe('')
    expect(audio.play).not.toHaveBeenCalled()
  })

  it('does not classify signed stream copies as re-encoding', () => {
    expect(isTranscodedStream('https://baander.app/api/stream/original/track?id=song_delivery&format=mp4&mode=copy&signature=signed')).toBe(false)
  })
})

describe('selection ownership', () => {
  it('ignores an older negotiation completed after the next track', async () => {
    let finish!: (value: ReturnType<typeof decision>) => void
    vi.mocked(negotiateMediaDelivery).mockImplementationOnce(() => new Promise(resolve => { finish = resolve }))
    vi.mocked(negotiateMediaDelivery).mockResolvedValueOnce({ ...decision('copy'), url: 'https://baander.app/api/stream/original/track?id=new&mode=copy&signature=new' })
    const audio = { src: '', play: vi.fn(async () => {}), pause: vi.fn(), paused: false, ended: false } as unknown as HTMLAudioElement
    usePlayerStore.getState().runtimeActions.setAudioElement(audio)
    usePlayerStore.getState().runtimeActions.playTrack(track)
    await vi.waitFor(() => expect(negotiateMediaDelivery).toHaveBeenCalledTimes(1))
    usePlayerStore.getState().runtimeActions.playTrack({ ...track, publicId: 'new' })
    await vi.waitFor(() => expect(audio.src).toContain('id=new'))
    finish(decision('original'))
    await Promise.resolve()
    expect(audio.src).toContain('id=new')
    expect(audio.play).toHaveBeenCalledTimes(1)
  })
})

it('waits for a copy to expose its actual duration before seeking', () => {
  const audio = { src: 'https://baander.app/api/stream/original/track?id=copy&mode=copy&format=mp4', duration: Infinity } as HTMLAudioElement
  expect(canSeekStream(audio)).toBe(false)
  Object.defineProperty(audio, 'duration', { value: 120 })
  expect(canSeekStream(audio)).toBe(true)
})

it('rejects a repeated failing source even when its signature changes', async () => {
  vi.mocked(negotiateMediaDelivery).mockResolvedValue(decision('original'))
  const audio = {} as HTMLAudioElement
  await expect(resolveMusicSource(audio, track.publicId, new AbortController().signal, undefined,
    'https://baander.app/api/stream/original/track?id=song_delivery&owner=listener&expires=123&signature=expired'))
    .rejects.toThrow('browser_rejected_source')
})

it('reports native source failure and uses the requested format after an encoded decision', async () => {
  vi.mocked(negotiateMediaDelivery).mockResolvedValue({ kind: 'encoded', url: null, reason: 'audio_codec_unsupported', selection: null, requestId: null, jobId: null })
  const audio = {} as HTMLAudioElement
  await expect(resolveMusicSource(audio, track.publicId, new AbortController().signal, 'aac', decision('original').url))
    .resolves.toBe('/api/stream/track?id=song_delivery&format=aac')
  expect(negotiateMediaDelivery).toHaveBeenCalledWith(
    expect.objectContaining({ capabilities: expect.objectContaining({ sourcePlaybackFailed: true }) }),
    expect.any(AbortSignal),
  )
})

it.each(['/api/stream/track', '/api/stream/original/track'])('recognizes track identity and native rejection on %s', path => {
  const src = `https://baander.app${path}?id=song_delivery&owner=listener&signature=signed`
  const audio = { src, error: { code: 4, MEDIA_ERR_SRC_NOT_SUPPORTED: 4 } } as HTMLAudioElement
  expect(isTrackStream(src, track.publicId)).toBe(true)
  expect(isTrackStream(src, 'another_track')).toBe(false)
  expect(isUnsupportedOriginalStream(audio, track.publicId)).toBe(true)
  expect(isUnsupportedOriginalStream({ ...audio, src: '/api/stream/track?id=song_delivery&format=mp3' } as HTMLAudioElement, track.publicId)).toBe(false)
})
