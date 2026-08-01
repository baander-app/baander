import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter } from 'react-router-dom'
import { ThemeProvider as SCTypedThemeProvider } from 'styled-components'
import { resolveTheme } from '@/shared/theme/resolve-theme'
import { Sidebar } from '@/features/layout/components/Sidebar'
import { useSidebarStore } from '@/features/layout/stores/sidebar-store'
import { useMediaModeStore } from '@/features/layout/stores/media-mode-store'

const testTheme = resolveTheme('dark', 'violet')

// Mock sidebar config hook — schemas are used instead of flat items
vi.mock('@/features/layout/hooks/use-sidebar-config', () => ({
  useSidebarConfig: () => ({ isLoading: false }),
}))

function renderSidebar(initialPath = '/') {
  return render(
    <SCTypedThemeProvider theme={testTheme}>
      <MemoryRouter initialEntries={[initialPath]}>
        <Sidebar />
      </MemoryRouter>
    </SCTypedThemeProvider>,
  )
}

beforeEach(() => {
  localStorage.clear()
  useMediaModeStore.setState({ activeMedia: 'music' })
  useSidebarStore.setState({
    items: [],
    isLoading: false,
    error: null,
    isEditorOpen: false,
  })
})

describe('Sidebar', () => {
  it('renders the Bånder logo', () => {
    renderSidebar()
    expect(screen.getByText('Bånder')).toBeVisible()
  })

  it('renders the search input', () => {
    renderSidebar()
    expect(screen.getByPlaceholderText('Search...')).toBeVisible()
  })

  it('renders media type selector with all 6 types via data-testid', () => {
    renderSidebar()
    expect(screen.getByTestId('media-tab-music')).toBeVisible()
    expect(screen.getByTestId('media-tab-movies')).toBeVisible()
    expect(screen.getByTestId('media-tab-tv')).toBeVisible()
    expect(screen.getByTestId('media-tab-podcasts')).toBeVisible()
    expect(screen.getByTestId('media-tab-concerts')).toBeVisible()
    expect(screen.getByTestId('media-tab-ebooks')).toBeVisible()
  })

  it('marks music as selected by default', () => {
    renderSidebar()
    expect(screen.getByTestId('media-tab-music')).toHaveAttribute('aria-selected', 'true')
  })

  it('renders section headers from active schema', () => {
    renderSidebar()
    expect(screen.getByText('Quick Jump')).toBeVisible()
    expect(screen.getByText('Library')).toBeVisible()
  })

  it('renders nav items as links', () => {
    renderSidebar()
    const albumsLink = screen.getByRole('link', { name: /albums/i })
    expect(albumsLink).toBeVisible()
    expect(albumsLink).toHaveAttribute('href', '/music/albums')
  })

  it('renders pinned footer with global links', () => {
    renderSidebar()
    expect(screen.getByText('Settings')).toBeVisible()
    expect(screen.getByText('Equalizer')).toBeVisible()
  })

  it('switches media type on tab click', async () => {
    const user = userEvent.setup()
    renderSidebar()
    await user.click(screen.getByTestId('media-tab-movies'))
    expect(screen.getByTestId('media-tab-movies')).toHaveAttribute('aria-selected', 'true')
    // Sidebar should now show Movies sections
    expect(screen.getByText('Directors')).toBeVisible()
  })

  it('renders panel_action items as buttons', () => {
    // Set a schema with a panel_action item
    const schema = useSidebarStore.getState().getActiveSchema()
    const modifiedSchema = {
      ...schema,
      sections: [
        {
          ...schema.sections[0],
          items: [
            { id: 'test-panel', type: 'panel_action' as const, label: 'Open Queue', icon: 'list', config: { tab: 'queue' } },
          ],
        },
      ],
    }
    useSidebarStore.getState().setSchema('music', modifiedSchema)
    renderSidebar()
    expect(screen.getByRole('button', { name: /open queue/i })).toBeVisible()
  })
})
