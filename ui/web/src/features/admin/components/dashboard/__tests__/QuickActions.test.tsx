import { cleanup, render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { ThemeProvider } from 'styled-components'
import { resolveTheme } from '@/shared/theme/resolve-theme'
import { AXIOS_INSTANCE } from '@/shared/api-client/axios-instance'
import { QuickActions } from '../QuickActions'

vi.mock('@/shared/api-client/axios-instance', () => ({
  AXIOS_INSTANCE: { get: vi.fn() },
}))
vi.mock('../../../hooks/use-lyrics-admin', () => ({
  useBulkFetchLyrics: () => ({ mutateAsync: vi.fn() }),
}))
vi.mock('../../../hooks/use-job-monitor', () => ({
  useFlushFailedQueue: () => ({ mutateAsync: vi.fn() }),
}))

const mockGet = vi.mocked(AXIOS_INSTANCE.get)

function mount() {
  const client = new QueryClient({ defaultOptions: { mutations: { retry: false } } })
  render(
    <QueryClientProvider client={client}>
      <ThemeProvider theme={resolveTheme('dark', 'violet')}><QuickActions /></ThemeProvider>
    </QueryClientProvider>,
  )
}

describe('QuickActions', () => {
  beforeEach(() => {
    mockGet.mockReset()
  })

  afterEach(() => {
    cleanup()
  })

  it('marks a finished action with a check icon rather than a glyph', async () => {
    mockGet.mockResolvedValue({ data: {} })
    mount()
    const button = screen.getByRole('button', { name: 'Run Health Check' })

    await userEvent.setup().click(button)

    await waitFor(() => expect(button.querySelector('svg.lucide-check')).not.toBeNull())
    expect(button).not.toHaveTextContent('✓')
  })

  it('marks a failed action with an X icon rather than a glyph', async () => {
    mockGet.mockRejectedValue(new Error('Unavailable'))
    mount()
    const button = screen.getByRole('button', { name: 'Run Health Check' })

    await userEvent.setup().click(button)

    await waitFor(() => expect(button.querySelector('svg.lucide-x')).not.toBeNull())
    expect(button).not.toHaveTextContent('✗')
  })
})
