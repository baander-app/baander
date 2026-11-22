import { cleanup, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { useLocation } from 'react-router-dom'
import { customInstance } from '@/shared/api-client/axios-instance'
import { returnPathFrom } from '../../lib/return-to'
import { VerifyEmailPage } from '../VerifyEmailPage'
import { httpError, renderAt } from './auth-page-test-utils'

vi.mock('@/shared/api-client/axios-instance', () => ({ customInstance: vi.fn() }))

interface MockUser {
  publicId: string
  email: string
  emailVerifiedAt?: string | null
}

interface MockAuthState {
  isAuthenticated: boolean
  user: MockUser | null
  updateUser: (updates: Partial<MockUser>) => void
  logout: () => Promise<void>
}

const authState = vi.hoisted(() => ({
  isAuthenticated: false,
  user: null as { publicId: string; email: string; emailVerifiedAt?: string | null } | null,
  updateUser: vi.fn(),
  logout: vi.fn<() => Promise<void>>(),
}))

vi.mock('@/features/auth/stores/auth-store', () => {
  const snapshot = (): MockAuthState => ({
    isAuthenticated: authState.isAuthenticated,
    user: authState.user,
    updateUser: authState.updateUser,
    logout: authState.logout,
  })
  const useAuthStore = Object.assign(
    vi.fn((selector: (state: MockAuthState) => unknown) => selector(snapshot())),
    { getState: snapshot },
  )

  return { useAuthStore }
})

const mockRequest = vi.mocked(customInstance)
const TOKEN = '7d1e4b9a2c6f0e3d8b5a1c9e4f7a2d6b7d1e4b9a2c6f0e3d8b5a1c9e4f7a2d6b'
const USER_PUBLIC_ID = 'usr_listener'
const VERIFIED_AT = '2026-10-07T12:00:00+00:00'
const OTHER_ACCOUNT_MESSAGE = 'This verification link was sent for another account. Sign out, then sign in to that account to use it.'

/** Stands in for the login page and shows where signing in would return to. */
function LoginProbe() {
  const location = useLocation()

  return <p data-testid="return-to">{returnPathFrom(location.state)}</p>
}

function mount(entry = `/verify-email#token=${TOKEN}`) {
  return renderAt(entry, {
    '/verify-email': <VerifyEmailPage />,
    '/login': <LoginProbe />,
  })
}

function verifyCalls() {
  return mockRequest.mock.calls.filter(([url]) => url === '/api/auth/email/verify')
}

function signIn() {
  authState.isAuthenticated = true
  authState.user = { publicId: USER_PUBLIC_ID, email: 'listener@baander.app', emailVerifiedAt: null }
}

function verifiedResponse(emailChanged = false) {
  return { data: { message: 'Email verified.', emailChanged } }
}

/** Answers the profile reload after a verification; every other request gets `answer`. */
function answerVerification(answer: () => Promise<unknown>) {
  mockRequest.mockImplementation(async (url: string) => {
    if (url === '/api/auth/me') {
      return {
        data: {
          publicId: USER_PUBLIC_ID,
          name: 'Listener',
          email: 'listener@baander.app',
          emailVerifiedAt: VERIFIED_AT,
          createdAt: '2026-10-01T09:00:00+00:00',
          roles: ['ROLE_USER'],
        },
      }
    }

    return answer()
  })
}

async function followLogInLink() {
  const user = userEvent.setup()
  await user.click(screen.getByRole('link', { name: 'Log in' }))

  return screen.findByTestId('return-to')
}

describe('VerifyEmailPage', () => {
  beforeEach(() => {
    mockRequest.mockReset()
    authState.isAuthenticated = false
    authState.user = null
    authState.updateUser.mockReset()
    authState.logout.mockReset()
  })

  afterEach(() => {
    cleanup()
  })

  it('redeems the token once, in the signed-in session, and removes it from the address', async () => {
    signIn()
    answerVerification(async () => verifiedResponse())
    mount()

    expect(screen.getByTestId('location')).toHaveTextContent(/^\/verify-email$/)
    expect(await screen.findByText('Your email address is verified.')).toBeInTheDocument()
    expect(verifyCalls()).toHaveLength(1)
    expect(mockRequest).toHaveBeenCalledWith('/api/auth/email/verify', expect.objectContaining({
      method: 'POST',
      body: JSON.stringify({ token: TOKEN }),
      keepPageOnSignOut: true,
    }))
  })

  it('asks a signed-out visitor to sign in without calling the server, and returns to the link afterwards', async () => {
    mount()

    expect(screen.getByText('Sign in to the account this link was sent for to verify the address.')).toBeInTheDocument()
    expect(mockRequest).not.toHaveBeenCalled()
    expect(screen.getByTestId('location')).toHaveTextContent(/^\/verify-email$/)

    expect(await followLogInLink()).toHaveTextContent(`/verify-email#token=${TOKEN}`)
  })

  it('reloads the signed-in user so the address shows as verified', async () => {
    signIn()
    answerVerification(async () => verifiedResponse())
    mount()

    expect(await screen.findByRole('link', { name: 'Continue to Bånder' })).toHaveAttribute('href', '/')
    await waitFor(() => expect(authState.updateUser).toHaveBeenCalledWith({
      email: 'listener@baander.app',
      name: 'Listener',
      emailVerifiedAt: VERIFIED_AT,
      pendingEmail: null,
    }))
  })

  it('says the other sessions were signed out when the link confirmed an email change', async () => {
    signIn()
    answerVerification(async () => verifiedResponse(true))
    mount()

    expect(await screen.findByRole('status')).toHaveTextContent(
      'Your account now uses this email address, and your other sessions were signed out.',
    )
  })

  it('resolves a session that ended during the request to the sign-in state and keeps the token', async () => {
    signIn()
    answerVerification(async () => {
      // The shared client signs the tab out when the refresh is rejected, and the request fails.
      authState.isAuthenticated = false
      authState.user = null
      throw httpError(401, 'Unauthorized.')
    })
    mount()

    expect(await screen.findByText('Sign in to the account this link was sent for to verify the address.')).toBeInTheDocument()
    expect(screen.getByTestId('location')).toHaveTextContent(/^\/verify-email$/)
    expect(verifyCalls()).toHaveLength(1)

    expect(await followLogInLink()).toHaveTextContent(`/verify-email#token=${TOKEN}`)
  })

  it('names the signed-in account for another account\'s link and signs out only when asked', async () => {
    signIn()
    answerVerification(async () => {
      throw httpError(403, OTHER_ACCOUNT_MESSAGE, {}, { reason: 'verification_link_other_account' })
    })
    authState.logout.mockImplementation(async () => {
      authState.isAuthenticated = false
      authState.user = null
    })
    mount()

    expect(await screen.findByRole('alert')).toHaveTextContent(
      'You are signed in as listener@baander.app, but this link was sent for another account.',
    )
    expect(authState.logout).not.toHaveBeenCalled()

    const user = userEvent.setup()
    await user.click(screen.getByRole('button', { name: 'Sign out and continue' }))

    expect(authState.logout).toHaveBeenCalledOnce()
    expect(await screen.findByText('Sign in to the account this link was sent for to verify the address.')).toBeInTheDocument()
    expect(verifyCalls()).toHaveLength(1)
    expect(await followLogInLink()).toHaveTextContent(`/verify-email#token=${TOKEN}`)
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

  it('treats a link without a token as invalid, calls nothing, and tells a signed-out visitor to log in for a new one', () => {
    mount('/verify-email')

    expect(screen.getByRole('alert')).toHaveTextContent('This verification link is invalid or has expired.')
    expect(screen.getByText('Log in to request a new verification link.')).toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'Log in' })).toHaveAttribute('href', '/login')
    expect(mockRequest).not.toHaveBeenCalled()
  })

  it('waits for the Retry-After delay before allowing another attempt', async () => {
    signIn()
    mockRequest
      .mockRejectedValueOnce(httpError(429, 'Too many requests.', { 'retry-after': '1' }))
      .mockResolvedValueOnce(verifiedResponse())
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
    signIn()
    mockRequest
      .mockRejectedValueOnce(httpError(500, 'The server could not verify the address.'))
      .mockResolvedValueOnce(verifiedResponse())
    mount()

    expect(await screen.findByRole('alert')).toHaveTextContent('The server could not verify the address.')

    const user = userEvent.setup()
    await user.click(screen.getByRole('button', { name: 'Try again' }))

    expect(await screen.findByText('Your email address is verified.')).toBeInTheDocument()
  })
})
