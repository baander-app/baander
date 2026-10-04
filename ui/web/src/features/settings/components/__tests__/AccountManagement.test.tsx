import { cleanup, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { AxiosError, AxiosHeaders } from 'axios'
import { MemoryRouter } from 'react-router-dom'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { AXIOS_INSTANCE } from '@/shared/api-client/axios-instance'
import { render } from '../../../../../tests/test-utils'
import { AccountManagement } from '../AccountManagement'

vi.mock('@/shared/api-client/axios-instance', () => ({ AXIOS_INSTANCE: { put: vi.fn() } }))
vi.mock('@/features/auth/stores/auth-store', () => {
  const state = { user: { email: 'member@baander.app' }, logout: vi.fn() }
  return { useAuthStore: (selector: (value: typeof state) => unknown) => selector(state) }
})

const mockPut = vi.mocked(AXIOS_INSTANCE.put)

function apiError(data: unknown) {
  const config = { headers: new AxiosHeaders() }
  return new AxiosError('Request failed with status code 400', 'ERR_BAD_REQUEST', config, undefined, {
    data,
    status: 400,
    statusText: 'Bad Request',
    headers: new AxiosHeaders(),
    config,
  })
}

async function saveAccountChange(kind: 'email' | 'password') {
  const user = userEvent.setup()
  render(<MemoryRouter><AccountManagement /></MemoryRouter>)
  await user.click(screen.getAllByRole('button', { name: 'Change' })[kind === 'email' ? 0 : 1])

  if (kind === 'email') {
    await user.clear(screen.getByLabelText('New email'))
    await user.type(screen.getByLabelText('New email'), 'updated@baander.app')
  } else {
    await user.type(screen.getByLabelText('Current password'), 'current-password')
    await user.type(screen.getByLabelText('New password'), 'new-password')
    await user.type(screen.getByLabelText('Confirm new password'), 'new-password')
  }

  await user.click(screen.getByRole('button', { name: 'Save' }))
}

describe('account change failures', () => {
  beforeEach(() => mockPut.mockReset())
  afterEach(cleanup)

  it.each([
    { kind: 'email' as const, message: 'This email address is already in use.', payload: { email: 'updated@baander.app' } },
    { kind: 'password' as const, message: 'The current password is incorrect.', payload: { currentPassword: 'current-password', newPassword: 'new-password' } },
  ])('shows the nested backend message after a failed $kind save', async ({ kind, message, payload }) => {
    mockPut.mockRejectedValueOnce(apiError({ error: { code: 400, message } }))

    await saveAccountChange(kind)

    expect(await screen.findByText(message)).toBeInTheDocument()
    expect(screen.getByRole('dialog', { name: `Change ${kind}` })).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Save' })).toBeEnabled()
    expect(mockPut).toHaveBeenCalledWith(`/api/auth/me/${kind}`, payload)
    expect(screen.queryByText(`Failed to change ${kind}.`)).not.toBeInTheDocument()
  })

  it.each(['email', 'password'] as const)('keeps the %s fallback for an unstructured failure', async (kind) => {
    mockPut.mockRejectedValueOnce(new Error('Network unavailable'))

    await saveAccountChange(kind)

    expect(await screen.findByText(`Failed to change ${kind}.`)).toBeInTheDocument()
    expect(screen.getByRole('dialog', { name: `Change ${kind}` })).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Save' })).toBeEnabled()
  })
})
