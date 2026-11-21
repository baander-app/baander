import { screen, within, waitFor, act } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { render } from '../../../../../tests/test-utils'
import { clearSpans, getSpans, type Span } from '../../api/debug-api'
import { SpansSection } from './SpansSection'

vi.mock('../../api/debug-api', () => ({ getSpans: vi.fn(), clearSpans: vi.fn() }))
const parent: Span = {
  trace_id: 'trace-1', span_id: 'parent', parent_span_id: null,
  operation_name: 'GET albums', service: 'api', kind: '2',
  start_time_us: 1_790_000_000_123456, duration_us: 20_000,
  attributes: { route: '/albums', unsafe: '<img src=x onerror=alert(1)>', nested: { enabled: true } },
  status_code: 'Unset', status_message: '', file_path: '/app/Albums.php', line_number: 42,
}
const child: Span = { ...parent, span_id: 'child', parent_span_id: 'gone', start_time_us: parent.start_time_us + 1000, operation_name: 'Read albums', service: 'storage', duration_us: 5_000 }
function mount() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } })
  render(<QueryClientProvider client={client}><SpansSection /></QueryClientProvider>)
  return client
}
function rowCount(grid: HTMLElement) {
  return within(grid).queryAllByRole('row', { selected: false }).length + within(grid).queryAllByRole('row', { selected: true }).length
}
async function openTrace(operation = /GET albums/) {
  await userEvent.click(await screen.findByRole('row', { name: operation }))
  return screen.findByRole('treegrid', { name: 'Trace waterfall' })
}
async function chooseParent() {
  const trace = await openTrace()
  await userEvent.click(within(trace).getByRole('row', { name: /GET albums/ }))
  return screen.getByRole('region', { name: 'Span inspector' })
}
beforeEach(() => {
  Element.prototype.hasPointerCapture = () => false
  Element.prototype.releasePointerCapture = () => {}
  Element.prototype.scrollIntoView = () => {}
  vi.mocked(getSpans).mockReset().mockResolvedValue([parent, child])
  vi.mocked(clearSpans).mockReset().mockResolvedValue(undefined)
})
describe('span inspection', () => {
  it('requests the full ring and renders all fields as inert text inline', async () => {
    mount()
    const inspector = await chooseParent()
    expect(getSpans).toHaveBeenCalledWith(500, expect.any(AbortSignal))
    expect(within(inspector).getByText('<img src=x onerror=alert(1)>')).toBeInTheDocument()
    expect(inspector.querySelector('img')).toBeNull()
    await userEvent.click(within(inspector).getByText('Span metadata'))
    expect(within(inspector).getByText('server')).toBeVisible()
    expect(within(inspector).getByText('2026-09-21T14:13:20.123456Z')).toBeVisible()
    expect(within(inspector).getByText('/app/Albums.php:42')).toBeVisible()
    expect(screen.getByRole('treegrid', { name: 'Trace waterfall' })).toContainElement(inspector)
  })
  it('preserves the whole trace and restores search after focused keyboard inspection', async () => {
    mount()
    await screen.findByRole('treegrid', { name: 'Spans' })
    await userEvent.type(screen.getByRole('textbox', { name: 'Search spans' }), 'Read albums')
    const trace = await openTrace(/Read albums/)
    expect(rowCount(trace)).toBe(2)
    expect(within(trace).getByText('Missing parent')).toBeInTheDocument()
    expect(screen.queryByRole('textbox', { name: 'Search spans' })).not.toBeInTheDocument()
    within(trace).getByRole('row', { name: /Read albums/ }).focus()
    await userEvent.keyboard('{Home}{Enter}')
    expect(within(screen.getByRole('region', { name: 'Span inspector' })).getByRole('heading', { name: 'GET albums' })).toBeInTheDocument()
    await userEvent.click(screen.getByRole('button', { name: 'Back to results' }))
    expect(screen.getByRole('textbox', { name: 'Search spans' })).toHaveValue('Read albums')
    expect(rowCount(screen.getByRole('treegrid', { name: 'Spans' }))).toBe(1)
  })
  it('compares independent requests and opens only the chosen trace with a retained summary', async () => {
    vi.mocked(getSpans).mockResolvedValue([parent, { ...child, trace_id: 'other-trace' }])
    mount()
    expect(rowCount(await screen.findByRole('treegrid', { name: 'Spans' }))).toBe(2)
    expect(rowCount(await openTrace())).toBe(1)
    expect(screen.getByRole('img', { name: 'Trace overview' })).toBeInTheDocument()
    const summary = screen.getByLabelText('Retained trace summary')
    expect(within(summary).getByText('Services').nextElementSibling).toHaveTextContent('1')
    expect(within(summary).getByText('Depth').nextElementSibling).toHaveTextContent('1')
    expect(within(summary).getByText('Spans').nextElementSibling).toHaveTextContent('1')
    await userEvent.click(screen.getByRole('button', { name: 'Back to results' }))
    expect(rowCount(screen.getByRole('treegrid', { name: 'Spans' }))).toBe(2)
  })
  it('validates duration ranges and resets combined filters', async () => {
    mount()
    await screen.findByRole('treegrid', { name: 'Spans' })
    await userEvent.type(screen.getByLabelText('Minimum duration (ms)'), '10')
    expect(rowCount(screen.getByRole('treegrid', { name: 'Spans' }))).toBe(1)
    await userEvent.type(screen.getByLabelText('Maximum duration (ms)'), '2')
    expect(screen.getByText('Maximum duration must be at least the minimum duration.')).toBeInTheDocument()
    await userEvent.click(screen.getByRole('button', { name: 'Reset filters' }))
    expect(rowCount(screen.getByRole('treegrid', { name: 'Spans' }))).toBe(2)
  })
  it('identifies a missing root without inventing a complete trace', async () => {
    vi.mocked(getSpans).mockResolvedValue([child])
    mount()
    const trace = await openTrace(/Read albums/)
    expect(screen.getByText('Root span unavailable in the retained snapshot; showing the entry span.')).toBeInTheDocument()
    expect(screen.getByRole('heading', { name: 'storage: Read albums' })).toBeInTheDocument()
    expect(rowCount(trace)).toBe(1)
    expect(within(trace).getByText('Missing parent')).toBeInTheDocument()
  })
  it('keeps details on refresh errors and returns to results when a refreshed selection is evicted', async () => {
    mount()
    await chooseParent()
    vi.mocked(getSpans).mockRejectedValueOnce(new Error('unavailable'))
    await userEvent.click(screen.getByRole('button', { name: 'Refresh' }))
    expect(await screen.findByText('Failed to refresh spans. Showing the last retained snapshot.')).toBeInTheDocument()
    expect(screen.getByRole('region', { name: 'Span inspector' })).toBeInTheDocument()
    vi.mocked(getSpans).mockResolvedValue([child])
    await userEvent.click(screen.getByRole('button', { name: 'Refresh' }))
    expect(await screen.findByText('The selected span is no longer retained.')).toBeInTheDocument()
    expect(screen.queryByRole('region', { name: 'Span inspector' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Back to results' })).not.toBeInTheDocument()
    expect(screen.getByRole('treegrid', { name: 'Spans' })).toBeInTheDocument()
  })
  it('preserves details after failed clear and empties the displayed snapshot after successful clear', async () => {
    mount()
    await chooseParent()
    vi.mocked(clearSpans).mockRejectedValueOnce(new Error('denied'))
    await userEvent.click(screen.getByRole('button', { name: 'Clear' }))
    expect(await screen.findByText('Failed to clear spans.')).toBeInTheDocument()
    expect(screen.getByRole('region', { name: 'Span inspector' })).toBeInTheDocument()
    await userEvent.click(screen.getByRole('button', { name: 'Clear' }))
    expect(await screen.findByText('No spans recorded.')).toBeInTheDocument()
    expect(screen.queryByRole('region', { name: 'Span inspector' })).not.toBeInTheDocument()
  })
  it('distinguishes an empty ring from no search matches', async () => {
    mount()
    await screen.findByRole('treegrid', { name: 'Spans' })
    await userEvent.type(screen.getByRole('textbox', { name: 'Search spans' }), 'missing')
    expect(screen.getByText('No spans match the current filters.')).toBeInTheDocument()
    vi.mocked(getSpans).mockResolvedValue([])
    await userEvent.click(screen.getByRole('button', { name: 'Refresh' }))
    expect(await screen.findByText('No spans recorded.')).toBeInTheDocument()
  })
  it('retries an initial read failure', async () => {
    vi.mocked(getSpans).mockRejectedValueOnce(new Error('unavailable'))
    mount()
    expect(await screen.findByText('Failed to load spans.')).toBeInTheDocument()
    await userEvent.click(screen.getByRole('button', { name: 'Retry' }))
    expect(await screen.findByRole('treegrid', { name: 'Spans' })).toBeInTheDocument()
  })
  it('holds the selected snapshot through background arrivals and adopts an explicit refresh', async () => {
    const client = mount()
    const inspector = await chooseParent()
    await userEvent.click(within(inspector).getByText('Span metadata'))
    act(() => client.setQueryData(['debug', 'spans', 500], [child]))
    expect(screen.getByRole('region', { name: 'Span inspector' })).toBeInTheDocument()
    expect(screen.queryByText('The selected span is no longer retained.')).not.toBeInTheDocument()
    vi.mocked(getSpans).mockResolvedValue([{ ...parent, status_message: 'Updated snapshot' }, child])
    await userEvent.click(screen.getByRole('button', { name: 'Refresh' }))
    expect(await screen.findByText('Updated snapshot')).toBeVisible()
    expect(within(screen.getByRole('region', { name: 'Span inspector' })).getByText('parent')).toBeVisible()
  })
  it('closes inline details with Escape and restores the span header focus', async () => {
    mount()
    const inspector = await chooseParent()
    within(inspector).getByRole('button', { name: 'Close inspector' }).focus()
    await userEvent.keyboard('{Escape}')
    expect(screen.queryByRole('region', { name: 'Span inspector' })).not.toBeInTheDocument()
    const trace = screen.getByRole('treegrid', { name: 'Trace waterfall' })
    await waitFor(() => expect(within(trace).getByRole('row', { name: /GET albums/ })).toHaveFocus())
    await userEvent.keyboard('{End}{Enter}')
    expect(screen.getByRole('region', { name: 'Span inspector' })).toBeInTheDocument()
  })
  it('combines service and status controls', async () => {
    vi.mocked(getSpans).mockResolvedValue([parent, { ...child, status_code: 'Error' }])
    mount()
    await screen.findByRole('treegrid', { name: 'Spans' })
    await userEvent.click(screen.getByRole('combobox', { name: 'Service' }))
    await userEvent.click(screen.getByRole('option', { name: 'storage' }))
    expect(rowCount(screen.getByRole('treegrid', { name: 'Spans' }))).toBe(1)
    await userEvent.click(screen.getByRole('combobox', { name: 'Status' }))
    await userEvent.click(screen.getByRole('option', { name: 'Non-error' }))
    expect(screen.getByText('No spans match the current filters.')).toBeInTheDocument()
    await userEvent.click(screen.getByRole('button', { name: 'Reset filters' }))
    expect(rowCount(screen.getByRole('treegrid', { name: 'Spans' }))).toBe(2)
  })
  it('keeps an active service filter readable when its spans leave the snapshot', async () => {
    mount()
    await screen.findByRole('treegrid', { name: 'Spans' })
    await userEvent.click(screen.getByRole('combobox', { name: 'Service' }))
    await userEvent.click(screen.getByRole('option', { name: 'storage' }))
    vi.mocked(getSpans).mockResolvedValue([parent])
    await userEvent.click(screen.getByRole('button', { name: 'Refresh' }))
    expect(await screen.findByText('No spans match the current filters.')).toBeInTheDocument()
    expect(screen.getByRole('combobox', { name: 'Service' })).toHaveTextContent('storage')
  })
})
