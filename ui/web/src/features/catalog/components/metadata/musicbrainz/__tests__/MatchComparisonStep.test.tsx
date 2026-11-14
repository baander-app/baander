import { cleanup, fireEvent, render, screen, within } from '@testing-library/react'
import type { ReactElement } from 'react'
import { ThemeProvider } from 'styled-components'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { resolveTheme } from '@/shared/theme'
import { MatchComparisonStep, type MatchComparisonStepProps } from '../MatchComparisonStep'
import { creditsRowKey, fieldRowKey } from '../match-ticks'
import type { MatchComparison } from '../types'
import { DUET_ARTIST, DUET_CREDITS, DUET_ID, MERGE_DUET_ARTIST, albumComparison, fieldRow, longAlbumComparison } from './match-fixtures'

/** Renders the first ten rows of a virtual list, as a short scroll viewport would. */
const VISIBLE_ROWS = 10

vi.mock('@tanstack/react-virtual', () => ({
  useVirtualizer: ({ count, estimateSize }: { count: number; estimateSize: (index: number) => number }) => ({
    getTotalSize: () => count * estimateSize(0),
    getVirtualItems: () =>
      Array.from({ length: Math.min(count, VISIBLE_ROWS) }, (_, index) => ({
        index,
        key: index,
        size: estimateSize(index),
        start: index * estimateSize(index),
      })),
    measureElement: () => {},
  }),
}))

afterEach(cleanup)

function themed(element: ReactElement): ReactElement {
  return <ThemeProvider theme={resolveTheme('dark', 'violet')}>{element}</ThemeProvider>
}

function props(overrides: Partial<MatchComparisonStepProps> = {}): MatchComparisonStepProps {
  return {
    comparison: albumComparison(),
    applying: false,
    onApply: vi.fn(),
    ...overrides,
  }
}

const MERGE_LINE = 'Merge “Post Malone, Luke Combs” into Post Malone'
const DUET_CREDITS_ROW = 'Artist credits for “Pour Me a Drink”'

function trackItems(): HTMLElement[] {
  return within(screen.getByRole('list', { name: 'Track pairing' })).getAllByRole('listitem')
}

describe('MatchComparisonStep', () => {
  it('leaves the differing year unticked, ticks the empty genre, and locks the locked title', () => {
    render(themed(<MatchComparisonStep {...props()} />))

    expect(screen.getByRole('checkbox', { name: 'Year' })).not.toBeChecked()
    expect(screen.getByRole('checkbox', { name: 'Year' })).toBeEnabled()
    expect(screen.getByRole('checkbox', { name: 'Genres' })).toBeChecked()
    expect(screen.getByRole('checkbox', { name: 'Title' })).not.toBeChecked()
    expect(screen.getByRole('checkbox', { name: 'Title' })).toBeDisabled()
    expect(screen.getByRole('checkbox', { name: 'Release ID' })).toBeChecked()
  })

  it('shows the current and the MusicBrainz value of each field', () => {
    render(themed(<MatchComparisonStep {...props()} />))

    const year = screen.getByRole('checkbox', { name: 'Year' }).closest('[data-match-row]')
    const title = screen.getByRole('checkbox', { name: 'Title' }).closest('[data-match-row]')

    expect(year).toHaveTextContent('2023')
    expect(year).toHaveTextContent('2024')
    expect(title).toHaveTextContent('F-1 Trillion (Long Bed)')
    expect(title).toHaveTextContent('F-1 Trillion: Long Bed')
    expect(title).toHaveTextContent('Locked')
  })

  it('applies the genre and keeps the year by default', () => {
    const onApply = vi.fn()
    render(themed(<MatchComparisonStep {...props({ onApply })} />))

    fireEvent.click(screen.getByRole('button', { name: 'Apply' }))

    const [selection] = onApply.mock.calls[0]
    const ownFields = selection.fields.filter((row: { songPublicId: string | null }) => row.songPublicId === null)

    expect(ownFields).toEqual([
      { field: 'releaseMbid', current: null, songPublicId: null },
      { field: 'genres', current: null, songPublicId: null },
    ])
  })

  it('applies the year once the admin ticks it', () => {
    const onApply = vi.fn()
    render(themed(<MatchComparisonStep {...props({ onApply })} />))

    fireEvent.click(screen.getByRole('checkbox', { name: 'Year' }))
    fireEvent.click(screen.getByRole('button', { name: 'Apply' }))

    expect(onApply.mock.calls[0][0].fields).toContainEqual({ field: 'year', current: 2023, songPublicId: null })
  })

  it('shows a song’s current credits against the primary and featured artists MusicBrainz credits', () => {
    render(themed(<MatchComparisonStep {...props()} />))

    const row = screen.getByRole('checkbox', { name: DUET_CREDITS_ROW }).closest('[data-match-row]') as HTMLElement

    expect(screen.getByRole('checkbox', { name: DUET_CREDITS_ROW })).toBeChecked()
    expect(within(row).getAllByText('Post Malone, Luke Combs').length).toBeGreaterThan(0)
    expect(within(row).getByText('Post Malone')).toBeInTheDocument()
    expect(within(row).getByText('Luke Combs')).toBeInTheDocument()
    expect(row).toHaveTextContent('featured')
    expect(row).toHaveTextContent('new artist')
  })

  it('removes the merge lines a credits row requires once it is unticked', () => {
    const onApply = vi.fn()
    render(themed(<MatchComparisonStep {...props({ onApply })} />))

    expect(screen.getByRole('checkbox', { name: MERGE_LINE })).toBeChecked()

    fireEvent.click(screen.getByRole('checkbox', { name: DUET_CREDITS_ROW }))

    expect(screen.queryByRole('checkbox', { name: MERGE_LINE })).not.toBeInTheDocument()

    fireEvent.click(screen.getByRole('button', { name: 'Apply' }))

    expect(onApply.mock.calls[0][0]).toMatchObject({ credits: [], artistActions: [], keptArtists: [DUET_ARTIST] })
  })

  it('keeps an artist whose merge the admin unticks', () => {
    const onApply = vi.fn()
    render(themed(<MatchComparisonStep {...props({ onApply })} />))

    fireEvent.click(screen.getByRole('checkbox', { name: MERGE_LINE }))
    fireEvent.click(screen.getByRole('button', { name: 'Apply' }))

    expect(onApply.mock.calls[0][0]).toMatchObject({ artistActions: [], keptArtists: [DUET_ARTIST] })
  })

  it('disables every tick and the apply button while an apply runs', () => {
    const onApply = vi.fn()
    render(themed(<MatchComparisonStep {...props({ applying: true, onApply })} />))

    const apply = screen.getByRole('button', { name: 'Applying…' })

    expect(apply).toBeDisabled()
    for (const checkbox of screen.getAllByRole('checkbox')) {
      expect(checkbox).toBeDisabled()
    }

    fireEvent.click(apply)

    expect(onApply).not.toHaveBeenCalled()
  })

  it('pairs the tracks and shows the unpaired ones on either side', () => {
    render(themed(<MatchComparisonStep {...props()} />))

    const items = trackItems()

    expect(items).toHaveLength(4)
    expect(within(items[0]).getAllByText('Pour Me a Drink')).toHaveLength(2)
    expect(within(items[1]).getByText('No changes')).toBeInTheDocument()
    expect(within(items[2]).getByText('Finer Things')).toBeInTheDocument()
    expect(items[2]).toHaveTextContent('Not in your library')
    expect(within(items[3]).getByText('Bonus Demo')).toBeInTheDocument()
    expect(items[3]).toHaveTextContent('No track on this release')
  })

  it('offers a paired song’s changing rows only', () => {
    render(themed(<MatchComparisonStep {...props()} />))

    expect(screen.getByRole('checkbox', { name: 'Recording ID for “Pour Me a Drink”' })).toBeChecked()
    expect(screen.queryByRole('checkbox', { name: 'Title for “Pour Me a Drink”' })).not.toBeInTheDocument()
    expect(screen.queryByRole('checkbox', { name: 'Track for “Pour Me a Drink”' })).not.toBeInTheDocument()
  })

  it('renders every track of an album of 50', () => {
    render(themed(<MatchComparisonStep {...props({ comparison: longAlbumComparison(50) })} />))

    expect(trackItems()).toHaveLength(50)
  })

  it('renders only the visible tracks of a longer album', () => {
    render(themed(<MatchComparisonStep {...props({ comparison: longAlbumComparison(51) })} />))

    expect(trackItems()).toHaveLength(VISIBLE_ROWS)
  })

  it('lists the rows that changed when the comparison was reloaded', () => {
    const changedRowKeys = [fieldRowKey({ field: 'year', songPublicId: null }), creditsRowKey(DUET_CREDITS)]
    render(themed(<MatchComparisonStep {...props({ changedRowKeys })} />))

    const notice = screen.getByRole('status')

    expect(notice).toHaveTextContent('This item changed since you opened the comparison')
    expect(notice).toHaveTextContent('Year')
    expect(notice).toHaveTextContent(DUET_CREDITS_ROW)
  })

  it('merges the artist a song match leaves without credits while the song’s credits stay ticked', () => {
    const comparison: MatchComparison = {
      type: 'song',
      publicId: DUET_ID,
      candidateId: 'rec-pour-me-a-drink',
      fields: [fieldRow({ field: 'mbid', proposed: 'rec-pour-me-a-drink', ticked: true })],
      credits: DUET_CREDITS,
      tracks: [],
      artistActions: [MERGE_DUET_ARTIST],
    }
    const onApply = vi.fn()
    render(themed(<MatchComparisonStep {...props({ comparison, onApply })} />))

    expect(screen.getByRole('checkbox', { name: 'Recording ID' })).toBeChecked()
    expect(screen.getByRole('checkbox', { name: MERGE_LINE })).toBeChecked()

    fireEvent.click(screen.getByRole('button', { name: 'Apply' }))

    expect(onApply.mock.calls[0][0]).toMatchObject({
      fields: [{ field: 'mbid', current: null, songPublicId: null }],
      credits: [{ songPublicId: DUET_ID }],
      artistActions: [DUET_ARTIST],
      keptArtists: [],
    })

    fireEvent.click(screen.getByRole('checkbox', { name: 'Artist credits' }))

    expect(screen.queryByRole('checkbox', { name: MERGE_LINE })).not.toBeInTheDocument()
  })

  it('shows the merge an artist match applies its ID by', () => {
    const comparison: MatchComparison = {
      type: 'artist',
      publicId: DUET_ARTIST,
      candidateId: 'b1e26560-60e5-4236-bbdb-9aa5a8d5ee19',
      fields: [],
      credits: null,
      tracks: [],
      artistActions: [{ ...MERGE_DUET_ARTIST, requiredSongs: [] }],
    }
    const onApply = vi.fn()
    render(themed(<MatchComparisonStep {...props({ comparison, onApply })} />))

    expect(screen.getByRole('checkbox', { name: MERGE_LINE })).toBeChecked()
    expect(screen.queryByRole('list', { name: 'Track pairing' })).not.toBeInTheDocument()

    fireEvent.click(screen.getByRole('button', { name: 'Apply' }))

    expect(onApply.mock.calls[0][0]).toMatchObject({ artistActions: [DUET_ARTIST], keptArtists: [] })
  })

  it('goes back to the candidates', () => {
    const onBack = vi.fn()
    render(themed(<MatchComparisonStep {...props({ onBack })} />))

    fireEvent.click(screen.getByRole('button', { name: 'Back' }))

    expect(onBack).toHaveBeenCalled()
  })
})
