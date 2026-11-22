import { act, cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { AxiosError, AxiosHeaders } from 'axios'
import { createMemoryRouter, Navigate, RouterProvider } from 'react-router-dom'
import { ThemeProvider } from 'styled-components'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { customInstance } from '@/shared/api-client/axios-instance'
import { resolveTheme } from '@/shared/theme/resolve-theme'
import { useAuthStore } from '../../stores/auth-store'
import { LoginPage } from '../LoginPage'
import { VerifyEmailPage } from '../VerifyEmailPage'

vi.mock('../../stores/auth-store', async () => {
  const { create } = await import('zustand')
  return { useAuthStore: create(() => ({ isAuthenticated: false, isLoading: false, login: vi.fn() })) }
})

vi.mock('@/shared/api-client/axios-instance', () => ({ customInstance: vi.fn() }))

const login = vi.fn<ReturnType<typeof useAuthStore.getState>['login']>()
const routers: ReturnType<typeof createMemoryRouter>[] = []

beforeEach(() => {
  login.mockReset()
  useAuthStore.setState({ isAuthenticated: false, isLoading: false, login })
})
afterEach(() => {
  cleanup()
  routers.splice(0).forEach(router => router.dispose())
})

function mount(state?: unknown) {
  const router = createMemoryRouter([
    { path: '/login', element: <LoginPage /> },
    { path: '/', element: <Navigate to="/music" replace /> },
    { path: '/music', element: <p>Home destination</p> },
    { path: '/music/albums', element: <p>Albums destination</p> },
    { path: '/device', element: <p>Device destination</p> },
  ], { initialEntries: [{ pathname: '/login', state }] })
  routers.push(router)
  render(<ThemeProvider theme={resolveTheme('dark', 'violet')}><RouterProvider router={router} /></ThemeProvider>)
  return router
}

async function submit() {
  const user = userEvent.setup()
  await user.type(screen.getByLabelText('Email'), 'login@baander.app')
  await user.type(screen.getByLabelText('Password'), 'test-password')
  await user.click(screen.getByRole('button', { name: 'Log in' }))
}

function pendingPersistence() {
  let resolve!: () => void
  const promise = new Promise<void>(done => { resolve = done })
  login.mockReturnValue(promise)
  return resolve
}

function refusal(code: string, message: string, status = 400) {
  return new AxiosError('Refused', undefined, undefined, undefined, {
    data: { error: { code, message } }, status, statusText: 'Refused', headers: {},
    config: { headers: new AxiosHeaders() },
  })
}

describe('login navigation ownership', () => {
  it('keeps the home destination when persistence finishes after authentication exposed it', async () => {
    const finishPersistence = pendingPersistence()
    const router = mount()
    await submit()
    act(() => { useAuthStore.setState({ isAuthenticated: true }) })
    await screen.findByText('Home destination')
    await act(async () => { finishPersistence() })
    await waitFor(() => expect(router.state.location.pathname).toBe('/music'))
    expect(screen.getByText('Home destination')).toBeInTheDocument()
  })

  it.each([
    ['default destination', undefined, 'Home destination'],
    ['guarded destination', { from: { pathname: '/device', search: '?user_code=BCDF-GHJK', hash: '#top' } }, 'Device destination'],
  ])('does not overwrite later navigation from the %s when persistence settles', async (_name, state, destination) => {
    const finishPersistence = pendingPersistence()
    const router = mount(state)
    await submit()
    act(() => { useAuthStore.setState({ isAuthenticated: true }) })
    await screen.findByText(String(destination))
    if (state) {
      expect(router.state.location.search).toBe('?user_code=BCDF-GHJK')
      expect(router.state.location.hash).toBe('#top')
    }
    await act(() => router.navigate('/music/albums'))
    await screen.findByText('Albums destination')
    await act(async () => { finishPersistence() })
    expect(router.state.location.pathname).toBe('/music/albums')
    expect(screen.getByText('Albums destination')).toBeInTheDocument()
  })

  it('keeps the form available for a required second factor and redirects only after authentication', async () => {
    login.mockRejectedValueOnce(refusal('AUTH_TOTP_REQUIRED', 'Enter your code.'))
    const router = mount({ from: { pathname: '/device', search: '?user_code=BCDF-GHJK', hash: '' } })
    await submit()
    const code = await screen.findByLabelText('Authentication code')
    expect(router.state.location.pathname).toBe('/login')
    login.mockImplementationOnce(async () => { useAuthStore.setState({ isAuthenticated: true }) })
    await userEvent.type(code, '123456')
    await userEvent.click(screen.getByRole('button', { name: 'Log in' }))
    await screen.findByText('Device destination')
    expect(login).toHaveBeenLastCalledWith('login@baander.app', 'test-password', '123456', '')
    expect(router.state.location.search).toBe('?user_code=BCDF-GHJK')
  })

  it('focuses the code field when the account asks for a second factor', async () => {
    login.mockRejectedValueOnce(refusal('AUTH_TOTP_REQUIRED', 'Enter your code.', 401))
    mount()
    await submit()
    const code = await screen.findByLabelText('Authentication code')
    expect(code).toHaveFocus()
    expect(screen.queryByText('Enter your code.')).not.toBeInTheDocument()
  })

  it('clears a rejected or already used code and says to wait for the next one', async () => {
    login.mockRejectedValueOnce(refusal('AUTH_TOTP_REQUIRED', 'Enter your code.', 401))
    mount()
    await submit()
    const code = await screen.findByLabelText('Authentication code')
    login.mockRejectedValueOnce(refusal('AUTH_INVALID_CREDENTIALS', 'Invalid credentials.', 401))
    await userEvent.type(code, '123456')
    await userEvent.click(screen.getByRole('button', { name: 'Log in' }))
    await screen.findByText('That code was not accepted. Wait for the next code and try again.')
    expect(screen.getByLabelText('Authentication code')).toHaveValue('')
    expect(screen.queryByText('Invalid credentials.')).not.toBeInTheDocument()
  })

  it('answers a throttled login with one message and no account detail', async () => {
    login.mockRejectedValueOnce(refusal('429', 'Too many requests for login@baander.app.', 429))
    mount()
    await submit()
    await screen.findByText('Too many sign-in attempts. Wait a minute and try again.')
    expect(screen.queryByText(/login@baander\.app/)).not.toBeInTheDocument()
  })

  it('preserves credentials and remains on login after a rejected password', async () => {
    login.mockRejectedValueOnce(refusal('AUTH_INVALID_CREDENTIALS', 'Wrong credentials.'))
    const router = mount()
    await submit()
    await screen.findByText('Wrong credentials.')
    expect(router.state.location.pathname).toBe('/login')
    expect(screen.getByLabelText('Email')).toHaveValue('login@baander.app')
    expect(screen.getByLabelText('Password')).toHaveValue('test-password')
  })

  it('does not authenticate a honeypot submission', async () => {
    mount()
    fireEvent.change(document.getElementById('username')!, { target: { value: 'bot' } })
    await submit()
    expect(login).not.toHaveBeenCalled()
  })
})

describe('returning to a verification link', () => {
  const token = '7d1e4b9a2c6f0e3d8b5a1c9e4f7a2d6b7d1e4b9a2c6f0e3d8b5a1c9e4f7a2d6b'

  afterEach(() => {
    vi.mocked(customInstance).mockReset()
  })

  it('redeems the link once a signed-out visitor has signed in', async () => {
    useAuthStore.setState({ user: null })
    vi.mocked(customInstance).mockImplementation(async (url: string) => url === '/api/auth/email/verify'
      ? { data: { message: 'Email verified.', emailChanged: false } }
      : { data: {} })
    login.mockImplementationOnce(async () => {
      useAuthStore.setState({ isAuthenticated: true })
    })
    const router = createMemoryRouter([
      { path: '/login', element: <LoginPage /> },
      { path: '/verify-email', element: <VerifyEmailPage /> },
    ], { initialEntries: [`/verify-email#token=${token}`] })
    routers.push(router)
    render(<ThemeProvider theme={resolveTheme('dark', 'violet')}><RouterProvider router={router} /></ThemeProvider>)

    expect(customInstance).not.toHaveBeenCalled()
    await userEvent.click(await screen.findByRole('link', { name: 'Log in' }))
    await submit()

    expect(await screen.findByText('Your email address is verified.')).toBeInTheDocument()
    expect(vi.mocked(customInstance).mock.calls.filter(([url]) => url === '/api/auth/email/verify')).toEqual([
      ['/api/auth/email/verify', expect.objectContaining({ body: JSON.stringify({ token }) })],
    ])
    expect(router.state.location.pathname).toBe('/verify-email')
    expect(router.state.location.hash).toBe('')
  })
})
