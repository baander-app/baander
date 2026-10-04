import { cleanup, render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import type { UseSongListResult } from '../../hooks/use-song-list'
import { ListView } from '../ListView'

const { mockUseSongList, fetchMore, retry } = vi.hoisted(() => ({
  mockUseSongList: vi.fn(), fetchMore: vi.fn(), retry: vi.fn(),
}))
vi.mock('../../hooks/use-song-list', () => ({ useSongList: mockUseSongList }))
vi.mock('../../components/ListHeader', () => ({ ListHeader: () => <div>Song list</div> }))
vi.mock('../../components/ListRow', () => ({ ListRow: ({ song }: { song: { title: string } }) => <div>{song.title}</div> }))
vi.mock('@tanstack/react-virtual', () => ({
  useVirtualizer: () => ({
    getVirtualItems: () => [{ index: 0, size: 32, start: 0 }],
    getTotalSize: () => 32,
  }),
}))

function result(overrides: Partial<UseSongListResult> = {}): UseSongListResult {
  return {
    songs: [{ publicId: 'song-1', title: 'Loaded song', index: 1 }],
    total: 2, isLoading: false, isFetchingMore: false, hasNextPage: true,
    isError: false, isFetchMoreError: false, fetchMore, retry,
    ...overrides,
  }
}

beforeEach(() => vi.resetAllMocks())
afterEach(cleanup)

describe('song list error recovery', () => {
  it('offers initial retry instead of reporting an empty collection', async () => {
    mockUseSongList.mockReturnValue(result({ songs: [], isError: true }))
    render(<ListView />)
    expect(screen.getByRole('alert')).toHaveTextContent('Could not load songs.')
    expect(screen.queryByText('No songs')).not.toBeInTheDocument()
    await userEvent.click(screen.getByRole('button', { name: 'Retry' }))
    expect(retry).toHaveBeenCalledOnce()
    expect(fetchMore).not.toHaveBeenCalled()
  })

  it('preserves loaded rows and waits for explicit retry after a page failure', async () => {
    mockUseSongList.mockReturnValue(result({ isError: true, isFetchMoreError: true }))
    const view = render(<ListView />)
    view.rerender(<ListView />)
    expect(screen.getByText('Loaded song')).toBeInTheDocument()
    expect(screen.getByRole('alert')).toHaveTextContent('Could not load more songs.')
    expect(fetchMore).not.toHaveBeenCalled()
    await userEvent.click(screen.getByRole('button', { name: 'Retry' }))
    expect(fetchMore).toHaveBeenCalledOnce()
    expect(retry).not.toHaveBeenCalled()
  })

  it('retries a refresh without dropping loaded rows', async () => {
    mockUseSongList.mockReturnValue(result({ isError: true }))
    render(<ListView />)
    expect(screen.getByText('Loaded song')).toBeInTheDocument()
    expect(screen.getByRole('alert')).toHaveTextContent('Could not refresh songs.')
    await userEvent.click(screen.getByRole('button', { name: 'Retry' }))
    expect(retry).toHaveBeenCalledOnce()
    expect(fetchMore).not.toHaveBeenCalled()
  })
})
