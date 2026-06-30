import { describe, it, expect, vi } from 'vitest'
import { render, screen, fireEvent } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { SortSelect, type SortOption } from '@/shared/components/ui/sort-select'

const OPTIONS: SortOption[] = [
  { value: 'name_asc', label: 'Name A-Z' },
  { value: 'name_desc', label: 'Name Z-A' },
  { value: 'year_desc', label: 'Newest first' },
]

describe('SortSelect', () => {
  it('renders with the current sort label', () => {
    render(<SortSelect options={OPTIONS} value="name_asc" onChange={vi.fn()} />)
    expect(screen.getByText('Name A-Z')).toBeInTheDocument()
  })

  it('renders "Sort" fallback when value does not match', () => {
    render(<SortSelect options={OPTIONS} value="unknown" onChange={vi.fn()} />)
    expect(screen.getByText('Sort')).toBeInTheDocument()
  })

  it('calls onChange when an option is selected', async () => {
    const user = userEvent.setup()
    const onChange = vi.fn()
    render(<SortSelect options={OPTIONS} value="name_asc" onChange={onChange} />)

    await user.click(screen.getByText('Name A-Z'))
    await user.click(screen.getByText('Newest first'))

    expect(onChange).toHaveBeenCalledWith('year_desc')
  })
})
