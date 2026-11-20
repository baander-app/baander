import { act, cleanup, renderHook, waitFor } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { afterEach, expect, it, vi } from 'vitest'
import { AXIOS_INSTANCE } from '@/shared/api-client/axios-instance'
import { queryLogs, resolveLogFilter, type LogFilter, type LogRecord } from '../../../api/logs-api'
import { useLogs } from '../../../hooks/use-logs'

vi.mock('@/shared/api-client/axios-instance', () => ({ AXIOS_INSTANCE: { get: vi.fn(), post: vi.fn() } }))

const filter: LogFilter = { sources: ['web'], channels: ['app'], levels: [], search: '', timeRange: '5m' }
afterEach(() => { cleanup(); vi.useRealTimers(); vi.clearAllMocks() })

it.each([
  ['2026-03-31T10:15:20Z', '1mo', '2026-02-28T10:15:20.000Z'],
  ['2024-03-31T10:15:20Z', '1mo', '2024-02-29T10:15:20.000Z'],
  ['2024-02-29T10:15:20Z', '1y', '2023-02-28T10:15:20.000Z'],
] as const)('clamps calendar range %s %s to %s', (now, timeRange, since) => {
  expect(resolveLogFilter({ ...filter, timeRange }, new Date(now))).toMatchObject({ since, until: new Date(now).toISOString() })
})

it('moves the live window, expires displayed old rows, and freezes paused rows and historical cursors', async () => {
  vi.setSystemTime(new Date('2026-03-31T10:00:00Z'))
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  const oldRecord: LogRecord = { id: 'old', sourceId: 'web', timestamp: '2026-03-31T09:56:00Z', level: 'info', channel: 'app', message: 'old', context: {}, extra: {}, raw: 'old', correlationId: null, node: 'local', service: 'web', stream: 'stderr', truncated: false, format: 'json' }
  const recentRecord = { ...oldRecord, id: 'recent', timestamp: '2026-03-31T09:59:00Z' }
  const newRecord = { ...oldRecord, id: 'new', timestamp: '2026-03-31T10:04:00Z' }
  vi.mocked(AXIOS_INSTANCE.get).mockImplementation(async (_url, options) => {
    const params = options?.params as { cursor?: string; since: string }
    const items = params.cursor ? [{ ...oldRecord, id: 'older-page' }]
      : params.since === '2026-03-31T10:00:00.000Z' ? [newRecord] : [oldRecord, recentRecord]
    return { data: { data: { items, nextCursor: params.cursor ? null : 'older', coverage: { complete: true, reasons: [] }, snapshot: { id: 'snapshot', createdAt: '2026-03-31T10:00:00Z' } } } }
  })
  const { result, rerender } = renderHook(({ following }) => useLogs(filter, following), {
    initialProps: { following: true },
    wrapper: ({ children }) => <QueryClientProvider client={client}>{children}</QueryClientProvider>,
  })
  await waitFor(() => expect(result.current.tail.isSuccess).toBe(true))
  expect(result.current.records.map(record => record.id)).toEqual(['recent', 'old'])
  vi.setSystemTime(new Date('2026-03-31T10:05:00Z'))
  await act(async () => { await client.invalidateQueries({ queryKey: ['admin', 'logs', 'tail'] }) })
  expect(vi.mocked(AXIOS_INSTANCE.get).mock.calls.at(-1)?.[1]?.params).toMatchObject({ since: '2026-03-31T10:00:00.000Z', until: '2026-03-31T10:05:00.000Z' })
  await waitFor(() => expect(result.current.records.map(record => record.id)).toEqual(['new']))
  rerender({ following: false })
  vi.setSystemTime(new Date('2026-03-31T11:00:00Z'))
  await act(async () => { await result.current.history.fetchNextPage() })
  expect(AXIOS_INSTANCE.get).toHaveBeenLastCalledWith('/api/admin/logs/entries', expect.objectContaining({ params: expect.objectContaining({ cursor: 'older', since: '2026-03-31T09:55:00.000Z', until: '2026-03-31T10:00:00.000Z' }) }))
  expect(result.current.records.map(record => record.id)).toEqual(['new'])
  const beforeRetry = vi.mocked(AXIOS_INSTANCE.get).mock.calls.length
  await act(async () => { await result.current.history.refetch() })
  const retryCalls = vi.mocked(AXIOS_INSTANCE.get).mock.calls.slice(beforeRetry)
  expect(retryCalls.length).toBeGreaterThan(0)
  for (const [, options] of retryCalls) expect(options?.params).toMatchObject({ since: '2026-03-31T09:55:00.000Z', until: '2026-03-31T10:00:00.000Z' })
  client.clear()
})

it('resolves a relative SQL filter once at submission and omits its UI preset', async () => {
  vi.setSystemTime(new Date('2026-03-31T10:00:00Z'))
  vi.mocked(AXIOS_INSTANCE.post).mockResolvedValue({ data: { data: { rows: [], columns: [] } } })
  const result = queryLogs('SELECT * FROM logs', true, filter)
  vi.setSystemTime(new Date('2026-03-31T11:00:00Z'))
  await result
  expect(AXIOS_INSTANCE.post).toHaveBeenCalledWith('/api/admin/logs/query', {
    sql: 'SELECT * FROM logs', allowPartial: true,
    filter: { sources: ['web'], channels: ['app'], levels: [], search: '', since: '2026-03-31T09:55:00.000Z', until: '2026-03-31T10:00:00.000Z' },
  }, expect.any(Object))
})
