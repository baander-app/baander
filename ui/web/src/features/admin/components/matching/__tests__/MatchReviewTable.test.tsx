import { cleanup, render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { ThemeProvider } from 'styled-components'
import { resolveTheme } from '@/shared/theme/resolve-theme'
import { customInstance } from '@/shared/api-client/axios-instance'
import { adminLiveDescriptors } from '@/features/admin/live-descriptors'
import type { MatchReview, MatchReviewPage } from '../../../api/match-reviews-api'
import { MatchReviewTable } from '../MatchReviewTable'

vi.mock('@/shared/api-client/axios-instance', () => ({
  AXIOS_INSTANCE: { get: vi.fn() },
  customInstance: vi.fn(),
}))

// jsdom lays nothing out, so the virtualizer would render no rows; this one renders them all.
vi.mock('@tanstack/react-virtual', () => ({
  useVirtualizer: ({ count, estimateSize }: { count: number; estimateSize: (index: number) => number }) => ({
    getTotalSize: () => count * estimateSize(0),
    getVirtualItems: () => Array.from({ length: count }, (_, index) => ({
      index,
      key: index,
      size: estimateSize(index),
      start: index * estimateSize(index),
    })),
  }),
}))

const mockCustomInstance = vi.mocked(customInstance)

function review(albumPublicId: string, title: string, reason: MatchReview['reason'], bestScore: number | null): MatchReview {
  return {
    albumPublicId,
    title,
    reason,
    checkedAt: '2026-11-14T09:30:00Z',
    bestScore,
  }
}

const firstPage: MatchReviewPage = {
  items: [
    review('album-long-bed', 'F-1 Trillion: Long Bed', 'close_runner_up', 84),
    review('album-unknown', 'Basement Tapes', 'no_candidates', null),
  ],
  next_cursor: 'cursor-page-2',
  has_next_page: true,
  per_page: 2,
}

const secondPage: MatchReviewPage = {
  items: [review('album-deluxe', 'Second Pressing', 'track_count_differs', 71)],
  next_cursor: null,
  has_next_page: false,
  per_page: 2,
}

let pages: Record<string, MatchReviewPage>
let failing: boolean
let client: QueryClient

function mount(onMatchAlbum: (albumPublicId: string) => void = vi.fn()) {
  client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return render(
    <QueryClientProvider client={client}>
      <ThemeProvider theme={resolveTheme('dark', 'violet')}>
        <MatchReviewTable onMatchAlbum={onMatchAlbum} />
      </ThemeProvider>
    </QueryClientProvider>,
  )
}

function requestedUrls(): string[] {
  return mockCustomInstance.mock.calls.map(([url]) => url)
}

describe('match review table', () => {
  beforeEach(() => {
    failing = false
    pages = { first: firstPage, 'cursor-page-2': secondPage }
    mockCustomInstance.mockReset()
    mockCustomInstance.mockImplementation(async (url: string) => {
      if (failing) throw new Error('Unavailable')
      const cursor = new URL(url, 'https://baander.app').searchParams.get('cursor') ?? 'first'
      return { data: pages[cursor] }
    })
  })

  afterEach(() => {
    cleanup()
    client?.clear()
  })

  it('lists each album with its title, readable reason and best score', async () => {
    mount()

    expect(await screen.findByText('F-1 Trillion: Long Bed')).toBeInTheDocument()
    expect(screen.getByText('Runner-up scores too close')).toBeInTheDocument()
    expect(screen.getByText('84')).toBeInTheDocument()
    expect(screen.getByText('Basement Tapes')).toBeInTheDocument()
    expect(screen.getByText('No candidates found')).toBeInTheDocument()
    expect(screen.getByText('—')).toBeInTheDocument()
    expect(screen.getAllByText(new Date('2026-11-14T09:30:00Z').toLocaleString())).toHaveLength(2)
    expect(requestedUrls()).toEqual(['/api/admin/metadata/match/reviews?limit=50'])
  })

  it('loads the next page by its cursor and appends it to the rows already shown', async () => {
    const user = userEvent.setup()
    mount()
    await screen.findByText('F-1 Trillion: Long Bed')

    await user.click(screen.getByRole('button', { name: 'Load more' }))

    expect(await screen.findByText('Second Pressing')).toBeInTheDocument()
    expect(screen.getByText('Track count differs')).toBeInTheDocument()
    expect(screen.getByText('F-1 Trillion: Long Bed')).toBeInTheDocument()
    expect(requestedUrls().at(-1)).toBe('/api/admin/metadata/match/reviews?limit=50&cursor=cursor-page-2')
    expect(screen.queryByRole('button', { name: 'Load more' })).not.toBeInTheDocument()
  })

  it('shows the empty state when no album needs review', async () => {
    pages.first = { items: [], next_cursor: null, has_next_page: false, per_page: 50 }
    mount()

    expect(await screen.findByText('No albums need a match review.')).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Load more' })).not.toBeInTheDocument()
  })

  it('offers a retry when the list fails to load and shows the rows once it succeeds', async () => {
    const user = userEvent.setup()
    failing = true
    mount()

    const alert = await screen.findByRole('alert')
    expect(alert).toHaveTextContent('Unable to load the albums that need review.')
    expect(screen.queryByText('No albums need a match review.')).not.toBeInTheDocument()

    failing = false
    await user.click(screen.getByRole('button', { name: 'Retry' }))

    expect(await screen.findByText('F-1 Trillion: Long Bed')).toBeInTheDocument()
    expect(screen.queryByRole('alert')).not.toBeInTheDocument()
  })

  it('keeps the loaded rows and lets Load more retry when the next page fails', async () => {
    const user = userEvent.setup()
    mount()
    await screen.findByText('F-1 Trillion: Long Bed')

    failing = true
    await user.click(screen.getByRole('button', { name: 'Load more' }))

    expect(await screen.findByRole('alert')).toHaveTextContent('Unable to load more albums.')
    expect(screen.getByText('F-1 Trillion: Long Bed')).toBeInTheDocument()

    failing = false
    await user.click(screen.getByRole('button', { name: 'Load more' }))

    expect(await screen.findByText('Second Pressing')).toBeInTheDocument()
    expect(screen.queryByRole('alert')).not.toBeInTheDocument()
  })

  it("passes the row's album public ID to onMatchAlbum", async () => {
    const user = userEvent.setup()
    const onMatchAlbum = vi.fn()
    mount(onMatchAlbum)

    await user.click(await screen.findByRole('button', { name: 'Match Basement Tapes' }))

    expect(onMatchAlbum).toHaveBeenCalledTimes(1)
    expect(onMatchAlbum).toHaveBeenCalledWith('album-unknown')
  })

  it('reads the list under one stored admin-metadata live descriptor', async () => {
    mount()
    await screen.findByText('F-1 Trillion: Long Bed')

    const keys = client.getQueryCache().getAll().map(query => query.queryKey)
    expect(keys).toHaveLength(1)
    const descriptors = adminLiveDescriptors.filter(descriptor => descriptor.matches(keys[0]))
    expect(descriptors.map(descriptor => descriptor.family)).toEqual(['admin-metadata'])
    expect(descriptors[0].sampleIntervalMs).toBeUndefined()
  })
})
