import { cleanup, render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { ThemeProvider } from 'styled-components'
import { resolveTheme } from '@/shared/theme/resolve-theme'
import { customInstance } from '@/shared/api-client/axios-instance'
import type { VideoMatchReviewPageResourceItemsItem } from '@/shared/api-client/gen/endpoints'
import { VideoMatchReviewTable } from '../VideoMatchReviewTable'

vi.mock('@/shared/api-client/axios-instance', () => ({ customInstance: vi.fn() }))
vi.mock('@/features/library/hooks/use-libraries', () => ({
  useLibraries: () => ({
    data: [{ publicId: 'tv-library', name: 'Television', type: 'tv_show' }, { publicId: 'music-library', name: 'Music', type: 'music' }],
    isPending: false,
    isError: false,
  }),
}))
vi.mock('../VideoMatchDialog', () => ({
  VideoMatchDialog: ({ review, onOpenChange }: { review: VideoMatchReviewPageResourceItemsItem; onOpenChange: (open: boolean) => void }) => (
    <div role="dialog" aria-label={`Review owner ${review.ownerPublicId}`}>
      <button onClick={() => onOpenChange(false)}>Close review</button>
    </div>
  ),
}))

const request = vi.mocked(customInstance)
const item: VideoMatchReviewPageResourceItemsItem = {
  kind: 'file',
  publicId: 'placement-public',
  ownerKind: 'series',
  ownerPublicId: 'show-public',
  title: 'Show',
  path: 'Show/S01E01.mkv',
  libraryPublicId: 'tv-library',
  libraryName: 'Television',
  reason: 'no_candidates',
  checkedAt: '2026-11-28T10:00:00Z',
  bestScore: null,
}
let client: QueryClient

function mount() {
  client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return render(
    <QueryClientProvider client={client}>
      <ThemeProvider theme={resolveTheme('dark', 'violet')}>
        <VideoMatchReviewTable />
      </ThemeProvider>
    </QueryClientProvider>,
  )
}

function response(items: VideoMatchReviewPageResourceItemsItem[] = [item]) {
  return { data: { items, pausedProviders: [{ provider: 'tvdb', retryAt: '2026-11-28T11:00:00Z', failureKind: 'unavailable' }], nextCursor: null, hasNextPage: false, perPage: 50 } }
}

beforeEach(() => {
  request.mockReset()
  request.mockResolvedValue(response())
})

afterEach(() => {
  cleanup()
  client?.clear()
})

describe('video match review table', () => {
  it('shows a paused provider and applies kind, library and reason filters', async () => {
    const user = userEvent.setup()
    mount()
    await screen.findByRole('table', { name: 'Video match reviews' })
    expect(screen.getByText(/Matching is paused for tvdb/)).toBeInTheDocument()
    expect(screen.getByText('Show/S01E01.mkv')).toBeInTheDocument()
    expect(within(screen.getByLabelText('Library')).queryByRole('option', { name: 'Music' })).not.toBeInTheDocument()

    await user.selectOptions(screen.getByLabelText('Kind'), 'file')
    await user.selectOptions(screen.getByLabelText('Library'), 'tv-library')
    await user.selectOptions(screen.getByLabelText('Reason'), 'no_candidates')
    await waitFor(() => {
      const url = new URL(request.mock.calls.at(-1)![0], 'https://baander.app')
      expect(url.searchParams.get('kind')).toBe('file')
      expect(url.searchParams.get('library')).toBe('tv-library')
      expect(url.searchParams.get('reason')).toBe('no_candidates')
    })
  })

  it('opens the file owner and restores keyboard focus to its review button', async () => {
    const user = userEvent.setup()
    mount()
    const button = await screen.findByRole('button', { name: 'Review Show/S01E01.mkv' })
    await user.click(button)
    expect(screen.getByRole('dialog', { name: 'Review owner show-public' })).toBeInTheDocument()
    await user.click(screen.getByRole('button', { name: 'Close review' }))
    await waitFor(() => expect(button).toHaveFocus())
  })

  it('appends the next cursor page and keeps the first page visible', async () => {
    const user = userEvent.setup()
    request.mockImplementation(async url => {
      const cursor = new URL(url, 'https://baander.app').searchParams.get('cursor')
      if (cursor === 'next-page') {
        return response([{ ...item, kind: 'movie', publicId: 'movie-public', ownerKind: 'movie', ownerPublicId: 'movie-public', path: null, title: 'Next film' }])
      }
      return { data: { ...response().data, nextCursor: 'next-page', hasNextPage: true } }
    })
    mount()
    await user.click(await screen.findByRole('button', { name: 'Load more video reviews' }))

    expect(await screen.findByText('Next film')).toBeInTheDocument()
    expect(screen.getByText('Show/S01E01.mkv')).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Load more video reviews' })).not.toBeInTheDocument()
  })

  it('recovers focus at the section heading when apply removes the opened row', async () => {
    const user = userEvent.setup()
    mount()
    await user.click(await screen.findByRole('button', { name: 'Review Show/S01E01.mkv' }))
    request.mockResolvedValue(response([]))
    await client.invalidateQueries({ queryKey: ['admin-video-matches'] })
    await screen.findByText('No video files or titles need review for these filters.')
    await user.click(screen.getByRole('button', { name: 'Close review' }))
    await waitFor(() => expect(screen.getByRole('heading', { name: 'Video reviews' })).toHaveFocus())
  })

  it('shows an actionable error separately from an empty review list', async () => {
    const user = userEvent.setup()
    request.mockRejectedValue(new Error('Unavailable'))
    mount()
    expect(await screen.findByRole('alert')).toHaveTextContent('Video reviews could not be loaded.')
    expect(screen.queryByText('No video files or titles need review for these filters.')).not.toBeInTheDocument()
    request.mockResolvedValue(response([]))
    await user.click(screen.getByRole('button', { name: 'Retry video reviews' }))
    expect(await screen.findByText('No video files or titles need review for these filters.')).toBeInTheDocument()
  })
})
