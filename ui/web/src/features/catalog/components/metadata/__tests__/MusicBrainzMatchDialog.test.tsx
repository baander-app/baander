import { act, cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { AxiosError, AxiosHeaders } from 'axios'
import { ThemeProvider } from 'styled-components'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { toast } from 'sonner'
import { customInstance } from '@/shared/api-client/axios-instance'
import type { ApplyMatchRequest } from '@/shared/api-client/gen/endpoints'
import { resolveTheme } from '@/shared/theme'
import { MusicBrainzMatchDialog } from '../MusicBrainzMatchDialog'
import { ALBUM_CANDIDATES, ALBUM_ID, DUET_ARTIST, DUET_ID, albumComparison, fieldRow, release } from '../musicbrainz/__tests__/match-fixtures'

vi.mock('@/shared/api-client/axios-instance', () => ({
  customInstance: vi.fn(),
}))
vi.mock('sonner', () => ({
  toast: { success: vi.fn(), error: vi.fn(), info: vi.fn() },
}))

const request = vi.mocked(customInstance)
const CANDIDATES_URL = `/api/admin/metadata/match/album/${ALBUM_ID}/candidates`
const COMPARISON_URL = `/api/admin/metadata/match/album/${ALBUM_ID}/comparison?candidate=release-digital`
const OTHER_COMPARISON_URL = `/api/admin/metadata/match/album/${ALBUM_ID}/comparison?candidate=release-cd`
const APPLY_URL = `/api/admin/metadata/match/album/${ALBUM_ID}/apply`
const MERGE_LINE = 'Merge “Post Malone, Luke Combs” into Post Malone'

type Answer = () => Promise<unknown>

let answers: Map<string, Answer>
let client: QueryClient

function httpError(status: number, reason?: string): AxiosError {
  return new AxiosError('Request failed', String(status), undefined, undefined, {
    status,
    statusText: '',
    headers: {},
    config: { headers: new AxiosHeaders() },
    data: { error: { code: status, message: 'Refused.', details: reason === undefined ? {} : { reason } } },
  })
}

function answer(url: string, respond: Answer): void {
  answers.set(url, respond)
}

function mount(onOpenChange: (open: boolean) => void = () => {}) {
  client = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } })

  return render(
    <QueryClientProvider client={client}>
      <ThemeProvider theme={resolveTheme('dark', 'violet')}>
        <MusicBrainzMatchDialog open onOpenChange={onOpenChange} type="album" publicId={ALBUM_ID} title="F-1 Trillion: Long Bed" artist="Post Malone" />
      </ThemeProvider>
    </QueryClientProvider>,
  )
}

/** The body of each apply request sent, the first one first. */
function appliedBodies(): ApplyMatchRequest[] {
  return request.mock.calls
    .filter(([url]) => url === APPLY_URL)
    .map(([, options]) => JSON.parse(String(options?.body)))
}

/** The body of the one apply request sent. */
function appliedBody(): unknown {
  return appliedBodies()[0]
}

function candidateItems(): HTMLElement[] {
  return within(screen.getByRole('list', { name: 'Candidates' })).getAllByRole('listitem')
}

async function openComparison(onOpenChange?: (open: boolean) => void): Promise<void> {
  mount(onOpenChange)
  await screen.findByRole('list', { name: 'Candidates' })
  fireEvent.click(within(candidateItems()[0]).getByRole('button', { name: /Compare/ }))
  await screen.findByRole('checkbox', { name: 'Year' })
}

beforeEach(() => {
  vi.mocked(toast.success).mockReset()
  vi.mocked(toast.error).mockReset()
  answers = new Map()
  answer(CANDIDATES_URL, async () => ({ data: ALBUM_CANDIDATES }))
  answer(COMPARISON_URL, async () => ({ data: albumComparison() }))
  request.mockReset().mockImplementation((url: string) => {
    const respond = answers.get(url)

    return respond === undefined ? Promise.reject(new Error(`Unexpected request ${url}`)) : respond()
  })
})

afterEach(() => {
  cleanup()
  client.clear()
})

describe('MusicBrainzMatchDialog', () => {
  it('searches for the item’s own title and artist and lists the candidates best first with their scores', async () => {
    mount()

    await screen.findByRole('list', { name: 'Candidates' })
    const items = candidateItems()

    expect(request).toHaveBeenCalledWith(CANDIDATES_URL, expect.objectContaining({ method: 'GET' }))
    expect(within(items[0]).getByText('96')).toBeInTheDocument()
    expect(within(items[1]).getByText('81')).toBeInTheDocument()
    expect(screen.getByLabelText('Title')).toHaveValue('F-1 Trillion: Long Bed')
    expect(screen.getByLabelText('Artist')).toHaveValue('Post Malone')
  })

  it('replaces the candidates with those of a refined search', async () => {
    answer(`${CANDIDATES_URL}?title=F-1+Trillion&artist=`, async () => ({
      data: {
        ...ALBUM_CANDIDATES,
        title: 'F-1 Trillion',
        artist: '',
        candidates: [release({ id: 'release-short', score: 74, title: 'F-1 Trillion', trackCount: 18 })],
      },
    }))
    mount()
    await screen.findByRole('list', { name: 'Candidates' })

    fireEvent.change(screen.getByLabelText('Title'), { target: { value: 'F-1 Trillion' } })
    fireEvent.change(screen.getByLabelText('Artist'), { target: { value: '' } })
    fireEvent.click(screen.getByRole('button', { name: 'Search' }))

    await waitFor(() => expect(candidateItems()).toHaveLength(1))
    expect(within(candidateItems()[0]).getByText('74')).toBeInTheDocument()
    expect(within(candidateItems()[0]).getByText('18 tracks')).toBeInTheDocument()
  })

  it('keeps the old candidates visible but unpickable while a refined search runs, and the typed search when it fails', async () => {
    let fail!: (error: unknown) => void
    answer(`${CANDIDATES_URL}?title=Long+Bed&artist=Posty`, () => new Promise((_resolve, reject) => { fail = reject }))
    mount()
    await screen.findByRole('list', { name: 'Candidates' })

    fireEvent.change(screen.getByLabelText('Title'), { target: { value: 'Long Bed' } })
    fireEvent.change(screen.getByLabelText('Artist'), { target: { value: 'Posty' } })
    fireEvent.click(screen.getByRole('button', { name: 'Search' }))

    await waitFor(() => expect(screen.getByRole('list', { name: 'Candidates' })).toHaveAttribute('aria-busy', 'true'))
    expect(candidateItems()).toHaveLength(2)
    for (const button of within(screen.getByRole('list', { name: 'Candidates' })).getAllByRole('button', { name: /Compare/ })) {
      expect(button).toBeDisabled()
    }

    fail(httpError(503, 'musicbrainz_unavailable'))

    expect(await screen.findByRole('alert')).toHaveTextContent('MusicBrainz is unavailable. Try again later.')
    expect(screen.getByLabelText('Title')).toHaveValue('Long Bed')
    expect(screen.getByLabelText('Artist')).toHaveValue('Posty')
  })

  it('names the searched title and artist when MusicBrainz finds no candidate', async () => {
    answer(CANDIDATES_URL, async () => ({ data: { ...ALBUM_CANDIDATES, candidates: [] } }))
    mount()

    expect(await screen.findByText(/No MusicBrainz release matches/)).toHaveTextContent(
      'No MusicBrainz release matches “F-1 Trillion: Long Bed” by “Post Malone”. Edit the title or artist below and search again.',
    )
    expect(screen.getByRole('button', { name: 'Search' })).toBeEnabled()
  })

  it('says MusicBrainz is unavailable when the search answers 503', async () => {
    answer(CANDIDATES_URL, () => Promise.reject(httpError(503, 'musicbrainz_unavailable')))
    mount()

    expect(await screen.findByRole('alert')).toHaveTextContent('MusicBrainz is unavailable. Try again later.')
    expect(screen.getByLabelText('Title')).toHaveValue('F-1 Trillion: Long Bed')
  })

  it('compares the picked candidate, the differing year unticked, the empty genre ticked and the locked title disabled', async () => {
    await openComparison()

    expect(request).toHaveBeenCalledWith(COMPARISON_URL, expect.objectContaining({ method: 'GET' }))
    expect(screen.getByRole('checkbox', { name: 'Year' })).not.toBeChecked()
    expect(screen.getByRole('checkbox', { name: 'Genres' })).toBeChecked()
    expect(screen.getByRole('checkbox', { name: 'Title' })).toBeDisabled()
  })

  it('removes the merge lines once the credits row they require is unticked', async () => {
    await openComparison()

    expect(screen.getByRole('checkbox', { name: MERGE_LINE })).toBeChecked()

    fireEvent.click(screen.getByRole('checkbox', { name: 'Artist credits for “Pour Me a Drink”' }))

    expect(screen.queryByRole('checkbox', { name: MERGE_LINE })).not.toBeInTheDocument()
  })

  it('goes back to the candidates it found', async () => {
    await openComparison()

    fireEvent.click(screen.getByRole('button', { name: 'Back' }))

    expect(candidateItems()).toHaveLength(2)
  })

  it('says MusicBrainz is unavailable when the comparison answers 503, and tries again', async () => {
    let attempts = 0
    answer(COMPARISON_URL, async () => {
      attempts++
      if (attempts === 1) {
        throw httpError(503, 'musicbrainz_unavailable')
      }

      return { data: albumComparison() }
    })
    mount()
    await screen.findByRole('list', { name: 'Candidates' })
    fireEvent.click(within(candidateItems()[0]).getByRole('button', { name: /Compare/ }))

    expect(await screen.findByRole('alert')).toHaveTextContent('MusicBrainz is unavailable. Try again later.')

    fireEvent.click(screen.getByRole('button', { name: 'Try again' }))

    expect(await screen.findByRole('checkbox', { name: 'Year' })).not.toBeChecked()
  })

  it.each([
    ['item_not_found', 'This item no longer exists. Close this dialog and reload the page.'],
    ['candidate_not_found', 'MusicBrainz no longer has this candidate. Go back and search again.'],
  ])('says why the comparison answers 404 with %s', async (reason, message) => {
    answer(COMPARISON_URL, () => Promise.reject(httpError(404, reason)))
    mount()
    await screen.findByRole('list', { name: 'Candidates' })
    fireEvent.click(within(candidateItems()[0]).getByRole('button', { name: /Compare/ }))

    expect(await screen.findByRole('alert')).toHaveTextContent(message)
    expect(screen.getByRole('button', { name: 'Back' })).toBeEnabled()
  })

  it('applies the ticked rows, marks the catalog and review list stale, and closes with a toast naming what changed', async () => {
    answer(APPLY_URL, async () => ({
      data: {
        type: 'album',
        publicId: ALBUM_ID,
        candidateId: 'release-digital',
        fields: ['releaseMbid', 'genres'],
        creditsChanged: false,
        songsChanged: 1,
        mergedArtists: [DUET_ARTIST],
        removedArtists: [],
      },
    }))
    const onOpenChange = vi.fn()
    await openComparison(onOpenChange)
    const invalidate = vi.spyOn(client, 'invalidateQueries')

    fireEvent.click(screen.getByRole('button', { name: 'Apply' }))

    await waitFor(() => expect(onOpenChange).toHaveBeenCalledWith(false))
    expect(appliedBody()).toEqual({
      candidate: 'release-digital',
      fields: [
        { key: 'releaseMbid', current: null },
        { key: 'genres', current: null },
        { key: `${DUET_ID}:mbid`, current: null },
      ],
      credits: [
        {
          key: `${DUET_ID}:credits`,
          current: [{ artistPublicId: DUET_ARTIST, role: 'primary', creditedName: 'Post Malone, Luke Combs' }],
        },
      ],
      artistActions: [DUET_ARTIST],
      keptArtists: [],
    })
    expect(toast.success).toHaveBeenCalledWith('Matched with MusicBrainz: Release ID, Genres, 1 song, 1 artist merged.')
    expect(invalidate).toHaveBeenCalledWith(expect.objectContaining({ queryKey: ['admin-metadata-match-reviews'] }))
    expect(invalidate).toHaveBeenCalledWith(expect.objectContaining({ predicate: expect.any(Function) }))
  })

  it('disables every tick and the apply button while an apply runs', async () => {
    answer(APPLY_URL, () => new Promise(() => {}))
    await openComparison()

    fireEvent.click(screen.getByRole('button', { name: 'Apply' }))

    expect(await screen.findByRole('button', { name: 'Applying…' })).toBeDisabled()
    for (const checkbox of screen.getAllByRole('checkbox')) {
      expect(checkbox).toBeDisabled()
    }
  })

  it('offers to reload a comparison the item outgrew, then resets the ticks and lists the rows that changed', async () => {
    answer(APPLY_URL, () => Promise.reject(httpError(409, 'match_stale')))
    await openComparison()

    fireEvent.click(screen.getByRole('checkbox', { name: 'Year' }))
    fireEvent.click(screen.getByRole('button', { name: 'Apply' }))

    expect(await screen.findByRole('alert')).toHaveTextContent('This item changed since you opened the comparison')
    expect(toast.error).not.toHaveBeenCalled()

    const before = albumComparison()
    answer(COMPARISON_URL, async () => ({
      data: albumComparison({
        fields: before.fields.map((row) => (row.key === 'year' ? fieldRow({ field: 'year', current: 2022, proposed: 2024 }) : row)),
      }),
    }))
    fireEvent.click(screen.getByRole('button', { name: 'Reload comparison' }))

    const notice = await screen.findByRole('status')

    expect(notice).toHaveTextContent('Every tick is back to its default.')
    expect(notice).toHaveTextContent('Changed since then: Year.')
    expect(screen.getByRole('checkbox', { name: 'Year' })).not.toBeChecked()
    expect(screen.queryByRole('alert')).not.toBeInTheDocument()
  })

  it('cancels a pending reload on going back, so its late answer leaves the next candidate’s ticks alone', async () => {
    answer(APPLY_URL, () => Promise.reject(httpError(409, 'match_stale')))
    await openComparison()
    fireEvent.click(screen.getByRole('button', { name: 'Apply' }))
    await screen.findByRole('alert')

    const before = albumComparison()
    let answerReload: (() => void) | undefined
    answer(COMPARISON_URL, () => new Promise((resolve) => {
      answerReload = () => resolve({
        data: albumComparison({
          fields: before.fields.map((row) => (row.key === 'year' ? fieldRow({ field: 'year', current: 2022, proposed: 2024 }) : row)),
        }),
      })
    }))
    answer(OTHER_COMPARISON_URL, async () => ({ data: albumComparison({ candidateId: 'release-cd' }) }))
    fireEvent.click(screen.getByRole('button', { name: 'Reload comparison' }))
    await waitFor(() => expect(answerReload).toBeDefined())
    const reload = request.mock.calls.findLast(([url]) => url === COMPARISON_URL)?.[1]

    fireEvent.click(screen.getByRole('button', { name: 'Back' }))

    expect(reload?.signal?.aborted).toBe(true)

    fireEvent.click(within(candidateItems()[1]).getByRole('button', { name: /Compare/ }))
    fireEvent.click(await screen.findByRole('checkbox', { name: 'Year' }))
    await act(async () => answerReload?.())

    expect(screen.getByRole('checkbox', { name: 'Year' })).toBeChecked()
    expect(screen.queryByRole('status')).not.toBeInTheDocument()
  })

  it('says MusicBrainz is unavailable when the apply answers 503, and keeps the comparison open', async () => {
    answer(APPLY_URL, () => Promise.reject(httpError(503, 'musicbrainz_unavailable')))
    const onOpenChange = vi.fn()
    await openComparison(onOpenChange)

    fireEvent.click(screen.getByRole('button', { name: 'Apply' }))

    await waitFor(() => expect(toast.error).toHaveBeenCalledWith('MusicBrainz is unavailable. Try again later.'))
    expect(onOpenChange).not.toHaveBeenCalled()
    expect(screen.getByRole('button', { name: 'Apply' })).toBeEnabled()
  })

  it('says the Cover Art Archive refused the cover when the apply answers 409, keeps the ticks, and applies the rest once the cover is unticked', async () => {
    const comparison = albumComparison()
    const cover = fieldRow({ field: 'cover', current: null, proposed: 'release-digital', ticked: true })
    answer(COMPARISON_URL, async () => ({ data: { ...comparison, fields: [...comparison.fields, cover] } }))
    answer(APPLY_URL, () => Promise.reject(httpError(409, 'cover_refused')))
    const onOpenChange = vi.fn()
    await openComparison(onOpenChange)

    fireEvent.click(screen.getByRole('checkbox', { name: 'Year' }))
    fireEvent.click(screen.getByRole('button', { name: 'Apply' }))

    await waitFor(() => {
      expect(toast.error).toHaveBeenCalledWith('The Cover Art Archive refused the cover. Nothing was written; untick the cover to apply the rest.')
    })
    expect(onOpenChange).not.toHaveBeenCalled()
    expect(screen.getByRole('checkbox', { name: 'Year' })).toBeChecked()
    expect(screen.getByRole('checkbox', { name: 'Cover' })).toBeChecked()
    expect(screen.getByRole('button', { name: 'Apply' })).toBeEnabled()

    answer(APPLY_URL, async () => ({
      data: {
        type: 'album',
        publicId: ALBUM_ID,
        candidateId: 'release-digital',
        fields: ['releaseMbid', 'year', 'genres'],
        creditsChanged: false,
        songsChanged: 1,
        mergedArtists: [DUET_ARTIST],
        removedArtists: [],
      },
    }))
    fireEvent.click(screen.getByRole('checkbox', { name: 'Cover' }))
    fireEvent.click(screen.getByRole('button', { name: 'Apply' }))

    await waitFor(() => expect(onOpenChange).toHaveBeenCalledWith(false))

    const [refused, applied] = appliedBodies()

    expect(refused.fields).toContainEqual({ key: 'cover', current: null })
    expect(applied).toEqual({ ...refused, fields: refused.fields?.filter((row) => row.key !== 'cover') })
  })

  it.each([
    ['404 for an item that is gone', httpError(404, 'item_not_found'), 'This item no longer exists. Close this dialog and reload the page.'],
    ['404 for a candidate MusicBrainz no longer has', httpError(404, 'candidate_not_found'), 'MusicBrainz no longer has this candidate. Go back and search again.'],
    ['422', httpError(422), 'The server did not accept the selection. Reload the page and match again.'],
    ['404 without a reason', httpError(404), 'The match could not be applied.'],
    ['500', httpError(500), 'The match could not be applied.'],
  ])('says what went wrong when the apply answers %s, and keeps the comparison open', async (_answer, error, message) => {
    answer(APPLY_URL, () => Promise.reject(error))
    const onOpenChange = vi.fn()
    await openComparison(onOpenChange)

    fireEvent.click(screen.getByRole('button', { name: 'Apply' }))

    await waitFor(() => expect(toast.error).toHaveBeenCalledWith(message))
    expect(onOpenChange).not.toHaveBeenCalled()
    expect(screen.getByRole('button', { name: 'Apply' })).toBeEnabled()
  })
})
