import { cleanup, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { AxiosError, AxiosHeaders } from 'axios'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter } from 'react-router-dom'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { useAuthStore } from '@/features/auth/stores/auth-store'
import { AXIOS_INSTANCE, customInstance } from '@/shared/api-client/axios-instance'
import type { UserResource } from '@/shared/api-client/gen/endpoints'
import { render } from '../../../../../tests/test-utils'
import { AccountManagement } from '../AccountManagement'

vi.mock('@/shared/api-client/axios-instance', () => ({
  AXIOS_INSTANCE: { put: vi.fn() },
  customInstance: vi.fn(),
}))

const mockPut = vi.mocked(AXIOS_INSTANCE.put)
const mockRequest = vi.mocked(customInstance)

const VERIFIED_AT = '2026-10-01T09:30:00+00:00'

function profile(overrides: Partial<UserResource> = {}): UserResource {
  return {
    uuid: '0192a7c4-6a4e-7c3b-9f1d-2b8e5c7a1d40',
    publicId: 'usr_member',
    name: 'Member',
    email: 'member@baander.app',
    emailVerifiedAt: VERIFIED_AT,
    createdAt: '2026-10-01T09:00:00+00:00',
    roles: ['ROLE_USER'],
    ...overrides,
  }
}

/** Signs in a user in the store and makes /api/auth/me return the same profile. */
function signIn(user: UserResource) {
  useAuthStore.setState({
    accessToken: 'access',
    refreshToken: 'refresh',
    isAuthenticated: true,
    user: {
      uuid: user.uuid,
      publicId: user.publicId,
      name: user.name,
      email: user.email,
      roles: user.roles,
      emailVerifiedAt: user.emailVerifiedAt,
    },
  })
  mockRequest.mockImplementation(async (url: string) => {
    if (url === '/api/auth/me') return { data: user }

    throw new Error(`Unexpected request to ${url}`)
  })
}

function httpError(status: number, message: string, headers: Record<string, string> = {}) {
  const config = { headers: new AxiosHeaders() }
  return new AxiosError(`Request failed with status code ${status}`, 'ERR_BAD_REQUEST', config, undefined, {
    data: { error: { code: status, message } },
    status,
    statusText: 'Error',
    headers,
    config,
  })
}

function renderAccount() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } })

  return render(
    <QueryClientProvider client={client}>
      <MemoryRouter>
        <AccountManagement />
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

/** Waits until the profile read on mount has been applied. */
async function waitForProfileRefresh() {
  await waitFor(() => expect(mockRequest).toHaveBeenCalledWith('/api/auth/me', expect.anything()))
}

async function saveAccountChange(kind: 'email' | 'password') {
  const user = userEvent.setup()
  renderAccount()
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

/** Routes the email change PUT through the generated client and anything else to /me. */
function answerEmailChange(answer: () => Promise<unknown>) {
  const me = mockRequest.getMockImplementation()
  mockRequest.mockImplementation(async (url: string, init: RequestInit) => {
    if (url === '/api/auth/me/email') return answer()

    return me ? me(url, init) : undefined
  })
}

describe('AccountManagement', () => {
  beforeEach(() => {
    mockPut.mockReset()
    mockRequest.mockReset()
    signIn(profile())
  })

  afterEach(() => {
    cleanup()
    useAuthStore.setState({ accessToken: null, refreshToken: null, user: null, isAuthenticated: false })
  })

  describe('account change failures', () => {
    it('shows the nested backend message after a failed email save', async () => {
      answerEmailChange(() => Promise.reject(httpError(409, 'This email address is already in use.')))

      await saveAccountChange('email')

      expect(await screen.findByText('This email address is already in use.')).toBeInTheDocument()
      expect(screen.getByRole('dialog', { name: 'Change email' })).toBeInTheDocument()
      expect(screen.getByRole('button', { name: 'Save' })).toBeEnabled()
      expect(mockRequest).toHaveBeenCalledWith('/api/auth/me/email', expect.objectContaining({
        method: 'PUT',
        body: JSON.stringify({ email: 'updated@baander.app' }),
      }))
      expect(screen.queryByText('Failed to change email.')).not.toBeInTheDocument()
    })

    it('shows the nested backend message after a failed password save', async () => {
      mockPut.mockRejectedValueOnce(httpError(400, 'The current password is incorrect.'))

      await saveAccountChange('password')

      expect(await screen.findByText('The current password is incorrect.')).toBeInTheDocument()
      expect(screen.getByRole('dialog', { name: 'Change password' })).toBeInTheDocument()
      expect(screen.getByRole('button', { name: 'Save' })).toBeEnabled()
      expect(mockPut).toHaveBeenCalledWith('/api/auth/me/password', { currentPassword: 'current-password', newPassword: 'new-password' })
      expect(screen.queryByText('Failed to change password.')).not.toBeInTheDocument()
    })

    it('keeps the email fallback for an unstructured failure', async () => {
      answerEmailChange(() => Promise.reject(new Error('Network unavailable')))

      await saveAccountChange('email')

      expect(await screen.findByText('Failed to change email.')).toBeInTheDocument()
      expect(screen.getByRole('dialog', { name: 'Change email' })).toBeInTheDocument()
      expect(screen.getByRole('button', { name: 'Save' })).toBeEnabled()
    })

    it('keeps the password fallback for an unstructured failure', async () => {
      mockPut.mockRejectedValueOnce(new Error('Network unavailable'))

      await saveAccountChange('password')

      expect(await screen.findByText('Failed to change password.')).toBeInTheDocument()
      expect(screen.getByRole('dialog', { name: 'Change password' })).toBeInTheDocument()
      expect(screen.getByRole('button', { name: 'Save' })).toBeEnabled()
    })
  })

  describe('email verification', () => {
    it('shows no verification notice for a verified address', async () => {
      renderAccount()
      await waitForProfileRefresh()

      expect(screen.getByText('member@baander.app')).toBeInTheDocument()
      expect(screen.queryByText('Not verified')).not.toBeInTheDocument()
      expect(screen.queryByRole('button', { name: 'Resend verification email' })).not.toBeInTheDocument()
    })

    it('resends the verification email for an unverified address', async () => {
      signIn(profile({ emailVerifiedAt: null }))
      const me = mockRequest.getMockImplementation()
      mockRequest.mockImplementation(async (url: string, init: RequestInit) => {
        if (url === '/api/auth/me/email/verification') {
          return { data: { message: 'If your email address still needs verification, a new link has been sent.' } }
        }

        return me ? me(url, init) : undefined
      })
      renderAccount()

      expect(screen.getByText('Not verified')).toBeInTheDocument()
      const user = userEvent.setup()
      await user.click(screen.getByRole('button', { name: 'Resend verification email' }))

      expect(await screen.findByRole('status')).toHaveTextContent('If your email address still needs verification, a new link is on its way.')
      expect(mockRequest).toHaveBeenCalledWith('/api/auth/me/email/verification', expect.objectContaining({ method: 'POST' }))
    })

    it('waits for the Retry-After delay when resending is rate limited', async () => {
      signIn(profile({ emailVerifiedAt: null }))
      const me = mockRequest.getMockImplementation()
      mockRequest.mockImplementation(async (url: string, init: RequestInit) => {
        if (url === '/api/auth/me/email/verification') throw httpError(429, 'Too many requests.', { 'retry-after': '30' })

        return me ? me(url, init) : undefined
      })
      renderAccount()

      const user = userEvent.setup()
      await user.click(screen.getByRole('button', { name: 'Resend verification email' }))

      expect(await screen.findByRole('alert')).toHaveTextContent('Too many attempts. Try again in 30 seconds.')
      expect(screen.getByRole('button', { name: 'Resend verification email' })).toBeDisabled()
      expect(screen.queryByRole('status')).not.toBeInTheDocument()
    })

    it('shows the notice once a changed address comes back unverified', async () => {
      answerEmailChange(async () => ({ data: profile({ email: 'updated@baander.app', emailVerifiedAt: null }) }))

      await saveAccountChange('email')

      await waitFor(() => expect(screen.queryByRole('dialog', { name: 'Change email' })).not.toBeInTheDocument())
      expect(screen.getByText('updated@baander.app')).toBeInTheDocument()
      expect(screen.getByText('Not verified')).toBeInTheDocument()
      expect(useAuthStore.getState().user?.emailVerifiedAt).toBeNull()
    })

    it('tells the user a verification link goes to the new address', async () => {
      renderAccount()
      const user = userEvent.setup()
      await user.click(screen.getAllByRole('button', { name: 'Change' })[0])

      expect(screen.getByRole('dialog', { name: 'Change email' })).toHaveTextContent('We\'ll send a verification link to it')
    })

    it('picks up an address verified elsewhere from the profile', async () => {
      useAuthStore.setState((state) => ({ user: state.user && { ...state.user, emailVerifiedAt: null } }))
      renderAccount()

      expect(screen.getByText('Not verified')).toBeInTheDocument()
      await waitFor(() => expect(screen.queryByText('Not verified')).not.toBeInTheDocument())
      expect(useAuthStore.getState().user?.emailVerifiedAt).toBe(VERIFIED_AT)
    })
  })
})
