import { beforeEach, describe, expect, it, vi } from 'vitest'
import type { DeliveryResource } from '@/shared/api-client/gen/endpoints'
import {
  detectDestinationCapabilities,
  MediaCompatibilityError,
  negotiateMediaDelivery,
} from '../media-delivery'

const { deliver, supportsHls } = vi.hoisted(() => ({
  deliver: vi.fn(),
  supportsHls: vi.fn(() => false),
}))

vi.mock('@/shared/api-client/gen/endpoints', () => ({ postStreamDelivery: deliver }))
vi.mock('hls.js', () => ({ default: { isSupported: supportsHls } }))

const requestId = '019a4330-e950-7000-8000-000000000001'
const capabilities = { containers: ['mp4'], videoCodecs: ['h264'], audioCodecs: ['aac'], transports: ['file', 'hls'] }
const video = { kind: 'video' as const, id: 'video', capabilities, requestId }
const selection = { container: 'mp4', transport: 'hls', videoStreamIndex: 0, audioStreamIndex: 1 }

function element(kind: 'audio' | 'video', accepted: string[]) {
  const media = document.createElement(kind)
  vi.spyOn(media, 'canPlayType').mockImplementation(type => accepted.includes(type) ? 'probably' : '')
  return media
}

beforeEach(() => {
  deliver.mockReset()
  supportsHls.mockReset().mockReturnValue(false)
})

describe('destination capabilities', () => {
  it('reports the receiving audio element capabilities without guessing a fallback', async () => {
    const receiver = element('audio', ['audio/mpeg'])
    const result = await detectDestinationCapabilities(receiver, 'track')
    expect(result.containers).toEqual(['mp3'])
    expect(result.audioCodecs).toEqual(['mp3'])
    expect(result.videoCodecs).toEqual([])
    expect(result.transports).toEqual(['file'])
    expect(supportsHls).not.toHaveBeenCalled()
  })

  it('does not reuse another destination capability result', async () => {
    const first = await detectDestinationCapabilities(element('audio', ['audio/mpeg']), 'track')
    const second = await detectDestinationCapabilities(element('audio', ['audio/flac']), 'track')
    expect(first.audioCodecs).toEqual(['mp3'])
    expect(second.audioCodecs).toEqual(['flac'])
    expect(second.containers).toEqual(['flac'])
  })

  it.each(['native', 'media-source'])('advertises HLS only through a supported %s player', async support => {
    supportsHls.mockReturnValue(support === 'media-source')
    const accepted = ['video/mp4; codecs="avc1.42E01E"', 'audio/mp4; codecs="mp4a.40.2"']
    if (support === 'native') accepted.push('application/vnd.apple.mpegurl')
    const result = await detectDestinationCapabilities(element('video', accepted), 'video')
    expect(result.containers).toEqual(['mp4'])
    expect(result.videoCodecs).toEqual(['h264'])
    expect(result.audioCodecs).toEqual(['aac'])
    expect(result.transports).toEqual(['file', 'hls'])
    expect(result.maxBitDepth).toBe(8)
    expect(result.supportsHdr).toBe(false)
  })

  it('does not advertise adaptive playback when neither player supports it', async () => {
    const result = await detectDestinationCapabilities(element('video', ['video/mp4; codecs="avc1.42E01E"']), 'video')
    expect(result.transports).toEqual(['file'])
  })
})

describe('media delivery negotiation', () => {
  it('forwards the stable video identity and actual destination without changing the response envelope', async () => {
    deliver.mockResolvedValue({ kind: 'copy', url: '/api/transcode/video/master.m3u8', requestId, jobId: 'owned-job', selection })
    const signal = new AbortController().signal
    const result = await negotiateMediaDelivery(video, signal)
    expect(deliver).toHaveBeenCalledExactlyOnceWith({ kind: 'video', id: 'video', requestId, ...capabilities }, { signal })
    expect(result).toEqual({ kind: 'copy', url: '/api/transcode/video/master.m3u8', requestId, jobId: 'owned-job', selection, reason: null })
  })

  it.each(['original', 'cached', 'copy'] as const)('preserves a signed %s audio URL without requiring a video job', async kind => {
    const url = '/api/stream/original/track?id=song&sig=capability&exp=2000000000'
    deliver.mockResolvedValue({ kind, url, selection: kind === 'copy' ? { ...selection, transport: 'file', videoStreamIndex: null } : null })
    const result = await negotiateMediaDelivery({ kind: 'track', id: 'song', capabilities }, new AbortController().signal)
    expect(result.kind).toBe(kind)
    expect(result.url).toBe(url)
    expect(result.requestId).toBeNull()
    expect(result.jobId).toBeNull()
    expect(deliver.mock.calls[0][0]).not.toHaveProperty('requestId')
  })

  it('accepts the full-profile encoded decision without a URL', async () => {
    deliver.mockResolvedValue({ kind: 'encoded', reason: 'audio_codec_unsupported', requestId })
    expect(await negotiateMediaDelivery(video, new AbortController().signal)).toEqual({
      kind: 'encoded', reason: 'audio_codec_unsupported', url: null, requestId, jobId: null, selection: null,
    })
  })

  it('retains a terminal incompatibility reason without trying a conversion', async () => {
    deliver.mockResolvedValue({ kind: 'incompatible', reason: 'video_hdr_unsupported', requestId })
    const result = await negotiateMediaDelivery(video, new AbortController().signal)
    expect(result.kind).toBe('incompatible')
    expect(result.reason).toBe('video_hdr_unsupported')
    expect(result.url).toBeNull()
    expect(deliver).toHaveBeenCalledTimes(1)
  })

  it('stops locally when the destination reports no supported containers', async () => {
    const result = await negotiateMediaDelivery({ kind: 'track', id: 'song', capabilities: { containers: [], audioCodecs: [] } }, new AbortController().signal)
    expect(result.kind).toBe('incompatible')
    expect(result.reason).toBe('destination_media_unsupported')
    expect(deliver).not.toHaveBeenCalled()
  })

  it.each([
    ['another client', { kind: 'original', url: '/file', requestId: '019a4330-e950-7000-8000-000000000002' }],
    ['missing client', { kind: 'original', url: '/file' }],
    ['missing copy job', { kind: 'copy', url: '/master.m3u8', requestId, selection }],
    ['missing media URL', { kind: 'original', requestId }],
    ['missing copy selection', { kind: 'copy', url: '/master.m3u8', requestId, jobId: 'owned-job' }],
    ['missing cached video selection', { kind: 'cached', url: '/master.m3u8', requestId }],
    ['unknown transport', { kind: 'copy', url: '/master.m3u8', requestId, jobId: 'owned-job', selection: { ...selection, transport: 'unknown' } }],
    ['unknown decision', { kind: 'guess', requestId }],
  ])('rejects an unusable response: %s', async (_label, response) => {
    deliver.mockResolvedValue(response)
    await expect(negotiateMediaDelivery(video, new AbortController().signal)).rejects.toThrow('Invalid media delivery response')
  })

  it('does not start a request after cancellation', async () => {
    const controller = new AbortController()
    controller.abort()
    await expect(negotiateMediaDelivery(video, controller.signal)).rejects.toMatchObject({ name: 'AbortError' })
    expect(deliver).not.toHaveBeenCalled()
  })

  it('rejects late completion after cancellation even if the transport resolves', async () => {
    let resolve!: (value: DeliveryResource) => void
    deliver.mockImplementation(() => new Promise<DeliveryResource>(done => { resolve = done }))
    const controller = new AbortController()
    const pending = negotiateMediaDelivery(video, controller.signal)
    controller.abort()
    resolve({ kind: 'original', url: '/file', requestId })
    await expect(pending).rejects.toMatchObject({ name: 'AbortError' })
  })

  it('presents compatibility errors as readable explanations while retaining the reason', () => {
    const error = new MediaCompatibilityError('audio_codec_unsupported')
    expect(error.reason).toBe('audio_codec_unsupported')
    expect(error.message).toMatch(/audio/i)
    expect(error.message).not.toContain('audio_codec_unsupported')
    expect(new MediaCompatibilityError('future_reason').message).toMatch(/play/i)
  })
})
