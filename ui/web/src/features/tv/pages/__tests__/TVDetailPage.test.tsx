import { QueryClient, QueryClientProvider, useMutation } from '@tanstack/react-query'
import { act, fireEvent, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import type {
  PutActivityWatchedUpdateMutationVariables,
  TvEpisodeResource,
  TvSeriesEpisodesParams,
} from '@/shared/api-client/gen/endpoints'
import { render } from '../../../../../tests/test-utils'
import { TVSeriesDetailPage } from '../TVDetailPage'

const { readEpisodes, writeWatched, playVideo, failedToast } = vi.hoisted(() => ({
  readEpisodes: vi.fn(),
  writeWatched: vi.fn(),
  playVideo: vi.fn(),
  failedToast: vi.fn(),
}))

const pilot: TvEpisodeResource = {
  publicId: 'pilot',
  name: 'Pilot',
  overview: null,
  stillUrl: null,
  airDate: null,
  seasonNumber: 1,
  episodeNumber: 1,
  seriesPublicId: 'series',
  seriesName: 'Fixture series',
  seasonPublicId: 'season',
  absoluteNumber: null,
  libraryPublicId: 'library',
  metadataSource: 'tvdb',
  watched: false,
  progress: 0.3,
  defaultVideoPublicId: 'watched-version',
  videos: [
    { publicId: 'first-version', duration: 100, width: 1920, height: 1080, videoBitrate: null, positionSeconds: null, lastPlayedAt: null },
    { publicId: 'watched-version', duration: 100, width: 1920, height: 1080, videoBitrate: null, positionSeconds: 30, lastPlayedAt: '2026-11-28T12:00:00Z' },
  ],
}
const finished = { ...pilot, publicId: 'finished', name: 'Finished episode', episodeNumber: 2, watched: true, progress: null }
const daily = { ...pilot, publicId: 'daily', name: 'Episode 2026-11-28', seasonNumber: null, seasonPublicId: null, episodeNumber: null, airDate: '2026-11-28' }

vi.mock('@/shared/api-client/gen/endpoints', async importOriginal => {
  const original = await importOriginal<typeof import('@/shared/api-client/gen/endpoints')>()
  return {
    ...original,
    useTvSeriesShow: () => ({
      data: {
        data: {
          publicId: 'series',
          name: 'Fixture series',
          posterUrl: null,
          overview: null,
          libraryPublicId: 'library',
          metadataSource: 'tvdb',
          extras: [],
          seasons: [
            { publicId: 'specials', number: 0, name: null, ownedCount: 1, totalCount: 1, watchedCount: 0 },
            { publicId: 'empty', number: 2, name: null, ownedCount: 0, totalCount: 8, watchedCount: 0 },
            { publicId: 'season', number: 1, name: null, ownedCount: 2, totalCount: 3, watchedCount: 1 },
          ],
          unseasonedCount: 1,
        },
      },
    }),
    useTvSeriesEpisodes: readEpisodes,
    usePutActivityWatchedUpdate: (options: {
      mutation: { onError: () => void; onSuccess: (data: unknown, variables: PutActivityWatchedUpdateMutationVariables) => Promise<void> }
    }) => useMutation({
      ...options.mutation,
      mutationFn: (variables: PutActivityWatchedUpdateMutationVariables) => writeWatched(variables),
    }),
  }
})

vi.mock('@/features/catalog/services/video-playback', () => ({ requestVideoPlayback: playVideo }))
vi.mock('@/features/session/hooks/use-playback-session', () => ({ usePlaybackSession: () => ({ snapshot: null }) }))
vi.mock('@/features/catalog/components/WorkPeople', () => ({ WorkPeople: () => null }))
vi.mock('@/features/catalog/components/MoviePlayerOverlay', () => ({
  MoviePlayerOverlay: ({ title }: { title: string }) => <div role="dialog" aria-label={title} />,
}))
vi.mock('sonner', () => ({ toast: { error: failedToast } }))

function renderSeries() {
  return render(
    <QueryClientProvider client={new QueryClient()}>
      <MemoryRouter initialEntries={['/tv/series/series']}>
        <Routes>
          <Route path="/tv/series/:publicId" element={<TVSeriesDetailPage />} />
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>
  )
}

beforeEach(() => {
  writeWatched.mockReset()
  playVideo.mockReset().mockResolvedValue(undefined)
  failedToast.mockReset()
  readEpisodes.mockReset().mockImplementation((_: string, params: TvSeriesEpisodesParams) => ({
    data: {
      data: params.unseasoned ? [daily] : [pilot, finished],
      meta: { current_page: 1, last_page: 1 },
    },
  }))
})

describe('series detail', () => {
  it('lists regular seasons before Specials, shows counts and opens unseasoned rows', async () => {
    const user = userEvent.setup()
    renderSeries()

    const tabs = within(screen.getByRole('tablist', { name: 'Seasons' })).getAllByRole('tab')
    expect(tabs.map(tab => tab.textContent)).toEqual(['Season 1', 'Season 2', 'Unseasoned', 'Specials'])
    expect(screen.getByText('2 of 3 episodes')).toBeInTheDocument()
    expect(screen.getByText('30%')).toBeInTheDocument()
    expect(screen.getByText('Watched')).toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'TheTVDB.com' })).toHaveAttribute('href', 'https://thetvdb.com')

    await user.click(screen.getByRole('tab', { name: 'Season 2' }))
    expect(screen.getByText('0 of 8 episodes')).toBeInTheDocument()
    expect(screen.getByText('No available episodes.')).toBeInTheDocument()

    await user.click(screen.getByRole('tab', { name: 'Unseasoned' }))
    expect(screen.getByText('1 of 1 episodes')).toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'Episode 2026-11-28' })).toBeInTheDocument()
    expect(readEpisodes).toHaveBeenLastCalledWith(
      'series',
      { unseasoned: true, page: 1, limit: 50 },
      expect.objectContaining({ query: expect.objectContaining({ enabled: true }) }),
    )
  })

  it('rolls back every affected row and reports failure after a season overflow mark', async () => {
    const user = userEvent.setup()
    let rejectPending: (failure: Error) => void = () => undefined
    writeWatched.mockReturnValue(new Promise((_, reject) => {
      rejectPending = reject
    }))
    renderSeries()

    await user.click(screen.getByRole('button', { name: 'Options for Season 1' }))
    await user.click(screen.getByRole('menuitem', { name: 'Mark as watched' }))

    expect(writeWatched).toHaveBeenCalledExactlyOnceWith({
      kind: 'season',
      publicId: 'season',
      data: { watched: true },
    })
    await waitFor(() => expect(screen.getAllByText('Watched')).toHaveLength(2))
    expect(screen.queryByText('30%')).not.toBeInTheDocument()

    await act(async () => rejectPending(new Error('write failed')))
    expect(await screen.findByText('30%')).toBeInTheDocument()
    expect(screen.getAllByText('Watched')).toHaveLength(1)
    expect(failedToast).toHaveBeenCalledWith('Could not update the watched state. Try again.')
  })

  it('offers episode marks from a context menu and plays the saved version', async () => {
    const user = userEvent.setup()
    writeWatched.mockResolvedValue(undefined)
    renderSeries()

    fireEvent.contextMenu(screen.getByRole('link', { name: 'Pilot' }))
    await user.click(screen.getByRole('menuitem', { name: 'Mark as unwatched' }))
    expect(writeWatched).toHaveBeenCalledExactlyOnceWith({
      kind: 'episode',
      publicId: 'pilot',
      data: { watched: false },
    })

    await user.click(screen.getByRole('button', { name: 'Resume Pilot' }))
    expect(await screen.findByRole('dialog', { name: 'Pilot' })).toBeInTheDocument()
    expect(playVideo).toHaveBeenCalledExactlyOnceWith({
      titleKind: 'episode',
      titleId: 'pilot',
      videoId: 'watched-version',
      startSeconds: 30,
    })
  })
})
