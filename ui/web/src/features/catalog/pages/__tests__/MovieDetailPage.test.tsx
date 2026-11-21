import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import {
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

const { request } = vi.hoisted(() => ({ request: vi.fn() }))

vi.mock('@/shared/api-client/gen/endpoints', () => ({
  useGetMovieShow: () => ({
    data: {
      data: {
        publicId: 'movie',
        title: 'Fixture movie',
        videos: [{ publicId: 'video', duration: 180 }]
      }
    },
    isLoading: false,
    isError: false,
    refetch: vi.fn()
  })
}))
vi.mock('@/features/session/hooks/use-playback-session', () => ({
  usePlaybackSession: () => ({
    snapshot: {
      media: { kind: 'video', publicId: 'video' },
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
  useVideoPresentation.getState().publish({ videoId: 'video', error: null })
})

function renderMovie() {
  render(
    <MemoryRouter initialEntries={['/movies/movie']}>
      <Routes>
        <Route path="/movies/:publicId" element={<MovieDetailPage />} />
      </Routes>
    </MemoryRouter>
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
      videoId: 'video'
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
})
