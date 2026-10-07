import { cleanup, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { customInstance } from '@/shared/api-client/axios-instance'
import { VerifyEmailPage } from '../VerifyEmailPage'
import { httpError, renderAt } from './auth-page-test-utils'

vi.mock('@/shared/api-client/axios-instance', () => ({ customInstance: vi.fn() }))

interface MockUser {
  uuid: string
  email: string
  emailVerifiedAt?: string | null
}

interface MockAuthState {
  isAuthenticated: boolean
  user: MockUser | null
  updateUser: (updates: Partial<MockUser>) => void
}

const authState = vi.hoisted(() => ({
  isAuthenticated: false,
  user: null as { uuid: string; email: string; emailVerifiedAt?: string | null } | null,
  updateUser: vi.fn(),
}))

vi.mock('@/features/auth/stores/auth-store', () => {
  const snapshot = (): MockAuthState => ({
    isAuthenticated: authState.isAuthenticated,
    user: authState.user,
    updateUser: authState.updateUser,
  })
  const useAuthStore = Object.assign(
    vi.fn((selector: (state: MockAuthState) => unknown) => selector(snapshot())),
    { getState: snapshot },
  )

  return { useAuthStore }
})

const mockRequest = vi.mocked(customInstance)
const TOKEN = '7d1e4b9a2c6f0e3d8b5a1c9e4f7a2d6b7d1e4b9a2c6f0e3d8b5a1c9e4f7a2d6b'
const USER_UUID = '0192a7c4-6a4e-7c3b-9f1d-2b8e5c7a1d40'
const VERIFIED_AT = '2026-10-07T12:00:00+00:00'

function mount(entry = `/verify-email#token=${TOKEN}`) {
  return renderAt(entry, {
    '/verify-email': <VerifyEmailPage />,
    '/login': <p>Login page</p>,
  })
}

function verifyCalls() {
  return mockRequest.mock.calls.filter(([url]) => url === '/api/auth/email/verify')
}

function signIn() {
  authState.isAuthenticated = true
  authState.user = { uuid: USER_UUID, email: 'listener@baander.app', emailVerifiedAt: null }
}

describe('VerifyEmailPage', () => {
  beforeEach(() => {
    mockRequest.mockReset()
    authState.isAuthenticated = false
    authState.user = null
    authState.updateUser.mockReset()
  })

  afterEach(() => {
    cleanup()
  })

  it('redeems the token once and removes it from the address', async () => {
    mockRequest.mockResolvedValue({ data: { message: 'Email verified.' } })
    mount()

    expect(screen.getByTestId('location')).toHaveTextContent(/^\/verify-email$/)
    expect(await screen.findByText('Your email address is verified.')).toBeInTheDocument()
    expect(verifyCalls()).toHaveLength(1)
    expect(mockRequest).toHaveBeenCalledWith('/api/auth/email/verify', expect.objectContaining({
      method: 'POST',
      body: JSON.stringify({ token: TOKEN }),
    }))
  })

  it('sends a signed-out visitor to log in after verifying', async () => {
    mockRequest.mockResolvedValue({ data: { message: 'Email verified.' } })
    mount()

    expect(await screen.findByRole('link', { name: 'Log in' })).toHaveAttribute('href', '/login')
    expect(mockRequest).not.toHaveBeenCalledWith('/api/auth/me', expect.anything())
  })

  it('reloads the signed-in user so the address shows as verified', async () => {
    signIn()
    mockRequest.mockImplementation(async (url: string) => {
      if (url === '/api/auth/me') {
        return {
          data: {
            uuid: USER_UUID,
            publicId: 'usr_listener',
            name: 'Listener',
            email: 'listener@baander.app',
            emailVerifiedAt: VERIFIED_AT,
            createdAt: '2026-10-01T09:00:00+00:00',
            roles: ['ROLE_USER'],
          },
        }
      }

      return { data: { message: 'Email verified.' } }
    })
    mount()

    expect(await screen.findByRole('link', { name: 'Continue to Bånder' })).toHaveAttribute('href', '/')
    await waitFor(() => expect(authState.updateUser).toHaveBeenCalledWith({
      email: 'listener@baander.app',
      name: 'Listener',
      emailVerifiedAt: VERIFIED_AT,
    }))
  })

  it('explains an unusable link and tells a signed-out visitor to log in for a new one', async () => {
    mockRequest.mockRejectedValue(httpError(400, 'This verification link is invalid or has expired.'))
    mount()

    expect(await screen.findByRole('alert')).toHaveTextContent('This verification link is invalid or has expired.')
    expect(screen.getByText('Log in to request a new verification link.')).toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'Log in' })).toHaveAttribute('href', '/login')
    expect(screen.queryByRole('button', { name: 'Resend verification email' })).not.toBeInTheDocument()
  })

  it('lets a signed-in user request a new link after an unusable one', async () => {
    signIn()
    mockRequest.mockImplementation(async (url: string) => {
      if (url === '/api/auth/me/email/verification') {
        return { data: { message: 'If your email address still needs verification, a new link has been sent.' } }
      }

      throw httpError(400, 'This verification link is invalid or has expired.')
    })
    mount()

    const user = userEvent.setup()
    await user.click(await screen.findByRole('button', { name: 'Resend verification email' }))

    expect(await screen.findByRole('status')).toHaveTextContent('If your email address still needs verification, a new link is on its way.')
    expect(mockRequest).toHaveBeenCalledWith('/api/auth/me/email/verification', expect.objectContaining({ method: 'POST' }))
    expect(verifyCalls()).toHaveLength(1)
  })

  it('treats a link without a token as invalid and calls nothing', () => {
    mount('/verify-email')

    expect(screen.getByRole('alert')).toHaveTextContent('This verification link is invalid or has expired.')
    expect(mockRequest).not.toHaveBeenCalled()
  })

  it('waits for the Retry-After delay before allowing another attempt', async () => {
    mockRequest
      .mockRejectedValueOnce(httpError(429, 'Too many requests.', { 'retry-after': '1' }))
      .mockResolvedValueOnce({ data: { message: 'Email verified.' } })
    mount()

    expect(await screen.findByRole('alert')).toHaveTextContent('Too many attempts. Try again in 1 second.')
    const retry = screen.getByRole('button', { name: 'Try again' })
    expect(retry).toBeDisabled()

    await waitFor(() => expect(retry).toBeEnabled(), { timeout: 2500 })
    expect(screen.queryByRole('alert')).not.toBeInTheDocument()

    const user = userEvent.setup()
    await user.click(retry)

    expect(await screen.findByText('Your email address is verified.')).toBeInTheDocument()
    expect(verifyCalls()).toHaveLength(2)
  })

  it('offers a retry when the request fails for another reason', async () => {
    mockRequest
      .mockRejectedValueOnce(httpError(500, 'The server could not verify the address.'))
      .mockResolvedValueOnce({ data: { message: 'Email verified.' } })
    mount()

    expect(await screen.findByRole('alert')).toHaveTextContent('The server could not verify the address.')

    const user = userEvent.setup()
    await user.click(screen.getByRole('button', { name: 'Try again' }))

    expect(await screen.findByText('Your email address is verified.')).toBeInTheDocument()
  })
})
