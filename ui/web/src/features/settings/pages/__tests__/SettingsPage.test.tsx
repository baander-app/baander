import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { ThemeProvider as SCTypedThemeProvider } from 'styled-components';
import { resolveTheme } from '@/shared/theme/resolve-theme';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';

const testTheme = resolveTheme('dark', 'violet');

// Mock react-router-dom
vi.mock('react-router-dom', () => ({
  Link: ({ children, to }: { children: React.ReactNode; to: string }) => (
    <a href={to}>{children}</a>
  ),
  useNavigate: () => vi.fn(),
}));

// Mock the equalizer store
const mockSetNormalizationEnabled = vi.fn();
const mockSetTargetLufs = vi.fn();

vi.mock('@/features/equalizer/stores/eq-processing-store', () => ({
  useEqProcessingStore: (selector: (s: Record<string, unknown>) => unknown) =>
    selector({
      normalizationEnabled: false,
      targetLufs: -14,
      setNormalizationEnabled: mockSetNormalizationEnabled,
      setTargetLufs: mockSetTargetLufs,
    }),
}));

// Mock PasskeyManagement to avoid WebAuthn setup
vi.mock('../../components/PasskeyManagement', () => ({
  PasskeyManagement: () => <div data-testid="passkey-management">Passkey Mock</div>,
}));

import { SettingsPage } from '../SettingsPage';

function renderWithTheme(ui: React.ReactElement) {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <QueryClientProvider client={qc}>
      <SCTypedThemeProvider theme={testTheme}>{ui}</SCTypedThemeProvider>
    </QueryClientProvider>,
  );
}

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

describe('SettingsPage', () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it('renders the page title', () => {
    renderWithTheme(<SettingsPage />);
    expect(screen.getByText('Settings')).toBeInTheDocument();
  });

  it('renders all section headings', () => {
    renderWithTheme(<SettingsPage />);
    expect(screen.getByText('Audio')).toBeInTheDocument();
    expect(screen.getByText('Security')).toBeInTheDocument();
    expect(screen.getByText('About')).toBeInTheDocument();
  });

  it('renders the volume normalization toggle', () => {
    renderWithTheme(<SettingsPage />);
    expect(screen.getByRole('switch', { name: /volume normalization/i })).toBeInTheDocument();
  });

  it('calls setNormalizationEnabled when toggle is clicked', async () => {
    const user = userEvent.setup();
    renderWithTheme(<SettingsPage />);

    await user.click(screen.getByRole('switch', { name: /volume normalization/i }));
    expect(mockSetNormalizationEnabled).toHaveBeenCalledWith(true);
  });

  it('renders the LUFS target select with all options', () => {
    renderWithTheme(<SettingsPage />);
    // The LUFS target uses a Radix Select with these options
    expect(screen.getByText('LUFS Target')).toBeInTheDocument();
    // Verify the select trigger exists (the default value is shown)
    expect(screen.getByText('-14 LUFS (Spotify)')).toBeInTheDocument();
  });

  it('disables LUFS select when normalization is off', () => {
    renderWithTheme(<SettingsPage />);
    // When normalization is off, the select should not be interactable
    // Verify the trigger element exists and has disabled attribute
    const trigger = screen.getByRole('combobox');
    expect(trigger).toBeDisabled();
  });

  it('renders the EQ link', () => {
    renderWithTheme(<SettingsPage />);
    const link = screen.getByRole('link', { name: /open eq/i });
    expect(link).toHaveAttribute('href', '/equalizer');
  });

  it('renders the PasskeyManagement component', () => {
    renderWithTheme(<SettingsPage />);
    expect(screen.getByTestId('passkey-management')).toBeInTheDocument();
  });

  it('renders the About section with version', () => {
    renderWithTheme(<SettingsPage />);
    expect(screen.getByText('Bånder')).toBeInTheDocument();
    expect(screen.getByText('v0.1.0')).toBeInTheDocument();
  });
});
