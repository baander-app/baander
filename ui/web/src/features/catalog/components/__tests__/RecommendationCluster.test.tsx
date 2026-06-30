import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, screen, fireEvent } from '@testing-library/react'
import userEvent from '@testing-library/user-event'

const mockSelect = vi.fn()
const mockNavigate = vi.fn()

vi.mock('@/features/catalog/stores/selection-store', () => ({
  useSelectionStore: (selector: (s: Record<string, unknown>) => unknown) =>
    selector({
      selectedId: null,
      selectedType: null,
      select: mockSelect,
      clear: vi.fn(),
    }),
}))

vi.mock('react-router-dom', () => ({
  useNavigate: () => mockNavigate,
}))

import { RecommendationClusterRow } from '../RecommendationCluster'
import type { RecommendationCluster } from '../../types/recommendation'

describe('RecommendationCluster', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  const albumCluster: RecommendationCluster = {
    sourceId: 'album-a',
    sourceType: 'album',
    sourceName: 'Album A',
    items: [
      {
        id: 'rec-1',
        name: 'Because you listened to Album A',
        source_type: 'album',
        source_id: 'album-a',
        target_type: 'album',
        target_id: 'album-b',
        score: 0.9,
        position: 1,
        user_id: null,
        created_at: '',
        updated_at: '',
      },
      {
        id: 'rec-2',
        name: 'Because you listened to Album A',
        source_type: 'album',
        source_id: 'album-a',
        target_type: 'album',
        target_id: 'album-c',
        score: 0.8,
        position: 2,
        user_id: null,
        created_at: '',
        updated_at: '',
      },
    ],
  }

  const artistCluster: RecommendationCluster = {
    sourceId: 'artist-x',
    sourceType: 'artist',
    sourceName: 'Artist X',
    items: [
      {
        id: 'rec-3',
        name: 'Similar to Artist X',
        source_type: 'artist',
        source_id: 'artist-x',
        target_type: 'artist',
        target_id: 'artist-y',
        score: 0.7,
        position: 1,
        user_id: null,
        created_at: '',
        updated_at: '',
      },
    ],
  }

  it('renders "Because you listened to" header for album source', () => {
    render(<RecommendationClusterRow cluster={albumCluster} />)
    expect(screen.getByText('Because you listened to Album A')).toBeInTheDocument()
  })

  it('renders "Similar to" header for artist source', () => {
    render(<RecommendationClusterRow cluster={artistCluster} />)
    expect(screen.getByText('Similar to Artist X')).toBeInTheDocument()
  })

  it('renders each recommended item', () => {
    render(<RecommendationClusterRow cluster={albumCluster} />)
    expect(screen.getByText('album-b')).toBeInTheDocument()
    expect(screen.getByText('album-c')).toBeInTheDocument()
  })

  it('calls select on item click', async () => {
    render(<RecommendationClusterRow cluster={albumCluster} />)
    const items = screen.getAllByRole('button')
    await userEvent.setup().click(items[0])
    expect(mockSelect).toHaveBeenCalledWith('album-b', 'album')
  })

  it('navigates to album detail on Enter', () => {
    render(<RecommendationClusterRow cluster={albumCluster} />)
    const items = screen.getAllByRole('button')
    fireEvent.keyDown(items[0], { key: 'Enter' })
    expect(mockNavigate).toHaveBeenCalledWith('/albums/album-b')
  })

  it('navigates to artist detail on Enter for artist target', () => {
    render(<RecommendationClusterRow cluster={artistCluster} />)
    const items = screen.getAllByRole('button')
    fireEvent.keyDown(items[0], { key: 'Enter' })
    expect(mockNavigate).toHaveBeenCalledWith('/artists/artist-y')
  })

  it('navigates on Space key', () => {
    render(<RecommendationClusterRow cluster={albumCluster} />)
    const items = screen.getAllByRole('button')
    fireEvent.keyDown(items[0], { key: ' ' })
    expect(mockNavigate).toHaveBeenCalledWith('/albums/album-b')
  })
})
