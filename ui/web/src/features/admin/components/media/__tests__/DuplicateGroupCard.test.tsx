import { cleanup, render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { AxiosError, AxiosHeaders } from 'axios'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { ThemeProvider } from 'styled-components'
import { toast } from 'sonner'
import { resolveTheme } from '@/shared/theme/resolve-theme'
import { AXIOS_INSTANCE } from '@/shared/api-client/axios-instance'
import type { AlbumSummary, DuplicateGroup } from '../../../api/album-duplicates-api'
import { DuplicateGroupCard } from '../DuplicateGroupCard'

vi.mock('@/shared/api-client/axios-instance', () => ({
  AXIOS_INSTANCE: { post: vi.fn() },
}))
vi.mock('sonner', () => ({
  toast: { success: vi.fn(), error: vi.fn() },
}))

const mockPost = vi.mocked(AXIOS_INSTANCE.post)

const KEPT: AlbumSummary = {
  publicId: 'keptAlbumPublicId0001',
  title: 'Blue Train',
  year: 1957,
  lockedFields: [],
  createdAt: '2026-10-01T00:00:00+00:00',
  coverImage: null,
  artists: [{ name: 'John Coltrane' }],
}

const MERGED: AlbumSummary = {
  publicId: 'mergedAlbumPublicId02',
  title: 'Blue Train (Remaster)',
  year: 1957,
  lockedFields: [],
  createdAt: '2026-10-02T00:00:00+00:00',
  coverImage: null,
  artists: [{ name: 'John Coltrane' }],
}

const GROUP: DuplicateGroup = {
  albumIds: [KEPT.publicId, MERGED.publicId],
  confidence: 0.97,
  albumCount: 2,
  albums: [KEPT, MERGED],
}

const BUSY_MESSAGE = 'Another change to "Blue Train (Remaster)" or "Blue Train" ran at the same time and nothing was merged. Merge the albums again.'

let client: QueryClient

function conflict(message: string, reason: string) {
  const config = { headers: new AxiosHeaders() }

  return new AxiosError('Request failed with status code 409', 'ERR_BAD_REQUEST', config, undefined, {
    data: { error: { code: 409, message, details: { reason } } },
    status: 409,
    statusText: 'Conflict',
    headers: {},
    config,
  })
}

function mount(onMergeComplete: () => void) {
  client = new QueryClient({ defaultOptions: { queries: { retry: false } } })

  return render(
    <QueryClientProvider client={client}>
      <ThemeProvider theme={resolveTheme('dark', 'violet')}>
        <DuplicateGroupCard group={GROUP} onMergeComplete={onMergeComplete} />
      </ThemeProvider>
    </QueryClientProvider>,
  )
}

describe('DuplicateGroupCard', () => {
  beforeEach(() => {
    mockPost.mockReset()
    mockPost.mockResolvedValue({ data: { data: { publicId: KEPT.publicId } } })
    vi.mocked(toast.error).mockReset()
  })

  afterEach(() => {
    cleanup()
    client?.clear()
  })

  it('merges the other album into the chosen one by their public IDs', async () => {
    const user = userEvent.setup()
    const onMergeComplete = vi.fn()
    mount(onMergeComplete)

    await user.click(screen.getByRole('button', { name: 'Merge' }))
    const dialog = await screen.findByRole('dialog')
    const keptOption = within(dialog).getByText(KEPT.title).closest('button')
    expect(keptOption).not.toBeNull()
    await user.click(keptOption as HTMLButtonElement)
    await user.click(screen.getByRole('button', { name: 'Merge Albums' }))

    await waitFor(() => expect(onMergeComplete).toHaveBeenCalledOnce())
    expect(mockPost).toHaveBeenCalledOnce()
    expect(mockPost).toHaveBeenCalledWith('/api/albums/merge', {
      targetPublicId: KEPT.publicId,
      sourcePublicId: MERGED.publicId,
    })

    const body = mockPost.mock.calls[0][1] as Record<string, string>
    expect(body.targetPublicId).toHaveLength(21)
    expect(body.sourcePublicId).toHaveLength(21)
  })

  it('shows the message of a conflict the API answers and keeps the dialog open', async () => {
    const user = userEvent.setup()
    const onMergeComplete = vi.fn()
    mockPost.mockRejectedValue(conflict(BUSY_MESSAGE, 'merge_busy'))
    mount(onMergeComplete)

    await user.click(screen.getByRole('button', { name: 'Merge' }))
    const dialog = await screen.findByRole('dialog')
    const keptOption = within(dialog).getByText(KEPT.title).closest('button')
    expect(keptOption).not.toBeNull()
    await user.click(keptOption as HTMLButtonElement)
    await user.click(screen.getByRole('button', { name: 'Merge Albums' }))

    await waitFor(() => expect(toast.error).toHaveBeenCalledWith(BUSY_MESSAGE))
    expect(onMergeComplete).not.toHaveBeenCalled()
    expect(screen.getByRole('dialog')).toBeInTheDocument()
  })
})
