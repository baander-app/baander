import { cleanup, render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { ActivityView } from '../ActivityView'

const { useActivityViewModel, loadMore, refetch } = vi.hoisted(() => ({
  useActivityViewModel: vi.fn(), loadMore: vi.fn(), refetch: vi.fn(),
}))
vi.mock('../../hooks/use-activity-view-model', () => ({ useActivityViewModel }))
vi.mock('../../components/ActivityGroup', () => ({
  ActivityGroup: ({ items }: { items: { uuid: string }[] }) => items.map((item) => <div key={item.uuid}>{item.uuid}</div>),
}))

beforeEach(() => vi.resetAllMocks())
afterEach(cleanup)

const loadedGroups = [{ label: 'Today', items: [{ uuid: 'loaded-history' }] }]

describe('activity history recovery', () => {
  it.each([true, false])('retains history when a request fails (next page: %s)', async (isFetchMoreError) => {
    useActivityViewModel.mockReturnValue({
      groups: loadedGroups, isLoading: false, error: new Error('Unavailable'),
      isFetchingMore: false, isFetchMoreError, hasMore: true, loadMore, refetch,
    })
    render(<ActivityView />)
    expect(screen.getByText('loaded-history')).toBeInTheDocument()
    expect(screen.getByRole('alert')).toHaveTextContent(isFetchMoreError ? 'Failed to load more activity' : 'Failed to refresh activity history')
    expect(screen.queryByRole('button', { name: 'Load more' })).not.toBeInTheDocument()
    await userEvent.click(screen.getByRole('button', { name: 'Retry' }))
    expect(isFetchMoreError ? loadMore : refetch).toHaveBeenCalledOnce()
    expect(isFetchMoreError ? refetch : loadMore).not.toHaveBeenCalled()
  })

  it('disables load more while a page is pending', () => {
    useActivityViewModel.mockReturnValue({
      groups: loadedGroups, isLoading: false, error: null,
      isFetchingMore: true, isFetchMoreError: false, hasMore: true, loadMore, refetch,
    })
    render(<ActivityView />)
    expect(screen.getByRole('button', { name: 'Loading…' })).toBeDisabled()
    expect(screen.getByText('loaded-history')).toBeInTheDocument()
  })
})
