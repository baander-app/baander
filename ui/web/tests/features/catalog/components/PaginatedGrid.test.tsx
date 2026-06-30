import { describe, it, expect, vi } from 'vitest'
import { render, screen, fireEvent } from '@testing-library/react'
import { PaginatedGrid } from '@/features/catalog/components/PaginatedGrid'

describe('PaginatedGrid', () => {
  it('renders children in a grid', () => {
    render(
      <PaginatedGrid
        pagination={{ currentPage: 1, lastPage: 1, total: 3 }}
        onPageChange={vi.fn()}
      >
        <div data-testid="item-1">Item 1</div>
        <div data-testid="item-2">Item 2</div>
        <div data-testid="item-3">Item 3</div>
      </PaginatedGrid>,
    )

    expect(screen.getByTestId('item-1')).toBeInTheDocument()
    expect(screen.getByTestId('item-2')).toBeInTheDocument()
    expect(screen.getByTestId('item-3')).toBeInTheDocument()
  })

  it('hides pagination controls when there is only one page', () => {
    render(
      <PaginatedGrid
        pagination={{ currentPage: 1, lastPage: 1, total: 5 }}
        onPageChange={vi.fn()}
      >
        <div>Item</div>
      </PaginatedGrid>,
    )

    expect(screen.queryByRole('button', { name: 'Previous' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Next' })).not.toBeInTheDocument()
  })

  it('shows pagination controls when there are multiple pages', () => {
    render(
      <PaginatedGrid
        pagination={{ currentPage: 1, lastPage: 3, total: 15 }}
        onPageChange={vi.fn()}
      >
        <div>Item</div>
      </PaginatedGrid>,
    )

    expect(screen.getByRole('button', { name: 'Previous' })).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Next' })).toBeInTheDocument()
    expect(screen.getByText('1 / 3')).toBeInTheDocument()
  })

  it('disables Previous on first page and Next on last page', () => {
    render(
      <PaginatedGrid
        pagination={{ currentPage: 1, lastPage: 2, total: 10 }}
        onPageChange={vi.fn()}
      >
        <div>Item</div>
      </PaginatedGrid>,
    )

    expect(screen.getByRole('button', { name: 'Previous' })).toBeDisabled()
    expect(screen.getByRole('button', { name: 'Next' })).not.toBeDisabled()
  })

  it('calls onPageChange when clicking Next', () => {
    const onPageChange = vi.fn()
    render(
      <PaginatedGrid
        pagination={{ currentPage: 1, lastPage: 2, total: 10 }}
        onPageChange={onPageChange}
      >
        <div>Item</div>
      </PaginatedGrid>,
    )

    fireEvent.click(screen.getByRole('button', { name: 'Next' }))
    expect(onPageChange).toHaveBeenCalledWith(2)
  })

  it('calls onPageChange when clicking Previous', () => {
    const onPageChange = vi.fn()
    render(
      <PaginatedGrid
        pagination={{ currentPage: 2, lastPage: 3, total: 15 }}
        onPageChange={onPageChange}
      >
        <div>Item</div>
      </PaginatedGrid>,
    )

    fireEvent.click(screen.getByRole('button', { name: 'Previous' }))
    expect(onPageChange).toHaveBeenCalledWith(1)
  })
})
