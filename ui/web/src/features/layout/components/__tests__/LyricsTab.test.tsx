import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { cleanup, render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { AxiosError, AxiosHeaders } from 'axios'
import { ThemeProvider } from 'styled-components'
import { toast } from 'sonner'
import { customInstance } from '@/shared/api-client/axios-instance'
import { resolveTheme } from '@/shared/theme/resolve-theme'
import {
  LYRICS_ALREADY_EXIST,
  LYRICS_CONFLICT,
  LYRICS_PROVIDER_UNAVAILABLE,
  LYRICS_REQUEST_REJECTED,
  LYRICS_SONG_HAS_NO_ARTIST,
  LYRICS_SONG_HAS_NO_LENGTH,
} from '@/features/catalog/components/lyrics-error-message'
import { LyricsTab } from '../LyricsTab'

const { admin } = vi.hoisted(() => ({
  admin: { current: false },
}))

vi.mock('@/shared/api-client/axios-instance', () => ({
  customInstance: vi.fn(),
}))
vi.mock('@/features/auth/hooks/use-admin-check', () => ({
  useIsAdmin: () => admin.current,
}))
vi.mock('sonner', () => ({
  toast: { success: vi.fn(), error: vi.fn(), info: vi.fn() },
}))
vi.mock('@/features/player/stores/player-store', () => ({
  usePlayerStore: (selector: (state: unknown) => unknown) =>
    selector({ currentTrack: { publicId: 'song-1', title: '直', albumLanguage: 'jpn' }, seekTo: () => {} }),
}))
vi.mock('@/features/player/stores/player-time-tracker', () => ({ useCurrentTime: () => 0 }))

const LYRICS_URL = '/api/songs/song-1/lyrics'
const FETCH_URL = '/api/songs/song-1/lyrics/fetch'

interface StoredLyrics {
  plainLyrics: string | null
  syncedLyrics: string | null
  source: string
  isInstrumental: boolean
}

const STILL_ALIVE: StoredLyrics = {
  plainLyrics: 'This was a triumph',
  syncedLyrics: null,
  source: 'lrclib',
  isInstrumental: false,
}

class ResizeObserverStub {
  observe() {}
  disconnect() {}
}

const mockRequest = vi.mocked(customInstance)
let client: QueryClient
let stored: StoredLyrics | never[]
let fetchAnswer: () => Promise<unknown>

function httpError(status: number, message: string, reason?: string): AxiosError {
  const error = reason === undefined
    ? { code: status, message }
    : { code: status, message, details: { reason } }

  return new AxiosError(message, String(status), undefined, undefined, {
    status,
    statusText: message,
    headers: {},
    config: { headers: new AxiosHeaders() },
    data: { error },
  })
}

function mount() {
  client = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } })

  return render(
    <QueryClientProvider client={client}>
      <ThemeProvider theme={resolveTheme('dark', 'violet')}>
        <LyricsTab />
      </ThemeProvider>
    </QueryClientProvider>,
  )
}

async function fetchFromLrclib() {
  admin.current = true
  const user = userEvent.setup()
  mount()

  await user.click(await screen.findByRole('button', { name: /Fetch from LRCLIB/ }))
}

describe('LyricsTab', () => {
  beforeEach(() => {
    globalThis.ResizeObserver = ResizeObserverStub as unknown as typeof ResizeObserver
    Element.prototype.scrollTo = vi.fn()
    admin.current = false
    stored = []
    fetchAnswer = () => Promise.resolve({ data: [] })
    vi.mocked(toast.success).mockReset()
    vi.mocked(toast.error).mockReset()
    vi.mocked(toast.info).mockReset()
    mockRequest.mockReset()
    mockRequest.mockImplementation((url: string) => {
      if (url === FETCH_URL) return fetchAnswer()
      if (url === LYRICS_URL) return Promise.resolve({ data: stored })

      return Promise.reject(new Error(`Unexpected request ${url}`))
    })
  })

  afterEach(() => {
    cleanup()
    client?.clear()
  })

  it('renders plain Thai lyrics as one block with the Thai script rules', async () => {
    stored = { plainLyrics: 'ฉันรักเธอ\nทุกวัน', syncedLyrics: null, source: 'lrclib', isInstrumental: false }
    mount()

    const block = await screen.findByText((_, element) => element?.tagName === 'PRE' && (element.textContent ?? '').startsWith('ฉันรักเธอ'))
    expect(block.getAttribute('data-script')?.split(' ')).toContain('thai')
  })

  it('renders each synced line in the song language', async () => {
    stored = { plainLyrics: null, syncedLyrics: '[00:01.00]直\n[00:05.00]ทุกวัน', source: 'lrclib', isInstrumental: false }
    mount()

    expect(await screen.findByText('直')).toHaveAttribute('lang', 'ja')
    expect(screen.getByText('ทุกวัน').getAttribute('data-script')?.split(' ')).toContain('thai')
  })

  it('shows the lyrics a fetch stored and confirms them', async () => {
    fetchAnswer = () => {
      stored = STILL_ALIVE

      return Promise.resolve({ data: STILL_ALIVE })
    }

    await fetchFromLrclib()

    expect(await screen.findByText('This was a triumph')).toBeInTheDocument()
    expect(toast.success).toHaveBeenCalledWith('Lyrics fetched')
    expect(toast.error).not.toHaveBeenCalled()
  })

  it('says no lyrics were found, without confirming a fetch, when LRCLIB has none', async () => {
    await fetchFromLrclib()

    await waitFor(() => expect(toast.info).toHaveBeenCalledWith('No lyrics found'))
    expect(toast.success).not.toHaveBeenCalled()
    expect(toast.error).not.toHaveBeenCalled()
  })

  it.each([
    ['song_has_no_artist', LYRICS_SONG_HAS_NO_ARTIST],
    ['song_has_no_length', LYRICS_SONG_HAS_NO_LENGTH],
    ['lrclib_rejected', LYRICS_REQUEST_REJECTED],
  ])('names the cause of a conflict with the reason %s', async (reason, message) => {
    fetchAnswer = () => Promise.reject(httpError(409, 'The API message for the conflict.', reason))

    await fetchFromLrclib()

    await waitFor(() => expect(toast.error).toHaveBeenCalledWith(message))
    expect(toast.error).not.toHaveBeenCalledWith('No lyrics found')
    expect(toast.success).not.toHaveBeenCalled()
    expect(toast.info).not.toHaveBeenCalled()
  })

  it('says LRCLIB is unavailable when a fetch meets an outage', async () => {
    fetchAnswer = () => Promise.reject(httpError(503, 'The lyrics provider LRCLIB is unavailable. Try again later.'))

    await fetchFromLrclib()

    await waitFor(() => expect(toast.error).toHaveBeenCalledWith(LYRICS_PROVIDER_UNAVAILABLE))
    expect(toast.error).not.toHaveBeenCalledWith('No lyrics found')
    expect(toast.success).not.toHaveBeenCalled()
  })

  it('shows a generic conflict, not "already has lyrics", for a 409 whose reason it does not know', async () => {
    fetchAnswer = () => Promise.reject(httpError(409, 'Something else is in the way.', 'something_new'))

    await fetchFromLrclib()

    await waitFor(() => expect(toast.error).toHaveBeenCalledWith(LYRICS_CONFLICT))
    expect(toast.error).not.toHaveBeenCalledWith(LYRICS_ALREADY_EXIST)
    expect(toast.success).not.toHaveBeenCalled()
  })
})
