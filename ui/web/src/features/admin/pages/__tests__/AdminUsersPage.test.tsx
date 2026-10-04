import { act, cleanup, render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { ThemeProvider } from 'styled-components'
import { resolveTheme } from '@/shared/theme/resolve-theme'
import { AXIOS_INSTANCE } from '@/shared/api-client/axios-instance'
import type { AdminUserListParams } from '../../api/user-admin-api'
import { AdminUsersPage } from '../AdminUsersPage'

vi.mock('@/shared/api-client/axios-instance', () => ({ AXIOS_INSTANCE: { get: vi.fn() } }))
vi.mock('../../components/users/UserRowActions', () => ({ UserRowActions: () => null }))
vi.mock('../../components/users/CreateUserDialog', () => ({ CreateUserDialog: () => null }))
vi.mock('../../components/users/EditUserDialog', () => ({ EditUserDialog: () => null }))
vi.mock('../../components/users/AssignRolesDialog', () => ({ AssignRolesDialog: () => null }))
vi.mock('../../components/users/ResetPasswordDialog', () => ({ ResetPasswordDialog: () => null }))
vi.mock('../../components/users/DeleteUserDialog', () => ({ DeleteUserDialog: () => null }))

const mockGet = vi.mocked(AXIOS_INSTANCE.get)
let total: number
let fail: boolean
let client: QueryClient

function mount() {
  client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return render(
    <QueryClientProvider client={client}>
      <ThemeProvider theme={resolveTheme('dark', 'violet')}><AdminUsersPage /></ThemeProvider>
    </QueryClientProvider>,
  )
}

async function chooseFilter(label: string, option: string) {
  const user = userEvent.setup()
  await user.click(screen.getByRole('combobox', { name: label }))
  await user.click(await screen.findByRole('option', { name: option }))
}

describe('admin user pagination', () => {
  beforeEach(() => {
    total = 101
    fail = false
    mockGet.mockReset()
    mockGet.mockImplementation(async (_url, config) => {
      if (fail) throw new Error('Unavailable')
      const { offset = 0, limit = 50 } = config?.params as AdminUserListParams
      return { data: {
        data: Array.from({ length: Math.max(0, Math.min(limit, total - offset)) }, (_, i) => ({
          id: `${offset + i}`, email: `user${offset + i}@baander.app`, name: `User ${offset + i}`,
          roles: ['ROLE_USER'], disabled: false, createdAt: '2026-10-01T00:00:00Z', libraryAccess: [],
        })),
        meta: { total, limit, offset },
      } }
    })
    Element.prototype.hasPointerCapture = () => false
    Element.prototype.setPointerCapture = () => {}
    Element.prototype.releasePointerCapture = () => {}
    Element.prototype.scrollIntoView = () => {}
  })

  afterEach(() => {
    cleanup()
    client?.clear()
  })

  it('shows totals and navigates through bounded pages with correct query params', async () => {
    const user = userEvent.setup()
    mount()
    await screen.findByText('1–50 of 101')
    expect(screen.getByText('101 users')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Previous' })).toBeDisabled()
    await user.click(screen.getByRole('button', { name: 'Next' }))
    await screen.findByText('51–100 of 101')
    expect(screen.queryByText('user0@baander.app')).not.toBeInTheDocument()
    await user.click(screen.getByRole('button', { name: 'Next' }))
    await screen.findByText('101–101 of 101')
    expect(screen.getByRole('button', { name: 'Next' })).toBeDisabled()
    expect(mockGet).toHaveBeenLastCalledWith('/api/admin/users', expect.objectContaining({
      params: { offset: 100, limit: 50 },
    }))
    await user.click(screen.getByRole('button', { name: 'Previous' }))
    await screen.findByText('51–100 of 101')
  })

  it('resets each filter to the first page and retains combined filters including false', async () => {
    const user = userEvent.setup()
    mount()
    await screen.findByText('1–50 of 101')
    await user.click(screen.getByRole('button', { name: 'Next' }))
    await screen.findByText('51–100 of 101')
    await chooseFilter('Filter by role', 'Admin')
    await screen.findByText('1–50 of 101')
    expect(mockGet).toHaveBeenLastCalledWith('/api/admin/users', expect.objectContaining({
      params: { offset: 0, limit: 50, role: 'ROLE_ADMIN' },
    }))
    await user.click(screen.getByRole('button', { name: 'Next' }))
    await screen.findByText('51–100 of 101')
    await chooseFilter('Filter by status', 'Active')
    await screen.findByText('1–50 of 101')
    expect(mockGet).toHaveBeenLastCalledWith('/api/admin/users', expect.objectContaining({
      params: { offset: 0, limit: 50, role: 'ROLE_ADMIN', disabled: false },
    }))
  })

  it('recovers the last valid page when invalidation shrinks the result set', async () => {
    const user = userEvent.setup()
    mount()
    await screen.findByText('1–50 of 101')
    await user.click(screen.getByRole('button', { name: 'Next' }))
    await screen.findByText('51–100 of 101')
    await user.click(screen.getByRole('button', { name: 'Next' }))
    await screen.findByText('101–101 of 101')
    total = 50
    await act(async () => { await client.invalidateQueries({ queryKey: ['admin-users'] }) })
    await screen.findByText('1–50 of 50')
    expect(screen.getByRole('button', { name: 'Next' })).toBeDisabled()
    expect(screen.getByRole('button', { name: 'Previous' })).toBeDisabled()
  })

  it('shows request errors without stale rows or a false empty result and supports retry', async () => {
    const user = userEvent.setup()
    mount()
    await screen.findByText('user0@baander.app')
    fail = true
    await act(async () => { await client.invalidateQueries({ queryKey: ['admin-users'] }) })
    expect(await screen.findByRole('alert')).toHaveTextContent('Unable to load users.')
    expect(screen.queryByText('user0@baander.app')).not.toBeInTheDocument()
    expect(screen.queryByText('No users found.')).not.toBeInTheDocument()
    fail = false
    await user.click(screen.getByRole('button', { name: 'Retry' }))
    await screen.findByText('user0@baander.app')
    await waitFor(() => expect(screen.queryByRole('alert')).not.toBeInTheDocument())
  })
})
