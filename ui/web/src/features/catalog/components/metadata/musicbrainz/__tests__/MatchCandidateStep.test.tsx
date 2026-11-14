import { cleanup, fireEvent, render, screen, within } from '@testing-library/react'
import type { ReactElement } from 'react'
import { ThemeProvider } from 'styled-components'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { resolveTheme } from '@/shared/theme'
import { MatchCandidateStep, type MatchCandidateStepProps } from '../MatchCandidateStep'
import type { MatchCandidates } from '../types'
import { ALBUM_CANDIDATES, ALBUM_ID, release } from './match-fixtures'

afterEach(cleanup)

function themed(element: ReactElement): ReactElement {
  return <ThemeProvider theme={resolveTheme('dark', 'violet')}>{element}</ThemeProvider>
}

function props(overrides: Partial<MatchCandidateStepProps> = {}): MatchCandidateStepProps {
  return {
    type: 'album',
    initialSearch: { title: 'F-1 Trillion: Long Bed', artist: 'Post Malone' },
    candidates: ALBUM_CANDIDATES,
    searching: false,
    error: null,
    onSearch: vi.fn(),
    onPick: vi.fn(),
    ...overrides,
  }
}

function candidateItems(): HTMLElement[] {
  return within(screen.getByRole('list', { name: 'Candidates' })).getAllByRole('listitem')
}

function refine(title: string, artist: string): void {
  fireEvent.change(screen.getByLabelText('Title'), { target: { value: title } })
  fireEvent.change(screen.getByLabelText('Artist'), { target: { value: artist } })
  fireEvent.click(screen.getByRole('button', { name: 'Search' }))
}

describe('MatchCandidateStep', () => {
  it('lists the candidates best first, each with its score and edition details', () => {
    render(themed(<MatchCandidateStep {...props()} />))

    const items = candidateItems()

    expect(items).toHaveLength(2)
    expect(within(items[0]).getByText('96')).toBeInTheDocument()
    expect(within(items[0]).getByText(/Score/)).toBeInTheDocument()
    expect(within(items[0]).getByText('Digital Media')).toBeInTheDocument()
    expect(within(items[0]).getByText('27 tracks')).toBeInTheDocument()
    expect(within(items[1]).getByText('81')).toBeInTheDocument()
    expect(within(items[1]).getByText('2024-09-20')).toBeInTheDocument()
    expect(within(items[1]).getByText('US')).toBeInTheDocument()
    expect(within(items[1]).getByText('Mercury Records')).toBeInTheDocument()
    expect(within(items[1]).getByText('Republic Records')).toBeInTheDocument()
    expect(within(items[1]).getByText('CD')).toBeInTheDocument()
    expect(within(items[1]).getByText('2 media')).toBeInTheDocument()
    expect(within(items[1]).getByText('Official')).toBeInTheDocument()
    expect(within(items[1]).getByText('(clean)')).toBeInTheDocument()
  })

  it('compares the candidate the admin picks', () => {
    const onPick = vi.fn()
    render(themed(<MatchCandidateStep {...props({ onPick })} />))

    fireEvent.click(within(candidateItems()[1]).getByRole('button', { name: /Compare/ }))

    expect(onPick).toHaveBeenCalledWith('release-cd')
  })

  it('searches again with the edited title and artist, and shows the new candidates in place of the old', () => {
    const onSearch = vi.fn()
    const { rerender } = render(themed(<MatchCandidateStep {...props({ onSearch })} />))

    refine('F-1 Trillion', 'Post Malone')

    expect(onSearch).toHaveBeenCalledWith({ title: 'F-1 Trillion', artist: 'Post Malone' })

    const refined: MatchCandidates = {
      ...ALBUM_CANDIDATES,
      title: 'F-1 Trillion',
      candidates: [release({ id: 'release-short', score: 88, title: 'F-1 Trillion', trackCount: 18 })],
    }
    rerender(themed(<MatchCandidateStep {...props({ onSearch, candidates: refined })} />))

    const items = candidateItems()

    expect(items).toHaveLength(1)
    expect(within(items[0]).getByText('88')).toBeInTheDocument()
    expect(within(items[0]).getByText('18 tracks')).toBeInTheDocument()
  })

  it('keeps the old candidates visible but unpickable while a refined search runs', () => {
    render(themed(<MatchCandidateStep {...props({ searching: true })} />))

    const list = screen.getByRole('list', { name: 'Candidates' })

    expect(list).toHaveAttribute('aria-busy', 'true')
    expect(candidateItems()).toHaveLength(2)
    for (const button of within(list).getAllByRole('button', { name: /Compare/ })) {
      expect(button).toBeDisabled()
    }
    expect(screen.getByRole('button', { name: 'Search' })).toBeDisabled()
  })

  it('keeps the edited title and artist when the refined search fails', () => {
    const onSearch = vi.fn()
    const { rerender } = render(themed(<MatchCandidateStep {...props({ onSearch })} />))

    refine('Long Bed', 'Posty')
    rerender(themed(<MatchCandidateStep {...props({ onSearch, error: 'MusicBrainz is unavailable. Try again later.' })} />))

    expect(screen.getByRole('alert')).toHaveTextContent('MusicBrainz is unavailable. Try again later.')
    expect(screen.getByLabelText('Title')).toHaveValue('Long Bed')
    expect(screen.getByLabelText('Artist')).toHaveValue('Posty')
  })

  it('names the searched title and artist when MusicBrainz has no candidate, and points to the refine form', () => {
    const empty: MatchCandidates = { type: 'album', publicId: ALBUM_ID, title: 'Long Bed Demos', artist: 'Posty', candidates: [] }
    render(themed(<MatchCandidateStep {...props({ candidates: empty })} />))

    const message = screen.getByText(/No MusicBrainz release matches/)

    expect(message).toHaveTextContent('No MusicBrainz release matches “Long Bed Demos” by “Posty”. Edit the title or artist below and search again.')
    expect(screen.queryByRole('list', { name: 'Candidates' })).not.toBeInTheDocument()
    expect(screen.getByLabelText('Title')).toHaveValue('Long Bed Demos')
    expect(screen.getByLabelText('Artist')).toHaveValue('Posty')
  })

  it('shows placeholders, not an empty list, while the first search runs', () => {
    render(themed(<MatchCandidateStep {...props({ candidates: undefined, searching: true })} />))

    expect(screen.queryByText(/No MusicBrainz release matches/)).not.toBeInTheDocument()
    expect(screen.getByRole('status')).toHaveTextContent('Searching MusicBrainz')
  })

  it('shows a recording with its length and the releases it appears on', () => {
    const recordings: MatchCandidates = {
      type: 'song',
      publicId: 'SongDuet00001',
      title: 'Pour Me a Drink',
      artist: 'Post Malone',
      candidates: [
        {
          id: 'rec-pour-me-a-drink',
          score: 93,
          title: 'Pour Me a Drink',
          artistCredit: 'Post Malone feat. Blake Shelton',
          lengthMs: 213_000,
          disambiguation: 'explicit',
          releases: [
            { id: 'r1', title: 'F-1 Trillion', date: '2024-08-16', country: 'XW', status: 'Official', trackCount: 18 },
            { id: 'r2', title: 'F-1 Trillion: Long Bed', date: '2024-08-16', country: 'XW', status: 'Official', trackCount: 27 },
            { id: 'r3', title: 'Pour Me a Drink', date: '2024-06-28', country: 'US', status: 'Promotion', trackCount: 1 },
            { id: 'r4', title: 'Country Hits 2024', date: '2024-10-01', country: 'GB', status: 'Official', trackCount: 40 },
          ],
        },
      ],
    }
    render(themed(<MatchCandidateStep {...props({ type: 'song', candidates: recordings })} />))

    const item = candidateItems()[0]

    expect(within(item).getByText('93')).toBeInTheDocument()
    expect(within(item).getByText('03:33')).toBeInTheDocument()
    expect(within(item).getByText('F-1 Trillion: Long Bed')).toBeInTheDocument()
    expect(within(item).getByText('and 1 more release')).toBeInTheDocument()
  })

  it('searches an artist by name alone', () => {
    const onSearch = vi.fn()
    const artists: MatchCandidates = {
      type: 'artist',
      publicId: 'ArtistPostMal1',
      title: 'Post Malone',
      artist: null,
      candidates: [
        {
          id: 'b1e26560-60e5-4236-bbdb-9aa5a8d5ee19',
          score: 100,
          name: 'Post Malone',
          sortName: 'Malone, Post',
          disambiguation: 'US rapper',
          type: 'Person',
          country: 'US',
          lifeSpanBegin: '1995-07-04',
          lifeSpanEnd: null,
          ended: false,
        },
      ],
    }
    render(themed(<MatchCandidateStep {...props({ type: 'artist', initialSearch: { title: 'Post Malone', artist: null }, candidates: artists, onSearch })} />))

    const item = candidateItems()[0]

    expect(within(item).getByText('Person')).toBeInTheDocument()
    expect(within(item).getByText('1995–present')).toBeInTheDocument()
    expect(screen.queryByLabelText('Artist')).not.toBeInTheDocument()

    fireEvent.change(screen.getByLabelText('Name'), { target: { value: 'Austin Post' } })
    fireEvent.click(screen.getByRole('button', { name: 'Search' }))

    expect(onSearch).toHaveBeenCalledWith({ title: 'Austin Post', artist: null })
  })
})
