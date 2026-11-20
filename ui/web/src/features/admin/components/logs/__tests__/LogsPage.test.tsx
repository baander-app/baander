import { act, cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { QueryClient, QueryClientProvider, type InfiniteData } from '@tanstack/react-query'
import { MemoryRouter } from 'react-router-dom'
import { ThemeProvider } from 'styled-components'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { resolveTheme } from '@/shared/theme/resolve-theme'
import { AXIOS_INSTANCE } from '@/shared/api-client/axios-instance'
import { LogsPage } from '../../../pages/LogsPage'
import { adminLiveDescriptors } from '../../../live-descriptors'
import { mergeLogRecords } from '../../../hooks/use-logs'
import { safeLogText } from '../log-text'
import type { LogPage, LogRecord } from '../../../api/logs-api'

const auth = vi.hoisted(() => ({ isSuperAdmin: true }))
vi.mock('@/features/auth/hooks/use-admin-check', () => ({ useAdminCheck: () => auth }))
vi.mock('@/shared/api-client/axios-instance', () => ({ AXIOS_INSTANCE: { get: vi.fn(), post: vi.fn() } }))
vi.mock('@tanstack/react-virtual', () => ({
  useVirtualizer: ({ count }: { count: number }) => ({
    getTotalSize: () => count * 32,
    getVirtualItems: () => Array.from({ length: count }, (_, index) => ({ index, start: index * 32 })),
    scrollToIndex: vi.fn(),
  }),
}))
const record: LogRecord = {
  format: 'json', id: 'node:one', sourceId: 'web', timestamp: '2026-11-20T12:34:56Z', level: 'info', channel: 'request',
  message: '<script>alert(1)</script>', context: { job: 'sync' }, extra: {}, raw: '<script>alert(1)</script>',
  correlationId: 'trace-12', node: 'local', service: 'web', stream: 'stderr', truncated: false,
}
const coverage = { complete: false, reasons: ['worker host unreachable'] }
const snapshot = { id: 'snapshot', createdAt: '2026-11-20T12:35:00Z' }
let client: QueryClient

function mount() {
  client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return render(<QueryClientProvider client={client}><ThemeProvider theme={resolveTheme('dark', 'violet')}><MemoryRouter><LogsPage /></MemoryRouter></ThemeProvider></QueryClientProvider>)
}

beforeEach(() => {
  Element.prototype.hasPointerCapture = () => false
  Element.prototype.setPointerCapture = () => {}
  Element.prototype.releasePointerCapture = () => {}
  Element.prototype.scrollIntoView = () => {}
  auth.isSuperAdmin = true
  vi.mocked(AXIOS_INSTANCE.get).mockReset()
  vi.mocked(AXIOS_INSTANCE.post).mockReset()
  vi.mocked(AXIOS_INSTANCE.get).mockImplementation(async (url, options) => {
    const hasCursor = Boolean((options?.params as { cursor?: string } | undefined)?.cursor)
    const payload = String(url).endsWith('/sources')
      ? { channels: ['request', 'security', 'scheduler'], items: [{ id: 'web', node: 'local', name: 'Web stderr' }, { id: 'archive', node: 'local', name: 'worker.log.gz', kind: 'gzip', status: 'archived' }], nodes: [{ id: 'local', status: 'healthy' }, { id: 'worker-host', status: 'unreachable' }], coverage }
      : String(url).includes('/entries/') ? record
      : { items: hasCursor ? [{ ...record, id: 'older', message: 'Older message', timestamp: '2026-11-19T12:00:00Z' }] : [record], nextCursor: hasCursor ? null : 'older-page', snapshot, coverage }
    return { data: { data: payload } }
  })
  vi.mocked(AXIOS_INSTANCE.post).mockResolvedValue({ data: { data: { columns: ['level', 'records'], rows: [['info', 2]], snapshot, coverage } } })
})
afterEach(() => { cleanup(); client?.clear(); vi.useRealTimers() })

describe('log workspace', () => {
  it('shows missing hosts and archives, inspects inert text and keeps selection while paused', async () => {
    const user = userEvent.setup()
    mount()
    expect(await screen.findByText(/^worker\.log\.gz · ID/)).toBeVisible()
    expect(screen.getByText('Collection host: worker-host')).not.toBeVisible()
    await user.click(screen.getByRole('button', { name: 'Source inventory coverage details' }))
    expect(screen.getByText('Collection host: worker-host')).toBeVisible()
    await user.click(await screen.findByRole('option'))
    expect(await screen.findByRole('region', { name: 'Record inspector' })).toBeVisible()
    await user.click(screen.getByText('Record fields'))
    expect(screen.getByText('trace-12')).toBeVisible()
    expect(document.querySelector('script')).toBeNull()
    await user.click(screen.getByRole('button', { name: 'Pause' }))
    expect(screen.getByRole('option')).toHaveAttribute('aria-selected', 'true')
    expect(client.getQueryCache().find({ queryKey: ['admin', 'logs', 'tail'], exact: false })?.isActive()).toBe(false)
    await user.click(screen.getByRole('button', { name: 'Load older messages' }))
    expect(await screen.findByText('Older message')).toBeVisible()
    expect(screen.getByRole('region', { name: 'Record inspector' })).toBeVisible()
    await user.click(screen.getByRole('button', { name: 'Close inspector' }))
    expect(screen.getByRole('listbox', { name: 'Log messages' })).toHaveFocus()
  })

  it('keeps incomplete coverage from an older loaded page visible beside its messages', async () => {
    const user = userEvent.setup()
    vi.mocked(AXIOS_INSTANCE.get).mockImplementation(async (url, options) => {
      const complete = { complete: true, reasons: [] }
      if (String(url).endsWith('/sources')) return { data: { data: { items: [], nodes: [], coverage: complete } } }
      const older = Boolean((options?.params as { cursor?: string } | undefined)?.cursor)
      return { data: { data: {
        items: older ? [{ ...record, id: 'older', message: 'Retained archive message' }] : [record],
        nextCursor: older ? null : 'older-page',
        snapshot,
        coverage: older ? { complete: false, reasons: ['Archive retention gap'] } : complete,
      } } }
    })
    mount()
    await screen.findByRole('option')
    expect(screen.queryByRole('button', { name: 'Message coverage details' })).not.toBeInTheDocument()
    await user.click(screen.getByRole('button', { name: 'Load older messages' }))
    expect(await screen.findByText('Retained archive message')).toBeVisible()
    expect(screen.getByRole('status', { name: 'Message coverage' })).toHaveTextContent('1 issue')
    await user.click(screen.getByRole('button', { name: 'Message coverage details' }))
    expect(screen.getByText('Archive retention gap')).toBeVisible()
  })

  it('sends literal search and selected opaque sources, and drops the old selection', async () => {
    const user = userEvent.setup()
    mount()
    await user.click(await screen.findByRole('option'))
    await user.click(screen.getByRole('checkbox', { name: /^Select worker\.log\.gz · ID/ }))
    await user.type(screen.getByRole('textbox', { name: 'Search log messages' }), '[[worker]*')
    await user.keyboard('{Enter}')
    await waitFor(() => expect(AXIOS_INSTANCE.get).toHaveBeenCalledWith('/api/admin/logs/entries', expect.objectContaining({ params: expect.objectContaining({ sources: 'archive', search: '[worker]*' }), signal: expect.any(AbortSignal) })))
    expect(screen.queryByRole('region', { name: 'Record inspector' })).not.toBeInTheDocument()
  })

  it('selects multiple inventory channels, retains choices after reload and clears them', async () => {
    const user = userEvent.setup()
    const read = vi.mocked(AXIOS_INSTANCE.get).getMockImplementation()!
    vi.mocked(AXIOS_INSTANCE.get).mockImplementation(async (url, options) => {
      if (String(url).endsWith('/entries') && ((options?.params as { 'channels[]'?: string[] } | undefined)?.['channels[]']?.length ?? 0) > 0) {
        return { data: { data: { items: [], nextCursor: null, snapshot, coverage } } }
      }
      return read(url, options)
    })
    mount()
    await screen.findByRole('option')
    await user.click(screen.getByRole('button', { name: 'Channels' }))
    await user.click(screen.getByRole('menuitemcheckbox', { name: 'request' }))
    await user.click(screen.getByRole('menuitemcheckbox', { name: 'security' }))
    expect(screen.getByRole('menuitemcheckbox', { name: 'request' })).toHaveAttribute('aria-checked', 'true')
    expect(screen.getByRole('menuitemcheckbox', { name: 'security' })).toHaveAttribute('aria-checked', 'true')
    await user.keyboard('{Escape}')
    await waitFor(() => expect(AXIOS_INSTANCE.get).toHaveBeenCalledWith('/api/admin/logs/entries', expect.objectContaining({ params: expect.objectContaining({ 'channels[]': ['request', 'security'] }) })))
    expect(await screen.findByText('No messages match these filters.')).toBeVisible()
    expect(screen.getByRole('button', { name: 'Channels' })).toHaveTextContent('2 selected')
    await user.click(screen.getByRole('button', { name: 'SQL query' }))
    await user.click(screen.getByRole('button', { name: 'Run query' }))
    await screen.findByRole('table', { name: 'SQL results' })
    expect(AXIOS_INSTANCE.post).toHaveBeenLastCalledWith('/api/admin/logs/query', expect.objectContaining({ filter: expect.objectContaining({ channels: ['request', 'security'] }) }), expect.any(Object))
    await user.click(screen.getByRole('button', { name: 'Messages' }))
    await user.click(screen.getByRole('button', { name: 'Channels' }))
    expect(screen.getByRole('menuitemcheckbox', { name: 'scheduler' })).toBeVisible()
    expect(screen.getByRole('menuitemcheckbox', { name: 'request' })).toHaveAttribute('aria-checked', 'true')
    await user.click(screen.getByRole('menuitemcheckbox', { name: 'All channels' }))
    await user.keyboard('{Escape}')
    await waitFor(() => expect(AXIOS_INSTANCE.get).toHaveBeenLastCalledWith('/api/admin/logs/entries', expect.objectContaining({ params: expect.objectContaining({ 'channels[]': [] }) })))
    expect(await screen.findByRole('option')).toBeVisible()
  })

  it('requires explicit partial SQL opt-in and labels the snapshot beside independent results', async () => {
    const user = userEvent.setup()
    mount()
    await user.click(screen.getByRole('button', { name: 'SQL query' }))
    await user.click(screen.getByRole('button', { name: 'Run query' }))
    expect(await screen.findByRole('table', { name: 'SQL results' })).toBeVisible()
    expect(AXIOS_INSTANCE.post).toHaveBeenLastCalledWith('/api/admin/logs/query', expect.objectContaining({ allowPartial: false }), expect.objectContaining({ signal: expect.any(AbortSignal) }))
    expect(screen.getByText(/Snapshot snapshot.*Incomplete snapshot/)).toBeVisible()
    await user.click(screen.getByRole('button', { name: 'SQL result coverage details' }))
    expect(screen.getByRole('region', { name: 'SQL result coverage issues' })).toHaveTextContent('worker host unreachable')
    await user.click(screen.getByRole('button', { name: 'SQL result coverage details' }))
    await user.click(screen.getByRole('checkbox', { name: 'Allow an incomplete snapshot' }))
    await user.click(screen.getByRole('button', { name: 'Run query' }))
    await waitFor(() => expect(AXIOS_INSTANCE.post).toHaveBeenLastCalledWith('/api/admin/logs/query', expect.objectContaining({ allowPartial: true }), expect.any(Object)))
  })

  it('copies retained raw text as plain text', async () => {
    const user = userEvent.setup()
    const writeText = vi.spyOn(navigator.clipboard, 'writeText').mockResolvedValue()
    mount()
    await user.click(await screen.findByRole('option'))
    await user.click(await screen.findByRole('button', { name: 'Copy raw record' }))
    expect(writeText).toHaveBeenCalledWith(record.raw)
    expect(screen.getByText('Copied raw record')).toBeVisible()
  })

  it('shows SQL failures separately from message history', async () => {
    vi.mocked(AXIOS_INSTANCE.post).mockRejectedValue(new Error('query rejected'))
    const user = userEvent.setup()
    mount()
    await user.click(screen.getByRole('button', { name: 'SQL query' }))
    await user.click(screen.getByRole('button', { name: 'Run query' }))
    expect(await screen.findByRole('alert')).toHaveTextContent('Query failed')
    expect(screen.queryByRole('table', { name: 'SQL results' })).not.toBeInTheDocument()
  })

  it('keeps source and history failures distinct from empty search results', async () => {
    vi.mocked(AXIOS_INSTANCE.get).mockRejectedValue(new Error('offline'))
    mount()
    expect(await screen.findByText(/Source discovery failed/)).toBeVisible()
    expect(await screen.findByText(/Message history unavailable/)).toBeVisible()
    expect(screen.queryByText('No messages match these filters.')).not.toBeInTheDocument()
  })

  it('aborts obsolete filter requests and ignores their late responses', async () => {
    const user = userEvent.setup()
    const signals: AbortSignal[] = []
    const release: (() => void)[] = []
    vi.mocked(AXIOS_INSTANCE.get).mockImplementation((url, options) => {
      if (String(url).endsWith('/sources')) return Promise.resolve({ data: { data: { items: [], nodes: [], coverage } } })
      if (!(options?.params as { search?: string } | undefined)?.search) {
        signals.push(options!.signal as AbortSignal)
        return new Promise(resolve => release.push(() => resolve({ data: { data: { items: [{ ...record, message: 'obsolete result' }], nextCursor: null, snapshot, coverage } } })))
      }
      return Promise.resolve({ data: { data: { items: [{ ...record, message: 'current result' }], nextCursor: null, snapshot, coverage } } })
    })
    mount()
    await user.type(screen.getByRole('textbox', { name: 'Search log messages' }), 'current')
    await user.keyboard('{Enter}')
    expect(await screen.findByRole('option')).toHaveTextContent('current result')
    expect(signals.length).toBeGreaterThan(0)
    expect(signals.every(signal => signal.aborted)).toBe(true)
    release.forEach(resolve => resolve())
    await waitFor(() => expect(screen.queryByText('obsolete result')).not.toBeInTheDocument())
  })

  it('keeps hundreds of long coverage issues out of the workspace until details are opened', async () => {
    const user = userEvent.setup()
    const reasons = Array.from({ length: 250 }, (_, index) => `Node-${index}-${'long-source-identifier-'.repeat(40)} unavailable`)
    const detailedCoverage = { complete: false, reasons: [...reasons, reasons[0]] }
    vi.mocked(AXIOS_INSTANCE.get).mockImplementation(async url => ({ data: { data: String(url).endsWith('/sources')
      ? { items: [], nodes: [], channels: [], coverage: detailedCoverage }
      : { items: [record], nextCursor: null, snapshot, coverage: detailedCoverage },
    } }))
    mount()
    await screen.findByRole('option')
    expect(screen.getByRole('status', { name: 'Message coverage' })).toHaveTextContent('250 issues')
    expect(screen.getByRole('status', { name: 'Source inventory coverage' })).toHaveTextContent('250 issues')
    for (const reason of screen.getAllByText(reasons[0])) expect(reason).not.toBeVisible()
    const trigger = screen.getByRole('button', { name: 'Message coverage details' })
    trigger.focus()
    await user.keyboard('{Enter}')
    const details = screen.getByRole('region', { name: 'Message coverage issues' })
    expect(details).toHaveTextContent(reasons[249])
    expect(within(details).getAllByRole('listitem')).toHaveLength(250)
    expect(details).toHaveAttribute('tabindex', '0')
    trigger.focus()
    await user.keyboard('{Enter}')
    expect(trigger).toHaveAttribute('aria-expanded', 'false')
    expect(details).not.toBeVisible()
    expect(trigger).toHaveFocus()
    expect(screen.getByRole('option')).toBeVisible()
  })

  it('debounces rapid text edits into one request and Enter flushes without a second request', async () => {
    vi.useFakeTimers()
    mount()
    await act(async () => { await vi.advanceTimersByTimeAsync(1) })
    const reads = () => vi.mocked(AXIOS_INSTANCE.get).mock.calls.filter(([url]) => url === '/api/admin/logs/entries')
    const initialReads = reads().length
    fireEvent.change(screen.getByRole('textbox', { name: 'Search log messages' }), { target: { value: 'n' } })
    fireEvent.change(screen.getByRole('textbox', { name: 'Search log messages' }), { target: { value: 'needle' } })
    fireEvent.change(screen.getByRole('textbox', { name: 'Correlation ID' }), { target: { value: 'trace-42' } })
    await act(async () => { await vi.advanceTimersByTimeAsync(299) })
    expect(reads()).toHaveLength(initialReads)
    await act(async () => { await vi.advanceTimersByTimeAsync(1) })
    expect(reads()).toHaveLength(initialReads + 1)
    expect(reads().at(-1)?.[1]?.params).toMatchObject({ search: 'needle', correlationId: 'trace-42' })
    fireEvent.change(screen.getByRole('textbox', { name: 'Search log messages' }), { target: { value: 'needle-more' } })
    fireEvent.keyDown(screen.getByRole('textbox', { name: 'Search log messages' }), { key: 'Enter' })
    await act(async () => { await vi.advanceTimersByTimeAsync(1) })
    expect(reads()).toHaveLength(initialReads + 2)
    await act(async () => { await vi.advanceTimersByTimeAsync(500) })
    expect(reads()).toHaveLength(initialReads + 2)
    expect(screen.queryByRole('button', { name: 'Apply filters' })).not.toBeInTheDocument()
    expect(screen.getByRole('combobox', { name: 'Time range' })).toHaveTextContent('All time')
  })

  it('applies levels, sources and dates as soon as they change', async () => {
    const user = userEvent.setup()
    mount()
    await screen.findByRole('option')
    await user.click(screen.getByRole('combobox', { name: 'Level' }))
    await user.click(screen.getByRole('option', { name: 'warning' }))
    await waitFor(() => expect(AXIOS_INSTANCE.get).toHaveBeenLastCalledWith('/api/admin/logs/entries', expect.objectContaining({ params: expect.objectContaining({ levels: 'warning' }) })))
    await user.click(screen.getByRole('checkbox', { name: /^Select worker\.log\.gz · ID/ }))
    await waitFor(() => expect(AXIOS_INSTANCE.get).toHaveBeenLastCalledWith('/api/admin/logs/entries', expect.objectContaining({ params: expect.objectContaining({ sources: 'archive' }) })))
    fireEvent.change(screen.getByLabelText('From'), { target: { value: '2026-11-20T09:15' } })
    await waitFor(() => expect(AXIOS_INSTANCE.get).toHaveBeenLastCalledWith('/api/admin/logs/entries', expect.objectContaining({ params: expect.objectContaining({ since: new Date('2026-11-20T09:15').toISOString() }) })))
  })

  it('applies calendar presets immediately, preserves other filters and switches manual dates to Custom', async () => {
    vi.setSystemTime(new Date('2026-03-31T10:00:00Z'))
    const user = userEvent.setup()
    mount()
    await screen.findByRole('option')
    await user.click(screen.getByRole('checkbox', { name: /^Select worker\.log\.gz · ID/ }))
    await user.click(screen.getByRole('combobox', { name: 'Time range' }))
    expect(screen.getAllByRole('option')).toHaveLength(12)
    await user.click(screen.getByRole('option', { name: '1 month' }))
    await waitFor(() => expect(AXIOS_INSTANCE.get).toHaveBeenLastCalledWith('/api/admin/logs/entries', expect.objectContaining({ params: expect.objectContaining({ sources: 'archive', since: '2026-02-28T10:00:00.000Z', until: '2026-03-31T10:00:00.000Z' }) })))
    expect(screen.getByLabelText('From')).toHaveValue('')
    expect(screen.getByRole('combobox', { name: 'Time range' })).toHaveTextContent('1 month')
    fireEvent.change(screen.getByLabelText('Until'), { target: { value: '2026-03-30T09:00' } })
    expect(screen.getByRole('combobox', { name: 'Time range' })).toHaveTextContent('Custom')
    await waitFor(() => expect(AXIOS_INSTANCE.get).toHaveBeenLastCalledWith('/api/admin/logs/entries', expect.objectContaining({ params: expect.objectContaining({ sources: 'archive', since: undefined, until: new Date('2026-03-30T09:00').toISOString() }) })))
    await user.click(screen.getByRole('combobox', { name: 'Time range' }))
    await user.click(screen.getByRole('option', { name: 'All time' }))
    expect(screen.getByRole('combobox', { name: 'Time range' })).toHaveTextContent('All time')
    expect(screen.getByLabelText('Until')).toHaveValue('')
    await waitFor(() => expect(AXIOS_INSTANCE.get).toHaveBeenLastCalledWith('/api/admin/logs/entries', expect.objectContaining({ params: expect.objectContaining({ sources: 'archive', since: undefined, until: undefined }) })))
  })

  it('keeps available gap sources visible and folds only unavailable sources behind a count', async () => {
    const user = userEvent.setup()
    const read = vi.mocked(AXIOS_INSTANCE.get).getMockImplementation()!
    const fullName = `preview-node:container:${'abcdef012345'.repeat(12)}`
    vi.mocked(AXIOS_INSTANCE.get).mockImplementation(async (url, options) => {
      if (String(url).endsWith('/sources')) return { data: { data: {
        channels: [], nodes: [], coverage: { complete: true, reasons: [] },
        items: [
          { id: 'active', name: fullName, node: 'local', service: 'web', kind: 'container', container: 'abcdef012345'.repeat(12), status: 'gap', available: true, issues: ['Historic records missing'] },
          { id: 'missing', name: '/retained/logs/missing.log', node: 'local', kind: 'file', status: 'gap', available: false, issues: ['Origin file missing'] },
          { id: 'checkpoint', name: fullName, node: 'local', service: 'docker', kind: 'collector', status: 'gap', available: true, issues: ['Checkpoint gap'] },
        ],
      } } }
      return read(url, options)
    })
    mount()
    const active = await screen.findByRole('checkbox', { name: 'Select Container abcdef01' })
    expect(active).toBeVisible()
    expect(screen.getByText('Web application logs · 1 source')).toBeVisible()
    expect(screen.getByText('Log collector · 1 source')).toBeVisible()
    expect(screen.getByRole('checkbox', { name: 'Select Final collection check' })).toBeVisible()
    expect(active).toHaveAttribute('aria-description', expect.stringContaining(fullName))
    expect(screen.queryByRole('button', { name: 'Source coverage details' })).not.toBeInTheDocument()
    const issueToggle = screen.getByRole('button', { name: 'Source inventory coverage details' })
    expect(issueToggle).toHaveAttribute('aria-expanded', 'false')
    await user.click(issueToggle)
    const diagnostics = screen.getByRole('region', { name: 'Source inventory coverage issues' })
    expect(diagnostics).toHaveTextContent('Historic records missing')
    expect(diagnostics).toHaveTextContent('Origin file missing')
    expect(diagnostics).toHaveTextContent('Checkpoint gap')
    expect(within(diagnostics).getAllByRole('heading').map(heading => heading.textContent)).toEqual(['Container abcdef01 · local', 'missing.log · ID missing · local', 'Final collection check · local'])
    expect(screen.getByRole('checkbox', { name: /^Select missing\.log · ID/, hidden: true })).not.toBeVisible()
    await user.click(screen.getByText('Unavailable sources (1)'))
    await user.click(screen.getByRole('checkbox', { name: /^Select missing\.log · ID/ }))
    await waitFor(() => expect(AXIOS_INSTANCE.get).toHaveBeenLastCalledWith('/api/admin/logs/entries', expect.objectContaining({ params: expect.objectContaining({ sources: 'missing' }) })))
    expect(active).toBeVisible()
  })

  it('expands the loaded multiline message immediately and keeps it when the row leaves the live window', async () => {
    const user = userEvent.setup()
    const message = 'First line\nSecond line with details\nThird line'
    const read = vi.mocked(AXIOS_INSTANCE.get).getMockImplementation()!
    vi.mocked(AXIOS_INSTANCE.get).mockImplementation(async (url, options) => {
      if (String(url).endsWith('/entries')) return { data: { data: { items: [{ ...record, message }], nextCursor: null, snapshot, coverage } } }
      if (String(url).includes('/entries/')) throw new Error('Detail expired')
      return read(url, options)
    })
    mount()
    await user.click(await screen.findByRole('option'))
    expect(screen.getByLabelText('Full log message').textContent).toBe(message)
    expect(vi.mocked(AXIOS_INSTANCE.get).mock.calls.some(([url]) => String(url).includes('/entries/'))).toBe(false)
    act(() => {
      client.setQueriesData({ queryKey: ['admin', 'logs', 'history'] }, value => {
        const data = value as InfiniteData<LogPage>
        return { ...data, pages: data.pages.map(page => ({ ...page, items: [] })) }
      })
      client.setQueriesData({ queryKey: ['admin', 'logs', 'tail'] }, value => ({ ...value as LogPage, items: [] }))
    })
    await waitFor(() => expect(screen.queryByRole('option')).not.toBeInTheDocument())
    expect(screen.getByLabelText('Full log message').textContent).toBe(message)
    expect(screen.getByRole('listbox', { name: 'Log messages' })).not.toHaveAttribute('aria-activedescendant')
    await user.click(screen.getByRole('button', { name: 'Close inspector' }))
    expect(screen.queryByRole('region', { name: 'Record inspector' })).not.toBeInTheDocument()
    expect(screen.getByRole('listbox', { name: 'Log messages' })).toHaveFocus()
  })

  it('excludes ordinary administrators before starting any log read', () => {
    auth.isSuperAdmin = false
    mount()
    expect(screen.queryByRole('heading', { name: 'Logs' })).not.toBeInTheDocument()
    expect(AXIOS_INSTANCE.get).not.toHaveBeenCalled()
  })
})

it('merges overlapping pages by identity without discarding repeated messages', () => {
  expect(mergeLogRecords([[record], [record, { ...record, id: 'node:two' }]]).map(row => row.id)).toEqual(['node:two', 'node:one'])
})

it('removes terminal controls without interpreting markup', () => {
  expect(safeLogText('\x1b[31m<script>plain</script>\x1b[0m\x07')).toBe('<script>plain</script>')
})

it('samples only the active tail and live source inventory every five seconds', () => {
  for (const variant of ['tail', 'sources-live']) {
    const descriptors = adminLiveDescriptors.filter(row => row.matches(['admin', 'logs', variant, {}]))
    expect(descriptors).toHaveLength(1)
    expect(descriptors[0].sampleIntervalMs).toBe(5000)
  }
  const history = adminLiveDescriptors.filter(row => row.matches(['admin', 'logs', 'history', {}]))
  expect(history).toHaveLength(1)
  expect(history[0].refreshPolicy).toBe('stored')
  for (const variant of ['sources', 'detail', 'sql']) {
    const descriptors = adminLiveDescriptors.filter(row => row.matches(['admin', 'logs', variant, {}]))
    expect(descriptors).toHaveLength(1)
    expect(descriptors[0].refreshPolicy).toBe('demand')
  }
})
