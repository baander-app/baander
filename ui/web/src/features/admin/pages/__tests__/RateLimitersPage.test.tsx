import { cleanup, render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { ThemeProvider } from 'styled-components'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { AXIOS_INSTANCE } from '@/shared/api-client/axios-instance'
import { resolveTheme } from '@/shared/theme/resolve-theme'
import type { RateLimitersResponse } from '../../api/rate-limiter-api'
import { RateLimitersPage } from '../RateLimitersPage'

vi.mock('@/shared/api-client/axios-instance', () => ({
  AXIOS_INSTANCE: {
    get: vi.fn(),
    delete: vi.fn(),
  },
}))

const authState = vi.hoisted(() => ({ roles: ['ROLE_ADMIN'] as string[] }))

vi.mock('@/features/auth/stores/auth-store', () => ({
  useAuthStore: vi.fn((selector: (state: { user: { roles: string[] } }) => unknown) =>
    selector({ user: { roles: authState.roles } })),
}))

const mockGet = vi.mocked(AXIOS_INSTANCE.get)
const mockDelete = vi.mocked(AXIOS_INSTANCE.delete)

const LIMITERS: RateLimitersResponse = {
  limiters: {
    auth_login_ip: {
      policy: 'fixed_window',
      limit: 20,
      interval: '300 seconds',
      description: 'Login attempts per IP (brute-force protection)',
      storageNamespace: 'baander:rate-limiter:{auth_login_ip}',
    },
  },
  count: 1,
}

let queryClient: QueryClient

function mount() {
  queryClient = new QueryClient({
    defaultOptions: {
      queries: { retry: false },
      mutations: { retry: false },
    },
  })

  return render(
    <QueryClientProvider client={queryClient}>
      <ThemeProvider theme={resolveTheme('dark', 'violet')}>
        <RateLimitersPage />
      </ThemeProvider>
    </QueryClientProvider>,
  )
}

describe('RateLimitersPage', () => {
  beforeEach(() => {
    authState.roles = ['ROLE_ADMIN']
    mockGet.mockReset()
    mockDelete.mockReset()
    mockGet.mockResolvedValue({ data: { data: LIMITERS } })
  })

  afterEach(() => {
    cleanup()
    queryClient?.clear()
  })

  it('lists the limiters to an admin without offering to clear them', async () => {
    mount()

    expect(await screen.findByText('auth_login_ip')).toBeInTheDocument()
    expect(screen.getByText('Only super admins can clear rate limiters.')).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Clear All' })).not.toBeInTheDocument()
  })

  it('lets a super admin clear every limiter after confirming', async () => {
    authState.roles = ['ROLE_SUPER_ADMIN']
    mockDelete.mockResolvedValue({ data: { data: { cleared: true, limiters: ['auth_login_ip'] } } })
    mount()
    const user = userEvent.setup()

    await user.click(await screen.findByRole('button', { name: 'Clear All' }))
    await user.click(screen.getByRole('button', { name: 'Confirm Clear' }))

    expect(mockDelete).toHaveBeenCalledWith('/api/monitor/rate-limiters/clear', {
      params: { confirm: 'true' },
    })
    expect(screen.queryByText('Only super admins can clear rate limiters.')).not.toBeInTheDocument()
  })
})
