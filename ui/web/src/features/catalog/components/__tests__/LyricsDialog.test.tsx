import { OwnedQueryClient } from '@/shared/live/query-client'
import { LiveQueryCoordinator } from '@/shared/live/query-coordinator'
import { catalogLiveDescriptors } from '@/features/catalog/live-descriptors'
import { LiveCoordinatorContext } from '@/shared/live/context'
import { advanceAuthorization, synchronizeOwnership } from '@/shared/live/ownership'
import type { LiveSnapshot } from '@/shared/live/recovery'
import { act, cleanup, render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { AxiosError, AxiosHeaders } from 'axios'
import { ThemeProvider } from 'styled-components'
import { toast } from 'sonner'
import { resolveTheme } from '@/shared/theme/resolve-theme'
import { customInstance } from '@/shared/api-client/axios-instance'
import { LyricsDialog } from '../LyricsDialog'
import { LYRICS_PROVIDER_UNAVAILABLE } from '../lyrics-error-message'

vi.mock('@/shared/api-client/axios-instance', () => ({
  customInstance: vi.fn(),
}))
vi.mock('@/features/auth/hooks/use-admin-check', () => ({
  useIsAdmin: () => true,
}))
vi.mock('sonner', () => ({
  toast: { success: vi.fn(), error: vi.fn(), info: vi.fn() },
}))

const SONG = 'lyricsDialogSongId001'

const STILL_ALIVE = {
  id: 912345,
  trackName: 'Still Alive',
  artistName: 'GLaDOS',
  albumName: 'Portal',
  duration: 175,
  instrumental: false,
  plainLyrics: 'This was a triumph',
  syncedLyrics: null,
}

type Answer = () => Promise<unknown>

const mockRequest = vi.mocked(customInstance)
let client: QueryClient
let answers: { fetch: Answer; search: Answer; apply: Answer }

function httpError(status: number, message: string): AxiosError {
  return new AxiosError(message, String(status), undefined, undefined, {
    status,
    statusText: message,
    headers: {},
    config: { headers: new AxiosHeaders() },
    data: { error: { code: status, message } },
  })
}

function mount() {
  client = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } })

  return render(
    <QueryClientProvider client={client}>
      <ThemeProvider theme={resolveTheme('dark', 'violet')}>
        <LyricsDialog open onOpenChange={() => {}} songPublicId={SONG} songTitle="Still Alive" artistName="GLaDOS" />
      </ThemeProvider>
    </QueryClientProvider>,
  )
}

async function applyFirstSearchResult() {
  const user = userEvent.setup()
  mount()

  await user.click(await screen.findByRole('tab', { name: 'Search' }))
  await user.click(screen.getByRole('button', { name: 'Auto' }))
  await user.click(await screen.findByRole('button', { name: /Still Alive/ }))
}

describe('LyricsDialog', () => {
  beforeEach(() => {
    vi.mocked(toast.success).mockReset()
    vi.mocked(toast.error).mockReset()
    vi.mocked(toast.info).mockReset()
    answers = {
      fetch: () => Promise.resolve({ data: [] }),
      search: () => Promise.resolve({ data: [STILL_ALIVE] }),
      apply: () => Promise.resolve({ data: { plainLyrics: 'This was a triumph', syncedLyrics: null, source: 'lrclib', isInstrumental: false } }),
    }
    mockRequest.mockReset()
    mockRequest.mockImplementation((url: string, options: RequestInit) => {
      if (url.endsWith('/lyrics/fetch')) return answers.fetch()
      if (url.startsWith('/api/lyrics/search?')) return answers.search()
      if (url.endsWith('/apply') && options.method === 'POST') return answers.apply()
      if (url === `/api/songs/${SONG}/lyrics`) return Promise.resolve({ data: [] })

      return Promise.reject(new Error(`Unexpected request ${url}`))
    })
  })

  afterEach(() => {
    cleanup()
    client?.clear()
  })

  it('shows the conflict instead of "Lyrics applied" when the song already has lyrics', async () => {
    answers.apply = () => Promise.reject(httpError(409, 'The song already has lyrics.'))

    await applyFirstSearchResult()

    await waitFor(() => expect(toast.error).toHaveBeenCalledWith('The song already has lyrics.'))
    expect(toast.success).not.toHaveBeenCalled()
  })

  it('says LRCLIB is unavailable when an apply meets an outage', async () => {
    answers.apply = () => Promise.reject(httpError(503, 'The lyrics provider LRCLIB is unavailable. Try again later.'))

    await applyFirstSearchResult()

    await waitFor(() => expect(toast.error).toHaveBeenCalledWith(LYRICS_PROVIDER_UNAVAILABLE))
    expect(toast.success).not.toHaveBeenCalled()
  })

  it('confirms an applied result', async () => {
    await applyFirstSearchResult()

    await waitFor(() => expect(toast.success).toHaveBeenCalledWith('Lyrics applied'))
  })

  it('says LRCLIB is unavailable instead of "No results found" when a search meets an outage', async () => {
    answers.search = () => Promise.reject(httpError(503, 'The lyrics provider LRCLIB is unavailable. Try again later.'))
    const user = userEvent.setup()
    mount()

    await user.click(await screen.findByRole('tab', { name: 'Search' }))
    await user.click(screen.getByRole('button', { name: 'Auto' }))

    expect(await screen.findByRole('alert')).toHaveTextContent(LYRICS_PROVIDER_UNAVAILABLE)
    expect(screen.queryByText(/No results found/)).not.toBeInTheDocument()
  })

  it('tells a fetch that found nothing from one that met an outage', async () => {
    const user = userEvent.setup()
    mount()

    await user.click(await screen.findByRole('button', { name: /Fetch from LRCLIB/ }))
    await waitFor(() => expect(toast.info).toHaveBeenCalledWith('No lyrics found on LRCLIB'))
    expect(toast.success).not.toHaveBeenCalled()

    answers.fetch = () => Promise.reject(httpError(503, 'The lyrics provider LRCLIB is unavailable. Try again later.'))
    await user.click(screen.getByRole('button', { name: /Fetch from LRCLIB/ }))
    await waitFor(() => expect(toast.error).toHaveBeenCalledWith(LYRICS_PROVIDER_UNAVAILABLE))
  })
  it('releases a pending lyrics mutation hold after authorization changes so later reads recover', async () => {
    synchronizeOwnership('lyrics-owner')
    const owned = new OwnedQueryClient()
    client = owned
    const snapshot: LiveSnapshot = {
      status: 'current',
      authorityReady: true,
      families: ['lyrics'],
      authorizationFingerprint: 'a'.repeat(64),
      health: { state: 'current', oldestPendingAgeMs: null },
    }
    const coordinator = new LiveQueryCoordinator(owned, {
      getSnapshot: () => snapshot,
      subscribe: () => () => undefined,
      subscribeEvents: () => () => undefined,
      completeRecovery: () => undefined,
      resourceReadFailed: () => undefined,
    }, catalogLiveDescriptors)
    owned.setCoordinator(coordinator)
    const stop = coordinator.start()
    let finish!: () => void
    let reads = 0
    mockRequest.mockImplementation((url: string) => {
      if (url.endsWith('/lyrics/fetch')) {
        return new Promise(resolve => {
          finish = () => resolve({ data: [] })
        })
      }
      if (url.endsWith('/lyrics')) {
        reads++
        return Promise.resolve({ data: reads > 1 ? { plainLyrics: 'Recovered lyrics' } : [] })
      }
      return Promise.reject(new Error(`Unexpected request ${url}`))
    })
    const view = render(
      <QueryClientProvider client={owned}>
        <LiveCoordinatorContext value={coordinator}>
          <ThemeProvider theme={resolveTheme('dark', 'violet')}>
            <LyricsDialog open onOpenChange={() => {}} songPublicId={SONG} songTitle="Still Alive" />
          </ThemeProvider>
        </LiveCoordinatorContext>
      </QueryClientProvider>,
    )
    try {
      const user = userEvent.setup()
      await user.click(await screen.findByRole('button', { name: /Fetch from LRCLIB/ }))
      await waitFor(() => expect(finish).toBeDefined())
      act(() => {
        advanceAuthorization()
        coordinator.hint(['lyrics'])
      })
      await new Promise(resolve => setTimeout(resolve, 150))
      expect(reads).toBe(1)
      await act(async () => finish())
      await waitFor(() => expect(reads).toBe(2))
      expect(await screen.findByText('Recovered lyrics')).toBeInTheDocument()
      expect(toast.info).not.toHaveBeenCalled()
    } finally {
      view.unmount()
      stop()
      owned.dispose()
      synchronizeOwnership(null)
    }
  })

})
