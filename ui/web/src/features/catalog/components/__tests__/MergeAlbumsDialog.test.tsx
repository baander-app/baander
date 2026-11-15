import { act, cleanup, render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { AxiosError, AxiosHeaders } from 'axios'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { ThemeProvider } from 'styled-components'
import { toast } from 'sonner'
import { resolveTheme } from '@/shared/theme/resolve-theme'
import { AXIOS_INSTANCE } from '@/shared/api-client/axios-instance'
import { useMergeStore } from '../../stores/merge-store'
import { MergeAlbumsDialog } from '../MergeAlbumsDialog'

vi.mock('@/shared/api-client/axios-instance', () => ({
  AXIOS_INSTANCE: { post: vi.fn() },
}))
vi.mock('sonner', () => ({
  toast: { success: vi.fn(), error: vi.fn() },
}))

const mockPost = vi.mocked(AXIOS_INSTANCE.post)

const SOURCE = { publicId: 'sourceAlbumPublicId05', title: 'Abbey Road (Remaster)' }
const TARGET = { publicId: 'targetAlbumPublicId06', title: 'Abbey Road' }

const BUSY_MESSAGE = 'Another change to "Abbey Road (Remaster)" or "Abbey Road" ran at the same time and nothing was merged. Merge the albums again.'

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

function mount() {
  client = new QueryClient({ defaultOptions: { queries: { retry: false } } })

  return render(
    <QueryClientProvider client={client}>
      <ThemeProvider theme={resolveTheme('dark', 'violet')}>
        <MergeAlbumsDialog />
      </ThemeProvider>
    </QueryClientProvider>,
  )
}

describe('MergeAlbumsDialog', () => {
  beforeEach(() => {
    mockPost.mockReset()
    vi.mocked(toast.error).mockReset()
    act(() => useMergeStore.getState().openMerge(SOURCE.publicId, SOURCE.title, TARGET.publicId, TARGET.title))
  })

  afterEach(() => {
    cleanup()
    client?.clear()
    act(() => useMergeStore.getState().closeMerge())
  })

  it('shows the message of a conflict the API answers and stays open to merge again', async () => {
    const user = userEvent.setup()
    mockPost.mockRejectedValue(conflict(BUSY_MESSAGE, 'merge_busy'))
    mount()

    await user.click(await screen.findByRole('button', { name: 'Merge Albums' }))

    await waitFor(() => expect(toast.error).toHaveBeenCalledWith(BUSY_MESSAGE))
    expect(mockPost).toHaveBeenCalledWith('/api/albums/merge', {
      targetPublicId: TARGET.publicId,
      sourcePublicId: SOURCE.publicId,
    })
    expect(useMergeStore.getState().isOpen).toBe(true)
    expect(screen.getByRole('button', { name: 'Merge Albums' })).toBeEnabled()
  })
})
