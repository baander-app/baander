import { act, cleanup, render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { ThemeProvider } from 'styled-components'
import { resolveTheme } from '@/shared/theme/resolve-theme'
import { AXIOS_INSTANCE } from '@/shared/api-client/axios-instance'
import type { AlbumSummary, DuplicateGroup } from '@/features/admin/api/album-duplicates-api'
import { useMergeStore } from '../../stores/merge-store'
import { DuplicateWarningBanner } from '../DuplicateWarningBanner'
import { MergeAlbumsDialog } from '../MergeAlbumsDialog'

vi.mock('@/shared/api-client/axios-instance', () => ({
  AXIOS_INSTANCE: { post: vi.fn() },
}))
vi.mock('sonner', () => ({
  toast: { success: vi.fn(), error: vi.fn() },
}))

const mockPost = vi.mocked(AXIOS_INSTANCE.post)

const VIEWED: AlbumSummary = {
  uuid: '0199bf3c-8a00-7000-8000-0000000000c3',
  publicId: 'viewedAlbumPublicId03',
  title: 'Kind of Blue',
  year: 1959,
  lockedFields: [],
  createdAt: '2026-10-01T00:00:00+00:00',
  coverImage: null,
}

const DUPLICATE: AlbumSummary = {
  uuid: '0199bf3c-8a00-7000-8000-0000000000d4',
  publicId: 'otherAlbumPublicId004',
  title: 'Kind of Blue (Legacy Edition)',
  year: 1959,
  lockedFields: [],
  createdAt: '2026-10-02T00:00:00+00:00',
  coverImage: null,
}

const GROUP: DuplicateGroup = {
  albumIds: [VIEWED.uuid, DUPLICATE.uuid],
  confidence: 0.9,
  albumCount: 2,
  albums: [VIEWED, DUPLICATE],
}

let client: QueryClient

function mount() {
  client = new QueryClient({ defaultOptions: { queries: { retry: false } } })

  return render(
    <QueryClientProvider client={client}>
      <ThemeProvider theme={resolveTheme('dark', 'violet')}>
        <DuplicateWarningBanner
          duplicateGroups={[GROUP]}
          albumTitle={VIEWED.title}
          albumPublicId={VIEWED.publicId}
        />
        <MergeAlbumsDialog />
      </ThemeProvider>
    </QueryClientProvider>,
  )
}

describe('DuplicateWarningBanner', () => {
  beforeEach(() => {
    mockPost.mockReset()
    mockPost.mockResolvedValue({ data: { data: { publicId: DUPLICATE.publicId } } })
  })

  afterEach(() => {
    cleanup()
    client?.clear()
    act(() => useMergeStore.getState().closeMerge())
  })

  it('counts the other albums in the group', () => {
    mount()

    expect(screen.getByText('1 potential duplicate found')).toBeInTheDocument()
  })

  it('merges the viewed album into its duplicate by their public IDs', async () => {
    const user = userEvent.setup()
    mount()

    await user.click(screen.getByRole('button', { name: 'Review Duplicates' }))
    await user.click(await screen.findByRole('button', { name: 'Merge Albums' }))

    await waitFor(() => expect(useMergeStore.getState().isOpen).toBe(false))
    expect(mockPost).toHaveBeenCalledOnce()
    expect(mockPost).toHaveBeenCalledWith('/api/albums/merge', {
      targetPublicId: DUPLICATE.publicId,
      sourcePublicId: VIEWED.publicId,
    })

    const body = mockPost.mock.calls[0][1] as Record<string, string>
    expect(body.targetPublicId).toHaveLength(21)
    expect(body.sourcePublicId).toHaveLength(21)
  })
})
