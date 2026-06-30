import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, screen, fireEvent } from '@testing-library/react'
import { ListHeader, type SortState } from '../ListHeader'
import { useListColumnStore } from '../../stores/list-column-store'

import React from 'react'

// Mock @dnd-kit to avoid complex drag setup in jsdom
vi.mock('@dnd-kit/sortable', () => ({
  SortableContext: ({ children }: any) => children,
  useSortable: () => ({
    attributes: {},
    listeners: {},
    setNodeRef: vi.fn(),
    transform: null,
    transition: null,
    isDragging: false,
  }),
  horizontalListSortingStrategy: () => {},
  arrayMove: (arr: any[], from: number, to: number) => {
    const result = [...arr]
    const [removed] = result.splice(from, 1)
    result.splice(to, 0, removed)
    return result
  },
  sortableKeyboardCoordinates: () => {},
}))

vi.mock('@dnd-kit/core', () => ({
  DndContext: ({ children }: any) => children,
  closestCenter: () => {},
  PointerSensor: class {},
  KeyboardSensor: class {},
  useSensor: () => ({}),
  useSensors: () => [],
}))

vi.mock('@dnd-kit/utilities', () => ({
  CSS: { Transform: { toString: () => null } },
}))

describe('ListHeader', () => {
  const defaultSort: SortState = { field: null, direction: null }

  beforeEach(() => {
    useListColumnStore.setState({
      visibleColumns: ['#', 'title', 'artist', 'album', 'year', 'duration'],
      columnOrder: ['#', 'title', 'artist', 'album', 'year', 'genre', 'duration', 'bitrate', 'format', 'createdAt'],
    })
  })

  it('renders visible column headers', () => {
    render(<ListHeader sort={defaultSort} onSortChange={vi.fn()} />)

    expect(screen.getByText('#')).toBeInTheDocument()
    expect(screen.getByText('Title')).toBeInTheDocument()
    expect(screen.getByText('Artist')).toBeInTheDocument()
    expect(screen.getByText('Album')).toBeInTheDocument()
    expect(screen.getByText('Year')).toBeInTheDocument()
    expect(screen.getByText('Duration')).toBeInTheDocument()
  })

  it('does not render hidden columns', () => {
    render(<ListHeader sort={defaultSort} onSortChange={vi.fn()} />)

    expect(screen.queryByText('Genre')).not.toBeInTheDocument()
    expect(screen.queryByText('Bitrate')).not.toBeInTheDocument()
  })

  it('clicking column header sorts ascending', () => {
    const onSortChange = vi.fn()
    render(<ListHeader sort={defaultSort} onSortChange={onSortChange} />)

    fireEvent.click(screen.getByText('Title'))
    expect(onSortChange).toHaveBeenCalledWith({ field: 'title', direction: 'asc' })
  })

  it('clicking again sorts descending', () => {
    const onSortChange = vi.fn()
    render(
      <ListHeader
        sort={{ field: 'title', direction: 'asc' }}
        onSortChange={onSortChange}
      />,
    )

    fireEvent.click(screen.getByText('Title'))
    expect(onSortChange).toHaveBeenCalledWith({ field: 'title', direction: 'desc' })
  })

  it('clicking again removes sort', () => {
    const onSortChange = vi.fn()
    render(
      <ListHeader
        sort={{ field: 'title', direction: 'desc' }}
        onSortChange={onSortChange}
      />,
    )

    fireEvent.click(screen.getByText('Title'))
    expect(onSortChange).toHaveBeenCalledWith({ field: null, direction: null })
  })

  it('shows sort indicator ▲ when ascending', () => {
    render(
      <ListHeader
        sort={{ field: 'title', direction: 'asc' }}
        onSortChange={vi.fn()}
      />,
    )

    expect(screen.getByText('▲')).toBeInTheDocument()
  })

  it('shows sort indicator ▼ when descending', () => {
    render(
      <ListHeader
        sort={{ field: 'title', direction: 'desc' }}
        onSortChange={vi.fn()}
      />,
    )

    expect(screen.getByText('▼')).toBeInTheDocument()
  })

  it('shows column visibility menu on right-click', () => {
    render(<ListHeader sort={defaultSort} onSortChange={vi.fn()} />)

    // Right-click on the header opens a custom context menu with checkboxes
    fireEvent.contextMenu(screen.getByText('Title').closest('div')!)
    // The menu should now show all available columns as checkboxes
    expect(screen.getByRole('checkbox', { name: /title/i })).toBeInTheDocument()
  })

  it('column visibility menu toggles columns', () => {
    render(<ListHeader sort={defaultSort} onSortChange={vi.fn()} />)

    // Open the menu
    fireEvent.contextMenu(screen.getByText('Title').closest('div')!)

    // Toggle genre on by clicking its checkbox
    const genreCheckbox = screen.getByRole('checkbox', { name: /genre/i })
    fireEvent.click(genreCheckbox)

    const state = useListColumnStore.getState()
    expect(state.visibleColumns).toContain('genre')
  })
})
