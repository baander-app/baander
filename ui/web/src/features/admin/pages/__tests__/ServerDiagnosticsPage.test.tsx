import { cleanup, screen, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { render } from '../../../../../tests/test-utils'
import { getServerStats, type ServerStats, type WorkerSnapshot } from '../../api/server-stats-api'
import {
  clearSpans,
  getCoroutineStats,
  getSpans,
  getWorkerStats,
  type CoroutineStats,
  type Span,
  type WorkerCoroutines,
  type WorkerStats,
} from '../../api/debug-api'
import { ServerDiagnosticsPage } from '../ServerDiagnosticsPage'

vi.mock('../../api/server-stats-api', () => ({ getServerStats: vi.fn() }))
vi.mock('../../api/debug-api', () => ({
  getCoroutineStats: vi.fn(),
  getWorkerStats: vi.fn(),
  getSpans: vi.fn(),
  clearSpans: vi.fn(),
}))

const mockServerStats = vi.mocked(getServerStats)
const mockCoroutineStats = vi.mocked(getCoroutineStats)
const mockWorkerStats = vi.mocked(getWorkerStats)
const mockSpans = vi.mocked(getSpans)
const mockClearSpans = vi.mocked(clearSpans)

function worker(workerId: number, pid: number): WorkerSnapshot {
  return {
    worker_id: workerId,
    memory: { usage: 40 + workerId, peak: 60 + workerId, real: 44 + workerId, real_peak: 64 + workerId },
    process: { pid, uid: 1000, gid: 1000, user: 'baander', uptime: 3_600 },
    swoole: { object_num: 12, resource_num: 3 },
    coroutines: { coroutine_num: 5 + workerId, coroutine_peak_num: 20 + workerId },
    pools: [{ active: 1, free: 3, limit: 4 }],
  }
}

function workerCoroutines(workerId: number, activeCids: number[], channelCount: number): WorkerCoroutines {
  return {
    worker_id: workerId,
    coroutines: {
      event_num: 1,
      signal_listener_num: 0,
      aio_task_num: 0,
      aio_worker_num: 0,
      aio_queue_size: 0,
      c_stack_size: 2_097_152,
      coroutine_num: 70 + workerId,
      coroutine_peak_num: 90 + workerId,
      coroutine_last_cid: 1_000 + workerId,
    },
    active_cids: activeCids,
    channels: Array.from({ length: channelCount }, (_, index) => ({
      name: `channel-${index}`,
      consumer_num: 0,
      producer_num: 0,
      queue_num: 0,
      capacity: 1,
      closed: false,
    })),
  }
}

const SERVER_STATS: ServerStats = {
  workers: [worker(0, 4101), worker(1, 4102)],
  missing_workers: [],
  worker_errors: [],
  redis: {
    connected: true,
    ping: true,
    db_size: 4_242,
    connected_clients: 17,
    used_memory: 12.5,
    maxmemory: 256,
  },
}

const COROUTINE_STATS: CoroutineStats = {
  workers: [
    workerCoroutines(0, [1, 2, 3], 2),
    workerCoroutines(1, [4], 0),
  ],
  missing_workers: [],
  worker_errors: [],
}

const WORKER_STATS: WorkerStats = {
  http_workers: {
    total: 2,
    idle: 1,
    active: 1,
    request_count: 500,
    dispatch_count: 500,
    concurrency: 3,
    connection_num: 4,
    max_connection: 1_024,
    coroutine_num: 8,
    coroutine_peak: 30,
    start_time: 1_790_000_000,
    total_recv_bytes: 1_000,
    total_send_bytes: 2_000,
  },
  task_workers: { total: 4, idle: 3, active: 1, tasking_num: 1, task_count: 12 },
  user_workers: { total: 1 },
  transcode_pool: { available: true, running: true, worker_count: 6, result_table_size: 64, boot_pending: false },
}

const SPANS: Span[] = [
  {
    trace_id: '4bf92f3577b34da6a3ce929d0e0e4736',
    span_id: '00f067aa0ba902b7',
    parent_span_id: null,
    operation_name: 'GET api_albums_index',
    service: 'baander',
    kind: 'server',
    start_time_us: 1_790_000_000_000_000,
    duration_us: 12_345,
    attributes: {
      'http.request.method': 'GET',
      'baander.route': 'api_albums_index',
      'http.response.status_code': 200,
    },
    status_code: 'OK',
    status_message: '',
    file_path: null,
    line_number: null,
  },
]

let queryClient: QueryClient

function mount() {
  queryClient = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } })
  return render(
    <QueryClientProvider client={queryClient}>
      <ServerDiagnosticsPage />
    </QueryClientProvider>,
  )
}

function bodyRows(table: HTMLElement) {
  return within(table).getAllByRole('row').slice(1)
}

function cellTexts(row: HTMLElement) {
  return within(row).getAllByRole('cell').map((cell) => cell.textContent)
}

function region(name: string) {
  return screen.getByRole('region', { name })
}

describe('ServerDiagnosticsPage', () => {
  beforeEach(() => {
    mockServerStats.mockReset()
    mockCoroutineStats.mockReset()
    mockWorkerStats.mockReset()
    mockSpans.mockReset()
    mockClearSpans.mockReset()

    mockServerStats.mockResolvedValue(SERVER_STATS)
    mockCoroutineStats.mockResolvedValue(COROUTINE_STATS)
    mockWorkerStats.mockResolvedValue(WORKER_STATS)
    mockSpans.mockResolvedValue(SPANS)
    mockClearSpans.mockResolvedValue(undefined)
  })

  afterEach(() => {
    cleanup()
    queryClient?.clear()
  })

  it('shows one row per worker with its ID and PID, plus the shared Redis figures', async () => {
    mount()

    const workers = await screen.findByRole('table', { name: 'HTTP workers' })
    const rows = bodyRows(workers)
    expect(rows).toHaveLength(2)
    expect(cellTexts(rows[0])).toEqual(['0', '4101', '1h 0m', '40 / 60 MB', '44 / 64 MB', '5 / 20'])
    expect(cellTexts(rows[1])).toEqual(['1', '4102', '1h 0m', '41 / 61 MB', '45 / 65 MB', '6 / 21'])

    expect(within(region('Redis')).getByText('4242')).toBeInTheDocument()
    expect(within(region('Redis')).getByText('17')).toBeInTheDocument()
    expect(screen.queryByRole('region', { name: 'Server-sent events' })).not.toBeInTheDocument()
    expect(screen.queryByText(/did not answer/)).not.toBeInTheDocument()
  })

  it('names each worker that did not answer and each worker error', async () => {
    mockServerStats.mockResolvedValue({
      ...SERVER_STATS,
      workers: [worker(0, 4101)],
      missing_workers: [2],
      worker_errors: [{ worker_id: 3, error: 'Channel closed' }],
    })

    mount()

    const notice = await screen.findByRole('region', { name: 'Worker statistics incomplete' })
    expect(within(notice).getByText('Worker 2 did not answer in time.')).toBeInTheDocument()
    expect(within(notice).getByText('Worker 3 failed: Channel closed')).toBeInTheDocument()
  })

  it('names a worker missing from the coroutine snapshot', async () => {
    mockCoroutineStats.mockResolvedValue({
      ...COROUTINE_STATS,
      missing_workers: [1],
    })

    mount()

    const notice = await screen.findByRole('region', { name: 'Coroutine statistics incomplete' })
    expect(within(notice).getByText('Worker 1 did not answer in time.')).toBeInTheDocument()
  })

  it('shows one coroutine row per worker', async () => {
    mount()

    const table = await screen.findByRole('table', { name: 'Coroutines per worker' })
    const rows = bodyRows(table)
    expect(rows).toHaveLength(2)
    expect(cellTexts(rows[0])).toEqual(['0', '70', '90', '3', '2'])
    expect(cellTexts(rows[1])).toEqual(['1', '71', '91', '1', '0'])
  })

  it('shows the server-wide worker pools', async () => {
    mount()

    const pools = await screen.findByRole('region', { name: 'Worker pools' })
    expect(await within(pools).findByText('Transcode pool workers')).toBeInTheDocument()
    expect(within(pools).getByText('6')).toBeInTheDocument()
    expect(within(pools).getByText('Task total')).toBeInTheDocument()
  })

  it('shows spans with operation name, status code and duration in milliseconds', async () => {
    mount()

    const spans = await screen.findByRole('region', { name: 'Recent spans' })
    expect(await within(spans).findByText('GET api_albums_index')).toBeInTheDocument()
    expect(within(spans).getByText('200')).toBeInTheDocument()
    expect(within(spans).getByText('12.3 ms')).toBeInTheDocument()
    expect(mockSpans).toHaveBeenCalledWith(30)

    await userEvent.click(within(spans).getByRole('button', { name: 'Clear' }))
    expect(mockClearSpans).toHaveBeenCalledTimes(1)
  })

  it('shows an error with a retry button when the server stats fail', async () => {
    mockServerStats.mockRejectedValueOnce(new Error('boom'))

    mount()

    expect(await screen.findByText('Failed to load server diagnostics.')).toBeInTheDocument()
    await userEvent.click(screen.getByRole('button', { name: 'Retry' }))
    expect(await screen.findByRole('table', { name: 'HTTP workers' })).toBeInTheDocument()
  })
})
