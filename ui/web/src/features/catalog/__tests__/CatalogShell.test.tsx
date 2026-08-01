import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, screen } from '@testing-library/react'

const mockSetViewMode = vi.fn()

vi.mock('../stores/view-mode-store', () => ({
  useViewModeStore: (selector: (s: Record<string, unknown>) => unknown) =>
    selector({
      viewMode: 'grid',
      setViewMode: mockSetViewMode,
    }),
  VIEW_MODES: ['grid', 'list', 'columns', 'timeline', 'activity', 'discover'],
}))

vi.mock('../components/ViewModeSwitcher', () => ({
  ViewModeSwitcher: () => <div data-testid="view-mode-switcher" />,
}))

vi.mock('../views/GridView', () => ({
  GridView: () => <div data-testid="grid-view" />,
}))

vi.mock('../views/ListView', () => ({
  ListView: () => <div data-testid="list-view" />,
}))

vi.mock('../views/ColumnBrowserView', () => ({
  ColumnBrowserView: () => <div data-testid="columns-view" />,
}))

vi.mock('../views/TimelineView', () => ({
  TimelineView: () => <div data-testid="timeline-view" />,
}))

vi.mock('../views/ActivityView', () => ({
  ActivityView: () => <div data-testid="activity-view" />,
}))

vi.mock('../views/DiscoverView', () => ({
  DiscoverView: () => <div data-testid="discover-view" />,
}))

import { CatalogShell } from '../CatalogShell'

describe('CatalogShell', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  it('renders the view mode switcher', () => {
    render(<CatalogShell />)
    expect(screen.getByTestId('view-mode-switcher')).toBeInTheDocument()
  })

  it('renders the grid view by default', () => {
    render(<CatalogShell />)
    expect(screen.getByTestId('grid-view')).toBeInTheDocument()
  })

  it('has a header toolbar area', () => {
    render(<CatalogShell />)
    const header = screen.getByTestId('view-mode-switcher').parentElement
    // styled-components generates hashed class names; verify the element exists with border styling
    expect(header).toBeTruthy()
    expect(getComputedStyle(header!).borderBottom).not.toBe('')
  })
})
