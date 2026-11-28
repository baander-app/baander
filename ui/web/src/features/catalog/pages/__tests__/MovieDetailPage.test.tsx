import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import {
  act,
  cleanup,
  fireEvent,
  screen,
  waitFor,
  within
} from '@testing-library/react'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { render } from '../../../../../tests/test-utils'
import { useVideoPresentation } from '../../stores/video-presentation-store'
import { MovieDetailPage } from '../MovieDetailPage'

const { request, playingVideo } = vi.hoisted(() => ({
  request: vi.fn(),
  playingVideo: { publicId: 'last-video', titleId: 'movie' },
}))

vi.mock('@/shared/api-client/gen/endpoints', () => ({
  useGetMovieShow: () => ({
    data: {
      data: {
        publicId: 'movie',
        title: 'Fixture movie',
        defaultVideoPublicId: 'last-video',
        metadataSource: 'tmdb',
        videos: [
          { publicId: 'first-video', duration: 180, positionSeconds: 0 },
          { publicId: 'last-video', duration: 180, positionSeconds: 32 },
        ],
        extras: [{
          placementPublicId: 'extra-placement',
          videoPublicId: 'extra-video',
          kind: 'behind_the_scenes',
          duration: 60,
          positionSeconds: 12,
        }],
      }
    },
    isLoading: false,
    isError: false,
    refetch: vi.fn()
  }),
  usePutActivityWatchedUpdate: () => ({ isPending: false }),
}))
vi.mock('@/features/session/hooks/use-playback-session', () => ({
  usePlaybackSession: () => ({
    snapshot: {
      media: {
        kind: 'video',
        publicId: playingVideo.publicId,
        viewing: { titleKind: 'movie', titleId: playingVideo.titleId },
      },
      position: 3,
      activeIncarnationId: 'local',
      destinations: [],
      observedState: 'paused'
    },
    endpoint: { incarnationId: 'local' },
    connected: true
  })
}))
vi.mock('@/features/session/components/PlaybackDestinationSelector', () => ({
  PlaybackDestinationSelector: () => <span>Destinations</span>
}))
vi.mock('@/features/session/services/playback-execution', () => ({
  requestPlayback: request
}))
vi.mock('@/features/catalog/hooks/use-video-playback-information', () => ({
  useVideoPlaybackInformation: () => ({
    data: undefined,
    previewUrl: null,
    isPending: false,
    isError: false
  })
}))
vi.mock('@/features/catalog/components/WorkPeople', () => ({
  WorkPeople: () => <section aria-label="Credits" />
}))
vi.mock('@/features/catalog/components/MovieMetadata', () => ({
  MovieMetadata: () => <p>Movie metadata</p>
}))

afterEach(cleanup)
beforeEach(() => {
  request.mockReset().mockResolvedValue(undefined)
  playingVideo.publicId = 'last-video'
  playingVideo.titleId = 'movie'
  useVideoPresentation.getState().publish({ videoId: 'last-video', error: null })
})

function renderMovie() {
  render(
    <QueryClientProvider client={new QueryClient()}>
      <MemoryRouter initialEntries={['/movies/movie']}>
        <Routes>
          <Route path="/movies/:publicId" element={<MovieDetailPage />} />
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>
  )
}

describe('movie player launcher', () => {
  it('retains the launching button behind the modal and returns focus after close', async () => {
    renderMovie()
    const launcher = screen.getByRole('button', { name: 'Play' })
    launcher.focus()
    fireEvent.click(launcher)

    const dialog = screen.getByRole('dialog', { name: 'Fixture movie' })
    expect(launcher).toBeInTheDocument()
    expect(launcher).not.toHaveFocus()
    expect(screen.getByText('Movie metadata')).toBeInTheDocument()

    fireEvent.click(within(dialog).getByRole('button', { name: 'Back' }))

    await waitFor(() => expect(dialog).not.toBeInTheDocument())
    await waitFor(() => expect(launcher).toHaveFocus())
    expect(screen.getByRole('button', { name: 'Play' })).toBe(launcher)
    expect(request).toHaveBeenCalledExactlyOnceWith({
      action: 'stop-local-video',
      incarnationId: 'local',
      videoId: 'last-video'
    })
  })

  it('keeps the modal and retained launcher when the scoped stop fails', async () => {
    renderMovie()
    const launcher = screen.getByRole('button', { name: 'Play' })
    launcher.focus()
    fireEvent.click(launcher)
    const dialog = screen.getByRole('dialog', { name: 'Fixture movie' })
    request.mockRejectedValueOnce(new Error('Playback revision conflict.'))

    fireEvent.click(within(dialog).getByRole('button', { name: 'Back' }))

    await waitFor(() =>
      expect(within(dialog).getByRole('alert')).toHaveTextContent(
        'Playback revision conflict.'
      )
    )
    expect(dialog).toBeInTheDocument()
    expect(launcher).toBeInTheDocument()
    expect(launcher).not.toHaveFocus()
  })

  it('resumes the selected version and shows the TMDB credit', async () => {
    playingVideo.publicId = 'unrelated-video'
    renderMovie()

    expect(screen.getByRole('img', { name: 'TMDB' })).toBeInTheDocument()
    expect(screen.getByText('This product uses the TMDB API but is not endorsed or certified by TMDB.')).toBeInTheDocument()
    fireEvent.click(screen.getByRole('button', { name: 'Play' }))

    expect(await screen.findByRole('dialog', { name: 'Fixture movie' })).toBeInTheDocument()
    expect(request).toHaveBeenCalledExactlyOnceWith({
      action: 'load',
      media: {
        kind: 'video',
        publicId: 'last-video',
        viewing: { titleKind: 'movie', titleId: 'movie' },
      },
      position: 32,
      desiredState: 'playing',
    })
  })

  it('resumes an extra under its own placement context', async () => {
    renderMovie()
    fireEvent.click(screen.getByRole('button', { name: 'Resume Behind the scenes' }))

    expect(await screen.findByRole('dialog', { name: 'Behind the scenes' })).toBeInTheDocument()
    expect(request).toHaveBeenCalledExactlyOnceWith({
      action: 'load',
      media: {
        kind: 'video',
        publicId: 'extra-video',
        viewing: { titleKind: 'extra', titleId: 'extra-placement' },
      },
      position: 12,
      desiredState: 'playing',
    })
  })

  it('queues the movie behind an outstanding extra even when the movie is still active', async () => {
    let completeExtra!: () => void
    const extraLoad = new Promise<void>(resolve => {
      completeExtra = resolve
    })
    request.mockReturnValueOnce(extraLoad).mockImplementationOnce(async () => {
      await extraLoad
    })
    renderMovie()

    fireEvent.click(screen.getByRole('button', { name: 'Resume Behind the scenes' }))
    fireEvent.click(screen.getByRole('button', { name: 'Play' }))

    expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
    expect(request).toHaveBeenCalledTimes(2)
    expect(request).toHaveBeenLastCalledWith({
      action: 'load',
      media: {
        kind: 'video',
        publicId: 'last-video',
        viewing: { titleKind: 'movie', titleId: 'movie' },
      },
      position: 3,
      desiredState: 'playing',
    })

    await act(async () => completeExtra())

    expect(screen.getByRole('dialog', { name: 'Fixture movie' })).toBeInTheDocument()
    expect(screen.queryByRole('dialog', { name: 'Behind the scenes' })).not.toBeInTheDocument()
  })

  it('opens the same file under a different title without losing the current position', async () => {
    playingVideo.titleId = 'another-movie'
    renderMovie()

    fireEvent.click(screen.getByRole('button', { name: 'Play' }))

    expect(await screen.findByRole('dialog', { name: 'Fixture movie' })).toBeInTheDocument()
    expect(request).toHaveBeenCalledExactlyOnceWith({
      action: 'load',
      media: {
        kind: 'video',
        publicId: 'last-video',
        viewing: { titleKind: 'movie', titleId: 'movie' },
      },
      position: 3,
      desiredState: 'playing',
    })
  })
})
