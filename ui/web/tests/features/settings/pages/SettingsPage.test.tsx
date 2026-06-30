import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, screen } from '@testing-library/react'
import { BrowserRouter } from 'react-router-dom'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { ThemeProvider as SCTypedThemeProvider } from 'styled-components'
import { resolveTheme } from '@/shared/theme/resolve-theme'

const testTheme = resolveTheme('dark', 'violet')

const mockSetNormalizationEnabled = vi.fn()
const mockSetTargetLufs = vi.fn()

vi.mock('@/features/equalizer/stores/eq-store', () => ({
  useEqStore: (selector: (s: unknown) => unknown) =>
    selector({
      normalizationEnabled: false,
      targetLufs: -14,
      setNormalizationEnabled: mockSetNormalizationEnabled,
      setTargetLufs: mockSetTargetLufs,
    }),
}))

vi.mock('@/shared/api-client/axios-instance', () => ({
  AXIOS_INSTANCE: {
    get: vi.fn().mockResolvedValue({ data: { data: [] } }),
  },
  customInstance: vi.fn().mockRejectedValue(new Error('API not available in tests')),
}))

import { SettingsPage } from '@/features/settings/pages/SettingsPage'

// Mock matchMedia for useThemeMood
beforeEach(() => {
  Object.defineProperty(window, 'matchMedia', {
    writable: true,
    value: vi.fn().mockImplementation((query: string) => ({
      matches: false,
      media: query,
      onchange: null,
      addListener: vi.fn(),
      removeListener: vi.fn(),
      addEventListener: vi.fn(),
      removeEventListener: vi.fn(),
      dispatchEvent: vi.fn(),
    })),
  });
});

function renderWithProviders(ui: React.ReactElement) {
  const queryClient = new QueryClient()
  return render(
    <QueryClientProvider client={queryClient}>
      <SCTypedThemeProvider theme={testTheme}>
        <BrowserRouter>{ui}</BrowserRouter>
      </SCTypedThemeProvider>
    </QueryClientProvider>,
  )
}

describe('SettingsPage', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    window.localStorage.clear()
  })

  it('renders all sections', () => {
    renderWithProviders(<SettingsPage />)

    expect(screen.getByText('Settings')).toBeInTheDocument()
    expect(screen.getByText('Audio')).toBeInTheDocument()
    expect(screen.getByText('Security')).toBeInTheDocument()
    expect(screen.getByText('About')).toBeInTheDocument()
  })

  it('renders volume normalization toggle', () => {
    renderWithProviders(<SettingsPage />)

    expect(screen.getByText('Volume Normalization')).toBeInTheDocument()
    expect(screen.getByRole('switch', { name: 'Volume normalization' })).toBeInTheDocument()
  })

  it('renders LUFS target selector', () => {
    renderWithProviders(<SettingsPage />)

    expect(screen.getByText('LUFS Target')).toBeInTheDocument()
    expect(screen.getByText('-14 LUFS (Spotify)')).toBeInTheDocument()
  })

  it('renders equalizer link', () => {
    renderWithProviders(<SettingsPage />)

    expect(screen.getByText('Equalizer')).toBeInTheDocument()
    expect(screen.getByText('Open EQ')).toBeInTheDocument()
  })

  it('renders passkey management section', () => {
    renderWithProviders(<SettingsPage />)

    expect(screen.getByText('Passkeys')).toBeInTheDocument()
    expect(screen.getByText('Passwordless authentication for your account')).toBeInTheDocument()
  })

  it('renders about section', () => {
    renderWithProviders(<SettingsPage />)

    expect(screen.getByText('Bånder')).toBeInTheDocument()
  })
})
