import { cleanup, screen } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { render } from '../../../../../tests/test-utils'
import { getSpans } from '../../api/debug-api'
import { AdminSidebar } from '../../components/layout/AdminSidebar'
import { SpansPage } from '../SpansPage'

const auth = vi.hoisted(() => ({ isSuperAdmin: true }))
vi.mock('@/features/auth/hooks/use-admin-check', () => ({ useAdminCheck: () => auth }))
vi.mock('../../api/debug-api', () => ({ getSpans: vi.fn(), clearSpans: vi.fn() }))

let client: QueryClient

function mount() {
  client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return render(
    <QueryClientProvider client={client}>
      <MemoryRouter initialEntries={['/admin/spans']}>
        <AdminSidebar />
        <Routes>
          <Route path="/admin/spans" element={<SpansPage />} />
          <Route path="/admin" element={<h1>Admin overview</h1>} />
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

beforeEach(() => {
  auth.isSuperAdmin = true
  vi.mocked(getSpans).mockReset().mockResolvedValue([])
})

afterEach(() => {
  cleanup()
  client?.clear()
})

describe('SpansPage', () => {
  it('opens the spans workspace for a super admin and exposes its navigation entry', async () => {
    mount()

    expect(await screen.findByRole('region', { name: 'Recent spans' })).toBeInTheDocument()
    expect(getSpans).toHaveBeenCalledWith(500, expect.any(AbortSignal))
    expect(screen.getByRole('link', { name: 'Spans' })).toHaveAttribute('href', '/admin/spans')
  })

  it('redirects an ordinary admin before collecting spans and hides its navigation entry', async () => {
    auth.isSuperAdmin = false
    mount()

    expect(await screen.findByRole('heading', { name: 'Admin overview' })).toBeInTheDocument()
    expect(screen.queryByRole('region', { name: 'Recent spans' })).not.toBeInTheDocument()
    expect(screen.queryByRole('link', { name: 'Spans' })).not.toBeInTheDocument()
    expect(getSpans).not.toHaveBeenCalled()
  })
})
