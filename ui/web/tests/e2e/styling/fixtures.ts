import { test as base, expect, authenticate } from '../fixtures'
import type { Page, Route } from '@playwright/test'
import type { SongResource } from '../../../src/shared/api-client/gen/endpoints'

export const catalogAlbum = {
  publicId: 'stylingAlbum0000000001',
  title: 'A very long album title for checking narrow catalog layouts',
  type: 'album', year: 2026, createdAt: '2026-01-01T00:00:00Z',
  coverImage: { blurhash: '00Qb-z', url: null }, artists: [{ name: 'The Styling Ensemble', role: null }],
  artistName: 'The Styling Ensemble', lockedFields: [],
}

export const catalogSong: SongResource = {
  publicId: 'stylingSong00000000001', albumId: catalogAlbum.publicId,
  title: 'A very long song title for checking playback and queue layouts',
  artistName: 'The Styling Ensemble', albumName: catalogAlbum.title,
  available: true, length: 30, track: 1, disc: 1, explicit: false,
  lockedFields: [], createdAt: '2026-01-01T00:00:00Z',
}

export const catalogPlaylist = {
  publicId: 'stylingPlaylist000001',
  name: 'A very long playlist name for checking narrow browsing layouts',
  description: 'Deterministic styling collection',
  ownerPublicId: 'stylingViewer00000001', isSmart: false,
  isPublic: false, isCollaborative: false, collaboratorPublicIds: [],
}

/** Silent PCM keeps active-player checks local without producing audible output. */
function localAudio(): Buffer {
  const sampleRate = 8000
  const size = sampleRate * 30 * 2
  const wav = Buffer.alloc(44 + size)
  wav.write('RIFF', 0)
  wav.writeUInt32LE(36 + size, 4)
  wav.write('WAVEfmt ', 8)
  wav.writeUInt32LE(16, 16)
  wav.writeUInt16LE(1, 20)
  wav.writeUInt16LE(1, 22)
  wav.writeUInt32LE(sampleRate, 24)
  wav.writeUInt32LE(sampleRate * 2, 28)
  wav.writeUInt16LE(2, 32)
  wav.writeUInt16LE(16, 34)
  wav.write('data', 36)
  wav.writeUInt32LE(size, 40)
  return wav
}

/** Replace catalog reads only. Login, preferences and account APIs stay real. */
export async function installCatalogFixtures(page: Page, state: 'empty' | 'populated') {
  const albums = state === 'populated' ? [catalogAlbum] : []
  const songs = state === 'populated' ? [catalogSong] : []
  const pageMeta = { current_page: 1, last_page: 1, per_page: 20, total: albums.length }
  const cursorMeta = {
    next_cursor: null, prev_cursor: null, has_next_page: false,
    has_previous_page: false, total: songs.length, per_page: 50, stale_cursor: false,
  }
  const responses: Record<string, unknown> = {
    '/api/albums': { data: albums, meta: pageMeta },
    [`/api/albums/${catalogAlbum.publicId}`]: { data: { ...catalogAlbum, songs } },
    '/api/songs': { data: songs, meta: cursorMeta },
    [`/api/songs/${catalogSong.publicId}`]: { data: catalogSong },
    '/api/artists': { data: [], meta: { ...pageMeta, total: 0 } },
    '/api/genres': { data: [] },
    '/api/playlists': { data: state === 'populated' ? [catalogPlaylist] : [] },
    [`/api/playlists/${catalogPlaylist.publicId}`]: { data: { ...catalogPlaylist, songs } },
    '/api/activity/history': { data: [] },
    '/api/recommendations/for-you': { data: [] },
    '/api/radio/starred': { data: [] },
  }
  const handler = async (route: Route) => {
    const request = route.request()
    const url = new URL(request.url())
    const path = url.pathname.replace(/\/$/, '')
    if (request.method() !== 'GET') return route.fallback()
    if (path === '/api/stream/track' && url.searchParams.get('id') === catalogSong.publicId) {
      const audio = localAudio()
      const range = /^bytes=(\d+)-(\d*)$/.exec(request.headers().range ?? '')
      if (range) {
        const start = Number(range[1])
        const end = Math.min(range[2] ? Number(range[2]) : audio.length - 1, audio.length - 1)
        if (start > end) {
          return route.fulfill({ status: 416, headers: { 'Content-Range': `bytes */${audio.length}` } })
        }
        return route.fulfill({
          status: 206, contentType: 'audio/wav', body: audio.subarray(start, end + 1),
          headers: { 'Accept-Ranges': 'bytes', 'Content-Range': `bytes ${start}-${end}/${audio.length}` },
        })
      }
      return route.fulfill({ status: 200, contentType: 'audio/wav', body: audio, headers: { 'Accept-Ranges': 'bytes' } })
    }
    if (!(path in responses)) return route.fallback()
    return route.fulfill({ status: 200, json: responses[path] })
  }
  // Context routing also covers stream fetches made by the app's service worker.
  await page.context().route('**/api/**', handler)
  return () => page.context().unroute('**/api/**', handler)
}

export const test = base.extend<{ emptyCatalog: Page; populatedCatalog: Page }>({
  emptyCatalog: async ({ page }, providePage) => {
    const cleanup = await installCatalogFixtures(page, 'empty')
    try {
      await authenticate(page)
      await providePage(page)
    } finally {
      await cleanup()
    }
  },
  populatedCatalog: async ({ page }, providePage) => {
    const cleanup = await installCatalogFixtures(page, 'populated')
    try {
      await authenticate(page)
      await providePage(page)
    } finally {
      await cleanup()
    }
  },
})

export { expect }
