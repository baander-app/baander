import { describe, it, expect, vi } from 'vitest'
import { render, screen } from '@testing-library/react'
import { BrowserRouter } from 'react-router-dom'
import { ThemeProvider as SCTypedThemeProvider } from 'styled-components'
import { resolveTheme } from '@/shared/theme/resolve-theme'
import { LoginForm } from '@/features/auth/components/LoginForm'

const testTheme = resolveTheme('dark', 'violet')

// Mock auth store
vi.mock('@/features/auth/stores/auth-store', () => ({
  useAuthStore: vi.fn((selector) =>
    selector({
      login: vi.fn(),
      isLoading: false,
    }),
  ),
}))

function renderWithProviders(ui: React.ReactElement) {
  return render(
    <SCTypedThemeProvider theme={testTheme}>
      <BrowserRouter>{ui}</BrowserRouter>
    </SCTypedThemeProvider>,
  )
}

describe('LoginForm', () => {
  it('renders email and password fields', () => {
    renderWithProviders(<LoginForm />)

    expect(screen.getByLabelText('Email')).toBeInTheDocument()
    expect(screen.getByLabelText('Password')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Log in' })).toBeInTheDocument()
  })
})
