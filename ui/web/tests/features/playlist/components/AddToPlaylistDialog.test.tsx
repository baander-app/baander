import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { ThemeProvider as SCTypedThemeProvider } from 'styled-components'
import { resolveTheme } from '@/shared/theme/resolve-theme'

const testTheme = resolveTheme('dark', 'violet')

const mockUseGetPlaylistIndex = vi.fn()
const mockUsePostPlaylistAddSong = vi.fn()
const mockUsePostPlaylistStore = vi.fn()

vi.mock('@/shared/api-client/gen/endpoints', () => ({
  useGetPlaylistIndex: (...args: unknown[]) => mockUseGetPlaylistIndex(...args),
  usePostPlaylistAddSong: (...args: unknown[]) => mockUsePostPlaylistAddSong(...args),
  usePostPlaylistStore: (...args: unknown[]) => mockUsePostPlaylistStore(...args),
}))

vi.mock('sonner', () => ({
  toast: {
    success: vi.fn(),
    error: vi.fn(),
  },
}))

import { AddToPlaylistDialog } from '@/features/playlist/components/AddToPlaylistDialog'

function mockMutation(overrides = {}) {
  return {
    mutateAsync: vi.fn().mockResolvedValue({ data: { publicId: 'pl_new' } }),
    isPending: false,
    ...overrides,
  }
}

describe('AddToPlaylistDialog', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    mockUsePostPlaylistAddSong.mockReturnValue(mockMutation())
    mockUsePostPlaylistStore.mockReturnValue(mockMutation())
  })

  it('renders dialog title and description when open', () => {
    mockUseGetPlaylistIndex.mockReturnValue({
      data: { data: [] },
      isLoading: false,
    })

    render(
      <SCTypedThemeProvider theme={testTheme}>
        <AddToPlaylistDialog open={true} onOpenChange={vi.fn()} songId="song_1" />
      </SCTypedThemeProvider>,
    )

    expect(screen.getByText('Add to Playlist')).toBeInTheDocument()
    expect(screen.getByText(/Choose an existing playlist/)).toBeInTheDocument()
  })

  it('shows playlists from API', () => {
    mockUseGetPlaylistIndex.mockReturnValue({
      data: {
        data: [
          { publicId: 'pl_1', name: 'My Playlist', isSmart: false },
          { publicId: 'pl_2', name: 'Favorites', isSmart: false },
          { publicId: 'pl_3', name: 'Auto Mix', isSmart: true },
        ],
      },
      isLoading: false,
    })

    render(
      <SCTypedThemeProvider theme={testTheme}>
        <AddToPlaylistDialog open={true} onOpenChange={vi.fn()} songId="song_1" />
      </SCTypedThemeProvider>,
    )

    expect(screen.getByText('My Playlist')).toBeInTheDocument()
    expect(screen.getByText('Favorites')).toBeInTheDocument()
    // Smart playlists should be excluded from the list
    expect(screen.queryByText('Auto Mix')).not.toBeInTheDocument()
  })

  it('shows loading skeleton while fetching playlists', () => {
    mockUseGetPlaylistIndex.mockReturnValue({
      data: undefined,
      isLoading: true,
    })

    render(
      <SCTypedThemeProvider theme={testTheme}>
        <AddToPlaylistDialog open={true} onOpenChange={vi.fn()} songId="song_1" />
      </SCTypedThemeProvider>,
    )

    // styled-components no longer uses .animate-pulse class; check for data-slot="skeleton"
    const skeletonElements = document.querySelectorAll('[data-slot="skeleton"]')
    expect(skeletonElements.length).toBeGreaterThan(0)
  })

  it('shows empty message when no regular playlists exist', () => {
    mockUseGetPlaylistIndex.mockReturnValue({
      data: { data: [{ publicId: 'pl_1', name: 'Smart Mix', isSmart: true }] },
      isLoading: false,
    })

    render(
      <SCTypedThemeProvider theme={testTheme}>
        <AddToPlaylistDialog open={true} onOpenChange={vi.fn()} songId="song_1" />
      </SCTypedThemeProvider>,
    )

    expect(screen.getByText(/No playlists yet/)).toBeInTheDocument()
  })

  it('shows create playlist form', () => {
    mockUseGetPlaylistIndex.mockReturnValue({
      data: { data: [] },
      isLoading: false,
    })

    render(
      <SCTypedThemeProvider theme={testTheme}>
        <AddToPlaylistDialog open={true} onOpenChange={vi.fn()} songId="song_1" />
      </SCTypedThemeProvider>,
    )

    expect(screen.getByPlaceholderText('New playlist name')).toBeInTheDocument()
    expect(screen.getByText('Create')).toBeInTheDocument()
  })
})
