import { screen, within, waitFor } from '@testing-library/react'
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
async function chooseParent() {
  await userEvent.click(await screen.findByRole('option', { name: /GET albums/ }))
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
  it('requests the full ring and renders all fields as inert text', async () => {
    mount()
    const inspector = await chooseParent()
    expect(getSpans).toHaveBeenCalledWith(500, expect.any(AbortSignal))
    expect(within(inspector).getByText('<img src=x onerror=alert(1)>')).toBeInTheDocument()
    expect(inspector.querySelector('img')).toBeNull()
    expect(within(inspector).getByText('server')).toBeInTheDocument()
    expect(within(inspector).getAllByText('2026-09-21T14:13:20.123456Z')[0]).toBeInTheDocument()
    expect(within(inspector).getByText('/app/Albums.php:42')).toBeInTheDocument()
  })
  it('filters results while preserving the whole trace and allows keyboard waterfall selection', async () => {
    mount()
    await screen.findByRole('listbox', { name: 'Spans' })
    await userEvent.type(screen.getByRole('textbox', { name: 'Search spans' }), 'Read albums')
    await userEvent.click(screen.getByRole('option', { name: /Read albums/ }))
    const waterfall = screen.getByRole('listbox', { name: 'Trace waterfall' })
    expect(within(waterfall).getAllByRole('option')).toHaveLength(2)
    expect(within(waterfall).getByText(/Missing parent/)).toBeInTheDocument()
    waterfall.focus()
    await userEvent.keyboard('{Home}{Enter}')
    expect(within(screen.getByRole('region', { name: 'Span inspector' })).getByText('Selected span is hidden by the current filters.')).toBeInTheDocument()
  })
  it('validates duration ranges and resets combined filters', async () => {
    mount()
    await screen.findByRole('listbox', { name: 'Spans' })
    await userEvent.type(screen.getByLabelText('Minimum duration (ms)'), '10')
    expect(screen.getAllByRole('option')).toHaveLength(1)
    await userEvent.type(screen.getByLabelText('Maximum duration (ms)'), '2')
    expect(screen.getByText('Maximum duration must be at least the minimum duration.')).toBeInTheDocument()
    await userEvent.click(screen.getByRole('button', { name: 'Reset filters' }))
    expect(screen.getAllByRole('option')).toHaveLength(2)
  })
  it('keeps details on refresh errors and explains eviction after a successful snapshot', async () => {
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
  })
  it('preserves details after failed clear and dismisses them after successful clear', async () => {
    mount()
    await chooseParent()
    vi.mocked(clearSpans).mockRejectedValueOnce(new Error('denied'))
    await userEvent.click(screen.getByRole('button', { name: 'Clear' }))
    expect(await screen.findByText('Failed to clear spans.')).toBeInTheDocument()
    expect(screen.getByRole('region', { name: 'Span inspector' })).toBeInTheDocument()
    await userEvent.click(screen.getByRole('button', { name: 'Clear' }))
    await waitFor(() => expect(screen.queryByRole('region', { name: 'Span inspector' })).not.toBeInTheDocument())
  })
  it('distinguishes an empty ring from no search matches', async () => {
    const client = mount()
    await screen.findByRole('listbox', { name: 'Spans' })
    await userEvent.type(screen.getByRole('textbox', { name: 'Search spans' }), 'missing')
    expect(screen.getByText('No spans match the current filters.')).toBeInTheDocument()
    client.setQueryData(['debug', 'spans', 500], [])
    expect(await screen.findByText('No spans recorded.')).toBeInTheDocument()
  })
  it('retries an initial read failure', async () => {
    vi.mocked(getSpans).mockRejectedValueOnce(new Error('unavailable'))
    mount()
    expect(await screen.findByText('Failed to load spans.')).toBeInTheDocument()
    await userEvent.click(screen.getByRole('button', { name: 'Retry' }))
    expect(await screen.findByRole('listbox', { name: 'Spans' })).toBeInTheDocument()
  })
  it('shows updated details for the selected identity after refresh', async () => {
    mount()
    await chooseParent()
    vi.mocked(getSpans).mockResolvedValue([{ ...parent, status_message: 'Updated snapshot' }, child])
    await userEvent.click(screen.getByRole('button', { name: 'Refresh' }))
    expect(await screen.findByText('Updated snapshot')).toBeInTheDocument()
    expect(within(screen.getByRole('region', { name: 'Span inspector' })).getByText('parent')).toBeInTheDocument()
  })
  it('closes with Escape and restores result keyboard focus', async () => {
    mount()
    const inspector = await chooseParent()
    within(inspector).getByRole('button', { name: 'Close inspector' }).focus()
    await userEvent.keyboard('{Escape}')
    expect(screen.queryByRole('region', { name: 'Span inspector' })).not.toBeInTheDocument()
    expect(screen.getByRole('listbox', { name: 'Spans' })).toHaveFocus()
    await userEvent.keyboard('{End}{Enter}')
    expect(screen.getByRole('region', { name: 'Span inspector' })).toBeInTheDocument()
  })
  it('combines service and status controls', async () => {
    vi.mocked(getSpans).mockResolvedValue([parent, { ...child, status_code: 'Error' }])
    mount()
    await screen.findByRole('listbox', { name: 'Spans' })
    await userEvent.click(screen.getByRole('combobox', { name: 'Service' }))
    await userEvent.click(screen.getByRole('option', { name: 'storage' }))
    expect(within(screen.getByRole('listbox', { name: 'Spans' })).getAllByRole('option')).toHaveLength(1)
    await userEvent.click(screen.getByRole('combobox', { name: 'Status' }))
    await userEvent.click(screen.getByRole('option', { name: 'Non-error' }))
    expect(screen.getByText('No spans match the current filters.')).toBeInTheDocument()
    await userEvent.click(screen.getByRole('button', { name: 'Reset filters' }))
    expect(within(screen.getByRole('listbox', { name: 'Spans' })).getAllByRole('option')).toHaveLength(2)
  })

  it('keeps an active service filter readable when its spans leave the snapshot', async () => {
    mount()
    await screen.findByRole('listbox', { name: 'Spans' })
    await userEvent.click(screen.getByRole('combobox', { name: 'Service' }))
    await userEvent.click(screen.getByRole('option', { name: 'storage' }))
    vi.mocked(getSpans).mockResolvedValue([parent])
    await userEvent.click(screen.getByRole('button', { name: 'Refresh' }))
    expect(await screen.findByText('No spans match the current filters.')).toBeInTheDocument()
    expect(screen.getByRole('combobox', { name: 'Service' })).toHaveTextContent('storage')
  })

})
