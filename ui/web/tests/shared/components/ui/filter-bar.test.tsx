import { describe, it, expect, vi } from 'vitest'
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { FilterBar, type FilterOption } from '@/shared/components/ui/filter-bar'

const FILTERS: FilterOption[] = [
  { value: 'rock', label: 'Rock' },
  { value: 'jazz', label: 'Jazz' },
  { value: 'electronic', label: 'Electronic' },
]

describe('FilterBar', () => {
  it('renders all filter buttons', () => {
    render(
      <FilterBar
        filters={FILTERS}
        selected={[]}
        onToggle={vi.fn()}
        onClear={vi.fn()}
      />,
    )

    expect(screen.getByText('Rock')).toBeInTheDocument()
    expect(screen.getByText('Jazz')).toBeInTheDocument()
    expect(screen.getByText('Electronic')).toBeInTheDocument()
  })

  it('renders nothing when filters array is empty', () => {
    const { container } = render(
      <FilterBar
        filters={[]}
        selected={[]}
        onToggle={vi.fn()}
        onClear={vi.fn()}
      />,
    )

    expect(container.firstChild).toBeNull()
  })

  it('calls onToggle when a filter is clicked', async () => {
    const user = userEvent.setup()
    const onToggle = vi.fn()
    render(
      <FilterBar
        filters={FILTERS}
        selected={[]}
        onToggle={onToggle}
        onClear={vi.fn()}
      />,
    )

    await user.click(screen.getByText('Rock'))
    expect(onToggle).toHaveBeenCalledWith('rock')
  })

  it('shows Clear button when filters are selected', () => {
    render(
      <FilterBar
        filters={FILTERS}
        selected={['rock']}
        onToggle={vi.fn()}
        onClear={vi.fn()}
      />,
    )

    expect(screen.getByText('Clear')).toBeInTheDocument()
  })

  it('hides Clear button when no filters are selected', () => {
    render(
      <FilterBar
        filters={FILTERS}
        selected={[]}
        onToggle={vi.fn()}
        onClear={vi.fn()}
      />,
    )

    expect(screen.queryByText('Clear')).not.toBeInTheDocument()
  })

  it('calls onClear when Clear is clicked', async () => {
    const user = userEvent.setup()
    const onClear = vi.fn()
    render(
      <FilterBar
        filters={FILTERS}
        selected={['rock']}
        onToggle={vi.fn()}
        onClear={onClear}
      />,
    )

    await user.click(screen.getByText('Clear'))
    expect(onClear).toHaveBeenCalled()
  })
})
