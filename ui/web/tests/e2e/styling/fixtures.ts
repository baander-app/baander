import { test as base, expect } from '../fixtures'
import type { Page, Route } from '@playwright/test'
import type { SongResource } from '../../../src/shared/api-client/gen/endpoints'
import { login, withFixtureRun, type FixtureResource } from '../live-updates/capacity-fixtures'

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

/** An album stored as Japanese whose songs carry Thai and wholly Burmese titles. */
export const scriptAlbum = {
  publicId: 'stylingAlbum0000000002',
  title: 'つづきの うた',
  language: 'jpn',
  type: 'album', year: 2026, createdAt: '2026-01-01T00:00:00Z',
  coverImage: { blurhash: '00Qb-z', url: null }, artists: [{ name: '坂本龍一', role: null }],
  artistName: '坂本龍一', lockedFields: [],
}

export const thaiSong: SongResource = {
  publicId: 'stylingSong00000000002', albumId: scriptAlbum.publicId,
  title: 'ฤดูกาลใหม่ของเรา',
  artistName: '坂本龍一', albumName: scriptAlbum.title, albumLanguage: scriptAlbum.language,
  available: true, length: 30, track: 1, disc: 1, explicit: false,
  lockedFields: [], createdAt: '2026-01-01T00:00:00Z',
}

export const burmeseSong: SongResource = {
  publicId: 'stylingSong00000000003', albumId: scriptAlbum.publicId,
  title: 'ဆက်ကြည့်ရန်',
  artistName: '坂本龍一', albumName: scriptAlbum.title, albumLanguage: scriptAlbum.language,
  available: true, length: 30, track: 2, disc: 1, explicit: false,
  lockedFields: [], createdAt: '2026-01-01T00:00:00Z',
}

/** The populated catalog's songs. Their IDs must be ones the server knows for playback to start. */
export interface CatalogSongs {
  catalogSong: SongResource
  thaiSong: SongResource
  burmeseSong: SongResource
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

/**
 * Replace catalog reads only. Login, preferences and account APIs stay real. The songs default to
 * the exported templates, whose IDs the server does not know; the fixtures below pass songs with
 * a server fixture's IDs and the templates' titles.
 */
export async function installCatalogFixtures(
  page: Page, state: 'empty' | 'populated', catalogSongs: CatalogSongs = { catalogSong, thaiSong, burmeseSong },
) {
  const albums = state === 'populated' ? [catalogAlbum, scriptAlbum] : []
  const scriptSongs = [catalogSongs.thaiSong, catalogSongs.burmeseSong]
  const songs = state === 'populated' ? [catalogSongs.catalogSong, ...scriptSongs] : []
  const pageMeta = { current_page: 1, last_page: 1, per_page: 20, total: albums.length }
  const cursorMeta = {
    next_cursor: null, prev_cursor: null, has_next_page: false,
    has_previous_page: false, total: songs.length, per_page: 50, stale_cursor: false,
  }
  const responses: Record<string, unknown> = {
    '/api/albums': { data: albums, meta: pageMeta },
    [`/api/albums/${catalogAlbum.publicId}`]: {
      data: { ...catalogAlbum, songs: songs.filter((song) => song.albumId === catalogAlbum.publicId) },
    },
    [`/api/albums/${scriptAlbum.publicId}`]: {
      data: { ...scriptAlbum, songs: songs.filter((song) => song.albumId === scriptAlbum.publicId) },
    },
    '/api/songs': { data: songs, meta: cursorMeta },
    [`/api/songs/${catalogSongs.catalogSong.publicId}`]: { data: catalogSongs.catalogSong },
    ...Object.fromEntries(scriptSongs.map((song) => [`/api/songs/${song.publicId}`, { data: song }])),
    '/api/artists': { data: [], meta: { ...pageMeta, total: 0 } },
    '/api/genres': { data: [] },
    '/api/playlists': { data: state === 'populated' ? [catalogPlaylist] : [] },
    [`/api/playlists/${catalogPlaylist.publicId}`]: {
      data: { ...catalogPlaylist, songs: songs.filter((song) => song.publicId === catalogSongs.catalogSong.publicId) },
    },
    '/api/activity/history': { data: [] },
    '/api/recommendations/for-you': { data: [] },
    '/api/radio/starred': { data: [] },
  }
  const handler = async (route: Route) => {
    const request = route.request()
    const url = new URL(request.url())
    const path = url.pathname.replace(/\/$/, '')
    if (request.method() !== 'GET') return route.fallback()
    // The template song's ID stays streamable: the radio case plays it as a station stream.
    const streamed = [catalogSong, ...songs].some((song) => song.publicId === url.searchParams.get('id'))
    if (path === '/api/stream/track' && streamed) {
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

/**
 * Signs the server fixture's admin, a super admin, in over the catalog fixtures. A fresh account has no listening
 * session and no saved preferences, so the preview's own accounts cannot change what a test sees.
 * The populated catalog's songs are the fixture's 30-second songs under the templates' titles: the
 * listening session refuses a song the server does not know, and the footer shows the duration of
 * the audio the server streams.
 */
async function signInOverCatalog(page: Page, state: 'empty' | 'populated', run: (page: Page) => Promise<void>) {
  // A super admin, because the administration specs also check views only a super admin sees.
  await withFixtureRun(async (manifest, fixture) => {
    let songs: CatalogSongs | undefined
    if (state === 'populated') {
      const created = (await fixture.request<{ createdSongs: FixtureResource[] }>(
        { op: 'catalog', albums: 1, songs: 4, durations: [30] },
      )).receipt.createdSongs
      songs = {
        catalogSong: { ...catalogSong, publicId: created[0].publicId },
        thaiSong: { ...thaiSong, publicId: created[1].publicId },
        burmeseSong: { ...burmeseSong, publicId: created[2].publicId },
      }
    }
    const removeRoutes = await installCatalogFixtures(page, state, songs)
    try {
      await login(page, manifest.observer)
      await expect(page.locator('main').getByRole('heading', { name: 'Home', exact: true, level: 1 })).toBeVisible()
      await run(page)
    } finally {
      await removeRoutes()
    }
  }, { superAdmin: true })
}

export const test = base.extend<{ emptyCatalog: Page; populatedCatalog: Page }>({
  emptyCatalog: async ({ page }, providePage) => {
    await signInOverCatalog(page, 'empty', providePage)
  },
  populatedCatalog: async ({ page }, providePage) => {
    await signInOverCatalog(page, 'populated', providePage)
  },
})

export { expect }
