import { expect, it, vi } from 'vitest'
import { postStreamDelivery } from '@/shared/api-client/gen/endpoints'
import { resolveMusicSource } from '@/features/player/services/music-delivery'

vi.mock('@/shared/api-client/gen/endpoints', () => ({ postStreamDelivery: vi.fn() }))

it('negotiates the capabilities of each receiving audio element', async () => {
  vi.mocked(postStreamDelivery).mockImplementation(async request => ({
    kind: 'original', url: `https://baander.app/api/stream/original/track?id=${request.id}&signature=signed`,
    reason: null, selection: null, requestId: null, jobId: null,
  }))
  const mp3 = { canPlayType: (type: string) => type === 'audio/mpeg' ? 'probably' : '' } as HTMLAudioElement
  const flac = { canPlayType: (type: string) => type === 'audio/flac' ? 'probably' : '' } as HTMLAudioElement
  await resolveMusicSource(mp3, 'song', new AbortController().signal)
  await resolveMusicSource(flac, 'song', new AbortController().signal)
  expect(vi.mocked(postStreamDelivery).mock.calls[0][0]).toMatchObject({ containers: ['mp3'], audioCodecs: ['mp3'] })
  expect(vi.mocked(postStreamDelivery).mock.calls[1][0]).toMatchObject({ containers: ['flac'], audioCodecs: ['flac'] })
})
