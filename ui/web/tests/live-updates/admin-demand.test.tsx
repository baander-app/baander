import { AxiosHeaders } from 'axios'
import { act, cleanup, fireEvent, renderHook, screen, waitFor } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { render } from '../test-utils'
import { QuickActions } from '@/features/admin/components/dashboard/QuickActions'
import { useCheckMissingImages } from '@/features/admin/hooks/use-media-admin'
import { advanceAuthorization } from '@/shared/live/ownership'
import { AXIOS_INSTANCE } from '@/shared/api-client/axios-instance'

vi.mock('@/shared/api-client/axios-instance', () => ({ AXIOS_INSTANCE: { get: vi.fn(), post: vi.fn() } }))
vi.mock('@/features/admin/hooks/use-job-monitor', () => ({ useFlushFailedQueue: () => ({ mutateAsync: vi.fn() }) }))
vi.mock('@/features/admin/hooks/use-lyrics-admin', () => ({ useBulkFetchLyrics: () => ({ mutateAsync: vi.fn() }) }))
const get = vi.mocked(AXIOS_INSTANCE.get)
afterEach(() => { cleanup(); vi.clearAllMocks() })

describe('explicit administrative reads', () => {
  it('leaves missing-media scanning disabled until the user explicitly requests it', async () => {
    get.mockResolvedValue({ data: { data: { missing: [] } } })
    const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
    const view = renderHook(() => useCheckMissingImages(), { wrapper: ({ children }) =>
      <QueryClientProvider client={client}>{children}</QueryClientProvider>,
    })
    expect(get).not.toHaveBeenCalled()
    await act(async () => { await view.result.current.refetch() })
    expect(get).toHaveBeenCalledWith('/api/admin/media/missing-check', { signal: expect.any(AbortSignal) })
    client.clear()
  })

  it('runs health only on the action and aborts its pending request when authority changes', async () => {
    let finish!: () => void
    get.mockImplementation(() => new Promise(resolve => {
      finish = () => resolve({ data: {}, status: 200, statusText: 'OK', headers: {}, config: { headers: new AxiosHeaders() } })
    }))
    const client = new QueryClient()
    render(<QueryClientProvider client={client}><QuickActions /></QueryClientProvider>)
    expect(get).not.toHaveBeenCalled()
    fireEvent.click(screen.getByRole('button', { name: /Run Health Check/ }))
    await waitFor(() => expect(get).toHaveBeenCalledTimes(1))
    const signal = get.mock.calls[0][1]!.signal as AbortSignal
    expect(signal.aborted).toBe(false)
    act(() => { advanceAuthorization() })
    expect(signal.aborted).toBe(true)
    await act(async () => { finish() })
    expect(screen.getByRole('button', { name: /Run Health Check/ })).not.toHaveTextContent('✓')
  })
})
