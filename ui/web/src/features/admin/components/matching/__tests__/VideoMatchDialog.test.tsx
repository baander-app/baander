import { act, cleanup, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { afterAll, afterEach, beforeAll, beforeEach, describe, expect, it, vi } from 'vitest'
import { render } from '../../../../../../tests/test-utils'
import { customInstance } from '@/shared/api-client/axios-instance'
import type {
  VideoMatchChoicesResource,
  VideoMatchReviewPageResourceItemsItem,
} from '@/shared/api-client/gen/endpoints'
import { VideoMatchDialog } from '../VideoMatchDialog'

vi.mock('@/shared/api-client/axios-instance', () => ({ customInstance: vi.fn() }))

const mockRequest = vi.mocked(customInstance)
const scrollIntoView = Object.getOwnPropertyDescriptor(HTMLElement.prototype, 'scrollIntoView')
const review: VideoMatchReviewPageResourceItemsItem = {
  kind: 'series',
  publicId: 'series-public',
  ownerKind: 'series',
  ownerPublicId: 'series-public',
  title: 'The Show',
  path: null,
  libraryPublicId: 'library-public',
  libraryName: 'TV',
  reason: 'no_candidates',
  checkedAt: '2026-11-28T12:00:00Z',
  bestScore: null,
}

let client: QueryClient
let failApply: boolean
let failSearch: boolean
let pendingApply: Promise<void> | null

function choices(collection: 'orderings' | 'episodes' | 'files', page: number): VideoMatchChoicesResource {
  return {
    kind: 'series',
    publicId: 'series-public',
    view: 'choices',
    collection,
    provider: 'tvdb',
    currentOrdering: 'aired',
    orderingProblem: null,
    orderings: collection === 'orderings' ? [
      { id: 'aired', label: 'Aired', kind: 'aired' },
      { id: 'dvd-first', label: 'DVD first edition', kind: 'dvd' },
      { id: 'dvd-second', label: 'DVD second edition', kind: 'dvd' },
    ] : [],
    episodes: collection === 'episodes' ? {
      items: [{
        publicId: `episode-${page}`,
        seasonNumber: 1,
        episodeNumber: page,
        title: page === 1 ? 'First' : 'Second',
        absoluteNumber: page,
        airDate: null,
      }],
      total: 2,
      page,
      perPage: 1,
      hasNextPage: page === 1,
    } : null,
    files: collection === 'files' ? {
      items: [{ publicId: 'placement-public', path: 'The Show/clip.mkv', assigned: false, episodePublicIds: [], extraKind: null }],
      total: 1,
      page: 1,
      perPage: 50,
      hasNextPage: false,
    } : null,
  }
}

function mount(item = review) {
  client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return render(
    <QueryClientProvider client={client}>
      <VideoMatchDialog review={item} open onOpenChange={vi.fn()} />
    </QueryClientProvider>,
  )
}

function requestBody(path: string): unknown {
  const call = mockRequest.mock.calls.find(([url]) => url === path)
  return call?.[1]?.body ? JSON.parse(String(call[1].body)) : null
}

describe('VideoMatchDialog', () => {
  beforeAll(() => {
    Object.defineProperty(HTMLElement.prototype, 'scrollIntoView', { configurable: true, value: vi.fn() })
  })

  afterAll(() => {
    if (scrollIntoView) {
      Object.defineProperty(HTMLElement.prototype, 'scrollIntoView', scrollIntoView)
    } else {
      Reflect.deleteProperty(HTMLElement.prototype, 'scrollIntoView')
    }
  })

  beforeEach(() => {
    failApply = false
    failSearch = false
    pendingApply = null
    mockRequest.mockReset()
    mockRequest.mockImplementation(async (url, options) => {
      const parsed = new URL(url, 'https://baander.app')
      if (options?.method !== 'GET') {
        if (url.endsWith('/apply') && pendingApply) await pendingApply
        if (url.endsWith('/apply') && failApply) throw new Error('Offline')
        return { data: { publicId: 'series-public' } }
      }
      if (parsed.searchParams.get('view') === 'choices') {
        const collection = parsed.searchParams.get('collection')
        if (collection === 'files' || collection === 'episodes' || collection === 'orderings') {
          return { data: choices(collection, Number(parsed.searchParams.get('page') ?? 1)) }
        }
      }
      if (failSearch) throw new Error('Search failed')
      return {
        data: {
          kind: 'series',
          publicId: 'series-public',
          providers: [
            { provider: 'tmdb', problem: 'unavailable', candidates: [] },
            { provider: 'tvdb', problem: null, candidates: [{ id: 'candidate-native', title: 'The Show', year: 2020, externalIds: {} }] },
            { provider: 'local', problem: null, candidates: [] },
          ],
        },
      }
    })
  })

  afterEach(() => {
    cleanup()
    client?.clear()
  })

  it('searches with the title owner and applies the provider-local candidate after a recoverable failure', async () => {
    const user = userEvent.setup()
    mount()
    await user.click(screen.getByRole('button', { name: 'Search' }))

    expect(await screen.findByText('This provider is unavailable. Try again later.')).toBeInTheDocument()
    expect(screen.getByText('No matching titles found.')).toBeInTheDocument()
    failApply = true
    await user.click(screen.getByRole('button', { name: 'Apply TheTVDB match The Show' }))
    expect(await screen.findByText('The match could not be applied. Try again.')).toBeInTheDocument()
    failApply = false
    await user.click(screen.getByRole('button', { name: 'Apply TheTVDB match The Show' }))

    expect(await screen.findByText('Match applied. Review its ordering and file assignments.')).toBeInTheDocument()
    expect(requestBody('/api/admin/video-matches/series/series-public/apply')).toEqual({ provider: 'tvdb', candidateId: 'candidate-native' })
    expect(screen.getByRole('tab', { name: 'Ordering' })).toHaveAttribute('aria-selected', 'true')
  })

  it('saves the exact offered ordering ID through a keyboard-operated select', async () => {
    const user = userEvent.setup()
    mount()
    await user.click(screen.getByRole('tab', { name: 'Ordering' }))
    const select = await screen.findByRole('combobox', { name: 'Episode ordering' })
    act(() => select.focus())
    await user.keyboard('{ArrowDown}')
    await user.click(await screen.findByRole('option', { name: 'DVD second edition' }))
    await user.click(screen.getByRole('button', { name: 'Save ordering' }))

    expect(await screen.findByText('Ordering saved.')).toBeInTheDocument()
    expect(requestBody('/api/admin/video-matches/series/series-public/ordering')).toEqual({ ordering: 'dvd-second' })
  })

  it('keeps episode selections across pages and sends the placement public ID', async () => {
    const user = userEvent.setup()
    mount({ ...review, kind: 'file', publicId: 'placement-public', path: 'The Show/clip.mkv' })
    await user.click(await screen.findByRole('button', { name: 'Assign The Show/clip.mkv' }))
    await user.click(await screen.findByRole('checkbox', { name: /First/ }))
    await user.click(screen.getByRole('button', { name: 'Next episodes page' }))
    await user.click(await screen.findByRole('checkbox', { name: /Second/ }))
    expect(screen.getByText('2 episodes selected. Selections stay when changing pages.')).toBeInTheDocument()
    await user.click(screen.getByRole('button', { name: 'Previous episodes page' }))
    expect(await screen.findByRole('checkbox', { name: /First/ })).toBeChecked()
    await user.click(screen.getByRole('button', { name: 'Save assignment' }))

    await waitFor(() => {
      expect(requestBody('/api/admin/video-matches/files/placement-public/assignment')).toEqual({
        episodePublicIds: ['episode-1', 'episode-2'],
      })
    })
    await waitFor(() => expect(screen.queryByRole('button', { name: 'Save assignment' })).not.toBeInTheDocument())
    expect(screen.getByRole('button', { name: 'Assign The Show/clip.mkv' })).toHaveFocus()
  })

  it('assigns a file to an extra with no episode IDs', async () => {
    const user = userEvent.setup()
    mount({ ...review, kind: 'file', publicId: 'placement-public' })
    await user.click(await screen.findByRole('button', { name: 'Assign The Show/clip.mkv' }))
    const select = await screen.findByRole('combobox', { name: 'Assign as' })
    act(() => select.focus())
    await user.keyboard('{ArrowDown}')
    await user.click(await screen.findByRole('option', { name: 'trailer' }))
    await user.click(screen.getByRole('button', { name: 'Save assignment' }))

    await waitFor(() => expect(requestBody('/api/admin/video-matches/files/placement-public/assignment')).toEqual({ extraKind: 'trailer' }))
  })

  it('retries a failed search and clears its search state when another row opens', async () => {
    const user = userEvent.setup()
    failSearch = true
    const mounted = mount()
    await user.click(screen.getByRole('button', { name: 'Search' }))
    expect(await screen.findByText('The search failed. Try again.')).toBeInTheDocument()
    failSearch = false
    await user.click(screen.getByRole('button', { name: 'Search' }))
    expect(await screen.findByRole('button', { name: 'Apply TheTVDB match The Show' })).toBeInTheDocument()
    mounted.rerender(
      <QueryClientProvider client={client}>
        <VideoMatchDialog
          review={{ ...review, ownerPublicId: 'another-series', publicId: 'another-series', title: 'Another Show' }}
          open
          onOpenChange={vi.fn()}
        />
      </QueryClientProvider>,
    )

    expect(screen.getByRole('textbox', { name: 'Search title' })).toHaveValue('Another Show')
    expect(screen.queryByRole('button', { name: 'Apply TheTVDB match The Show' })).not.toBeInTheDocument()
  })

  it('disables competing actions while applying a candidate', async () => {
    const user = userEvent.setup()
    let finish = () => {}
    pendingApply = new Promise<void>((resolve) => { finish = resolve })
    mount()
    await user.click(screen.getByRole('button', { name: 'Search' }))
    await user.click(await screen.findByRole('button', { name: 'Apply TheTVDB match The Show' }))

    expect(screen.getByRole('tab', { name: 'Ordering' })).toBeDisabled()
    expect(screen.getByRole('tab', { name: 'Files' })).toBeDisabled()
    expect(screen.getByRole('button', { name: 'Apply TheTVDB match The Show' })).toBeDisabled()
    await act(async () => { finish() })
    expect(await screen.findByText('Match applied. Review its ordering and file assignments.')).toBeInTheDocument()
  })

  it('requires an explicit extra choice for a movie feature', async () => {
    const user = userEvent.setup()
    mount({ ...review, kind: 'file', ownerKind: 'movie', publicId: 'placement-public', ownerPublicId: 'movie-public' })
    await user.click(await screen.findByRole('button', { name: 'Assign The Show/clip.mkv' }))

    expect(screen.getByRole('combobox', { name: 'Assign as' })).toHaveTextContent('Choose an assignment')
    expect(screen.getByRole('button', { name: 'Save assignment' })).toBeDisabled()
    expect(screen.queryByRole('tab', { name: 'Ordering' })).not.toBeInTheDocument()
    expect(mockRequest.mock.calls.some(([url]) => url.includes('collection=episodes'))).toBe(false)
  })
})
