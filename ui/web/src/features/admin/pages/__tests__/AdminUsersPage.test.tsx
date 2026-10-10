import { act, cleanup, render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { ThemeProvider } from 'styled-components'
import { resolveTheme } from '@/shared/theme/resolve-theme'
import { AxiosError, type AxiosResponse } from 'axios'
import { AXIOS_INSTANCE, customInstance } from '@/shared/api-client/axios-instance'
import type { AdminUserListParams } from '../../api/user-admin-api'
import { AdminUsersPage } from '../AdminUsersPage'

vi.mock('@/shared/api-client/axios-instance', () => ({
  AXIOS_INSTANCE: { get: vi.fn() },
  customInstance: vi.fn(),
}))
vi.mock('@/features/auth/hooks/use-admin-check', () => ({
  useAdminCheck: () => ({ isAdmin: true, isSuperAdmin: superAdmin, roles: [] }),
}))
vi.mock('../../components/users/UserRowActions', () => ({ UserRowActions: () => null }))
vi.mock('../../components/users/CreateUserDialog', () => ({ CreateUserDialog: () => null }))
vi.mock('../../components/users/EditUserDialog', () => ({ EditUserDialog: () => null }))
vi.mock('../../components/users/AssignRolesDialog', () => ({ AssignRolesDialog: () => null }))
vi.mock('../../components/users/LibraryAccessDialog', () => ({ LibraryAccessDialog: () => null }))
vi.mock('../../components/users/ResetPasswordDialog', () => ({ ResetPasswordDialog: () => null }))
vi.mock('../../components/users/DeleteUserDialog', () => ({ DeleteUserDialog: () => null }))

const mockGet = vi.mocked(AXIOS_INSTANCE.get)
const mockCustomInstance = vi.mocked(customInstance)
let total: number
let fail: boolean
let client: QueryClient
let superAdmin: boolean

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
    superAdmin = true
    mockGet.mockReset()
    mockCustomInstance.mockReset()
    mockGet.mockImplementation(async (_url, config) => {
      if (fail) throw new Error('Unavailable')
      const { offset = 0, limit = 50 } = config?.params as AdminUserListParams
      return { data: {
        data: Array.from({ length: Math.max(0, Math.min(limit, total - offset)) }, (_, i) => ({
          id: `${offset + i}`, email: `user${offset + i}@baander.app`, name: `User ${offset + i}`,
          roles: ['ROLE_USER'], disabled: false, createdAt: '2026-10-01T00:00:00Z',
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

describe('admin user management toggles', () => {
  beforeEach(() => {
    superAdmin = false
    mockGet.mockReset()
    mockGet.mockResolvedValue({ data: { data: [], meta: { total: 0, limit: 50, offset: 0 } } })
    mockCustomInstance.mockReset()
  })

  afterEach(() => {
    cleanup()
    client?.clear()
  })

  function adminSettings(canViewUsers: boolean, canCreateUsers: boolean) {
    mockCustomInstance.mockResolvedValue({
      data: [
        { key: 'admin.can_view_users', value: canViewUsers, storedValue: null, isExplicit: false, storedValueValid: true },
        { key: 'admin.can_create_users', value: canCreateUsers, storedValue: null, isExplicit: false, storedValueValid: true },
      ],
    })
  }

  it('shows Create User to a super admin without reading the settings', async () => {
    superAdmin = true
    mount()

    expect(await screen.findByRole('button', { name: /Create User/ })).toBeInTheDocument()
    expect(mockCustomInstance).not.toHaveBeenCalled()
  })

  it('hides Create User from an admin while admin.can_create_users is off', async () => {
    adminSettings(true, false)
    mount()

    await screen.findByText('No users found.')
    await waitFor(() => expect(mockCustomInstance).toHaveBeenCalledWith('/api/admin/settings', expect.anything()))
    expect(screen.queryByRole('button', { name: /Create User/ })).not.toBeInTheDocument()
  })

  it('shows Create User to an admin while admin.can_create_users is on', async () => {
    adminSettings(true, true)
    mount()

    expect(await screen.findByRole('button', { name: /Create User/ })).toBeInTheDocument()
  })

  it('replaces the list with an explanation when the server denies the admin the user list', async () => {
    adminSettings(false, false)
    mockGet.mockRejectedValue(new AxiosError('Forbidden', 'ERR_BAD_REQUEST', undefined, undefined, { status: 403 } as AxiosResponse))
    mount()

    expect(await screen.findByText(/Admins cannot view the user list on this server/)).toBeInTheDocument()
    expect(screen.queryByRole('alert')).not.toBeInTheDocument()
    expect(screen.queryByRole('combobox', { name: 'Filter by role' })).not.toBeInTheDocument()
    expect(screen.queryByRole('navigation', { name: 'Users pagination' })).not.toBeInTheDocument()
  })
})
