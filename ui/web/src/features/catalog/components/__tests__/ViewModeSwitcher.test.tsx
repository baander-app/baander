import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, screen, fireEvent } from '@testing-library/react'

const mockSetViewMode = vi.fn()
const mockViewMode = 'grid'

vi.mock('../../stores/view-mode-store', () => ({
  useViewModeStore: (selector: (s: Record<string, unknown>) => unknown) =>
    selector({
      viewMode: mockViewMode,
      setViewMode: mockSetViewMode,
    }),
  VIEW_MODES: ['grid', 'list', 'columns', 'timeline', 'activity', 'discover'],
}))

vi.mock('@/shared/components/ui/tooltip', () => ({
  Tooltip: ({ children }: { children: React.ReactNode }) => <>{children}</>,
  TooltipTrigger: ({ children }: { children: React.ReactNode }) => <>{children}</>,
  TooltipContent: ({ children }: { children: React.ReactNode }) => <span>{children}</span>,
  TooltipProvider: ({ children }: { children: React.ReactNode }) => <>{children}</>,
}))

import { ViewModeSwitcher } from '../ViewModeSwitcher'

describe('ViewModeSwitcher', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  it('renders 6 view mode buttons', () => {
    render(<ViewModeSwitcher />)
    const buttons = screen.getAllByRole('button')
    expect(buttons).toHaveLength(6)
  })

  it('renders all view mode labels', () => {
    render(<ViewModeSwitcher />)
    expect(screen.getByLabelText('Grid view')).toBeInTheDocument()
    expect(screen.getByLabelText('List view')).toBeInTheDocument()
    expect(screen.getByLabelText('Columns view')).toBeInTheDocument()
    expect(screen.getByLabelText('Timeline view')).toBeInTheDocument()
    expect(screen.getByLabelText('Activity view')).toBeInTheDocument()
    expect(screen.getByLabelText('Discover view')).toBeInTheDocument()
  })

  it('calls setViewMode on button click', () => {
    render(<ViewModeSwitcher />)
    fireEvent.click(screen.getByLabelText('List view'))
    expect(mockSetViewMode).toHaveBeenCalledWith('list')
  })

  it('marks active mode as pressed', () => {
    render(<ViewModeSwitcher />)
    const gridButton = screen.getByLabelText('Grid view')
    expect(gridButton).toHaveAttribute('aria-pressed', 'true')
  })

  it('inactive modes are not pressed', () => {
    render(<ViewModeSwitcher />)
    const listButton = screen.getByLabelText('List view')
    expect(listButton).toHaveAttribute('aria-pressed', 'false')
  })

  it('switches view mode on keyboard 1-6', () => {
    render(<ViewModeSwitcher />)
    fireEvent.keyDown(document, { key: '3' })
    expect(mockSetViewMode).toHaveBeenCalledWith('columns')
  })

  it('does not switch on keyboard 0 or 7', () => {
    render(<ViewModeSwitcher />)
    fireEvent.keyDown(document, { key: '0' })
    fireEvent.keyDown(document, { key: '7' })
    expect(mockSetViewMode).not.toHaveBeenCalled()
  })

  it('renders toolbar with label', () => {
    render(<ViewModeSwitcher />)
    expect(screen.getByRole('toolbar', { name: 'View mode' })).toBeInTheDocument()
  })
})
