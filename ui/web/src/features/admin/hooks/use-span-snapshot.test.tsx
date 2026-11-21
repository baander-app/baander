import { act, renderHook, waitFor } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { beforeEach, expect, it, vi } from 'vitest'
import type { ReactNode } from 'react'
import { getSpans, type Span } from '../api/debug-api'
import { useSpanSnapshot } from './use-span-snapshot'

vi.mock('../api/debug-api', () => ({ getSpans: vi.fn() }))

const original: Span = {
  trace_id: 'trace-original', span_id: 'original', parent_span_id: null,
  operation_name: 'GET albums', service: 'api', kind: '2',
  start_time_us: 1000, duration_us: 100,
  attributes: {}, status_code: 'Unset', status_message: '', file_path: null, line_number: null,
}
const arrival = { ...original, trace_id: 'trace-arrival', span_id: 'arrival', start_time_us: 2000 }

function mount(client = new QueryClient({ defaultOptions: { queries: { retry: false } } })) {
  const wrapper = ({ children }: { children: ReactNode }) => <QueryClientProvider client={client}>{children}</QueryClientProvider>
  return { client, ...renderHook(useSpanSnapshot, { wrapper }) }
}

beforeEach(() => {
  vi.mocked(getSpans).mockReset().mockResolvedValue([original])
})

it('freezes initial data until explicit refresh and lets Live/Pause control later arrivals', async () => {
  const { result, client } = mount()
  await waitFor(() => expect(result.current.spans).toEqual([original]))
  act(() => client.setQueryData(['debug', 'spans', 500], [arrival, original]))
  expect(result.current.spans).toEqual([original])
  expect(result.current.live).toBe(false)
  vi.mocked(getSpans).mockResolvedValue([arrival, original])
  await act(() => result.current.refresh())
  expect(result.current.spans).toEqual([arrival, original])
  act(() => result.current.setLive(true))
  act(() => client.setQueryData(['debug', 'spans', 500], [arrival]))
  await waitFor(() => expect(result.current.spans).toEqual([arrival]))
  act(() => result.current.setLive(false))
  act(() => client.setQueryData(['debug', 'spans', 500], [original]))
  expect(result.current.spans).toEqual([arrival])
})

it('preserves the inspected snapshot when explicit refresh fails', async () => {
  const { result } = mount()
  await waitFor(() => expect(result.current.spans).toEqual([original]))
  vi.mocked(getSpans).mockRejectedValue(new Error('unavailable'))
  await act(() => result.current.refresh())
  expect(result.current.spans).toEqual([original])
  await waitFor(() => expect(result.current.error).toBeTruthy())
})

it('keeps cleared rows hidden when Live is enabled after a failed read', async () => {
  const { result, client } = mount()
  await waitFor(() => expect(result.current.spans).toEqual([original]))
  vi.mocked(getSpans).mockRejectedValue(new Error('unavailable'))
  await act(() => result.current.refresh())
  await act(() => result.current.clearSnapshot())
  act(() => result.current.setLive(true))
  expect(result.current.spans).toEqual([])
  act(() => client.setQueryData(['debug', 'spans', 500], [arrival]))
  await waitFor(() => expect(result.current.spans).toEqual([arrival]))
})

it('keeps the cleared snapshot when returning to diagnostics before a successful read', async () => {
  const first = mount()
  await waitFor(() => expect(first.result.current.spans).toEqual([original]))
  await act(() => first.result.current.clearSnapshot())
  first.unmount()
  vi.mocked(getSpans).mockRejectedValue(new Error('unavailable'))
  const next = mount(first.client)
  await waitFor(() => expect(next.result.current.error).toBeTruthy())
  expect(next.result.current.spans).toEqual([])
})

it.each([false, true])('does not restore a pre-clear response after clearing with live=%s', async (live) => {
  const { result } = mount()
  await waitFor(() => expect(result.current.spans).toEqual([original]))
  act(() => result.current.setLive(live))
  let resolveRead!: (value: Span[]) => void
  vi.mocked(getSpans).mockImplementation(() => new Promise(resolve => { resolveRead = resolve }))
  let pending!: Promise<void>
  act(() => { pending = result.current.refresh() })
  await act(() => result.current.clearSnapshot())
  await act(async () => {
    resolveRead([original])
    await pending
  })
  expect(result.current.spans).toEqual([])
})
