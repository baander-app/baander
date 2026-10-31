import { act, cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { AxiosError, AxiosHeaders } from 'axios'
import { createMemoryRouter, Navigate, RouterProvider } from 'react-router-dom'
import { ThemeProvider } from 'styled-components'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { resolveTheme } from '@/shared/theme/resolve-theme'
import { useAuthStore } from '../../stores/auth-store'
import { LoginPage } from '../LoginPage'

vi.mock('../../stores/auth-store', async () => {
  const { create } = await import('zustand')
  return { useAuthStore: create(() => ({ isAuthenticated: false, isLoading: false, login: vi.fn() })) }
})

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

function refusal(code: string, message: string) {
  return new AxiosError('Refused', undefined, undefined, undefined, {
    data: { error: { code, message } }, status: 400, statusText: 'Bad Request', headers: {},
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
