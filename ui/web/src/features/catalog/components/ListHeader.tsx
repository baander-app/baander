import styled, { css } from 'styled-components'
import { useCallback, useMemo, useState } from 'react'
import {
  SortableContext,
  useSortable,
  horizontalListSortingStrategy,
  arrayMove,
} from '@dnd-kit/sortable'
import { DndContext, closestCenter, PointerSensor, KeyboardSensor, useSensor, useSensors } from '@dnd-kit/core'
import { sortableKeyboardCoordinates } from '@dnd-kit/sortable'
import { CSS } from '@dnd-kit/utilities'
import { ALL_COLUMNS, DEFAULT_WIDTHS, useListColumnStore } from '../stores/list-column-store'
import { ColumnResizeHandle } from './ColumnResizeHandle'
import { interactiveTransition } from '@/shared/theme'

export interface SortState {
  field: string | null
  direction: 'asc' | 'desc' | null
}

const HeaderContainer = styled.div`
  display: flex;
  flex-shrink: 0;
  align-items: center;
  border-bottom: 1px solid var(--color-border);
  background-color: color-mix(in srgb, var(--color-muted) 30%, transparent);
  padding: 0 0.5rem;
  user-select: none;
`

const HeaderRow = styled.div`
  display: flex;
  min-width: 0;
  flex: 1;
  align-items: stretch;
`

const DraggableWrapper = styled.div<{ $isDragging: boolean }>`
  position: relative;
  display: flex;
  align-items: center;

  ${({ $isDragging }) =>
    $isDragging &&
    css`
      z-index: 50;
      opacity: 0.5;
    `}
`

const SortButton = styled.button`
  display: flex;
  flex-shrink: 0;
  align-items: center;
  gap: 0.25rem;
  padding: 0 0.5rem;
  font-size: 11px;
  font-weight: 500;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  color: var(--color-muted-foreground);
  ${interactiveTransition(['color'])}
  min-width: 0;
  cursor: pointer;
  background: none;
  border: none;

  &:hover {
    color: var(--color-foreground);
  }
`

const SortLabel = styled.span`
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
`

const SortArrow = styled.span`
  color: color-mix(in srgb, var(--color-muted-foreground) 60%, transparent);
`

const ContextMenu = styled.div`
  position: fixed;
  z-index: 50;
  min-width: 160px;
  border-radius: var(--radius-md);
  border: 1px solid var(--color-border);
  background-color: var(--color-popover);
  padding: 0.25rem;
  color: var(--color-popover-foreground);
  box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
`

const MenuLabel = styled.label`
  display: flex;
  cursor: pointer;
  align-items: center;
  gap: 0.5rem;
  border-radius: var(--radius-sm);
  padding: 0.375rem 0.5rem;
  font-size: 0.875rem;
  ${interactiveTransition(['color', 'background-color'])}

  &:hover {
    background-color: var(--color-accent);
  }
`

const MenuCheckbox = styled.input`
  width: 1rem;
  height: 1rem;
`

const Backdrop = styled.div`
  position: fixed;
  inset: 0;
  z-index: 40;
`

interface ListHeaderProps {
  sort: SortState
  onSortChange: (sort: SortState) => void
}

const HEADER_HEIGHT = 32

function DraggableColumn({
  col,
  isActive,
  sortDirection,
  width,
  onSortClick,
}: {
  col: { id: string; label: string; field: string }
  isActive: boolean
  sortDirection: 'asc' | 'desc' | null
  width: number
  onSortClick: () => void
}) {
  const { attributes, listeners, setNodeRef, transform, transition, isDragging } = useSortable({ id: col.id })

  return (
    <DraggableWrapper
      ref={setNodeRef}
      style={{
        transform: CSS.Transform.toString(transform),
        transition,
        height: HEADER_HEIGHT,
      }}
      $isDragging={isDragging}
      {...attributes}
    >
      <SortButton
        style={{ width }}
        onClick={onSortClick}
        {...listeners}
      >
        <SortLabel>{col.label}</SortLabel>
        {isActive && sortDirection === 'asc' && (
          <SortArrow>&#9650;</SortArrow>
        )}
        {isActive && sortDirection === 'desc' && (
          <SortArrow>&#9660;</SortArrow>
        )}
      </SortButton>
    </DraggableWrapper>
  )
}

export function ListHeader({ sort, onSortChange }: ListHeaderProps) {
  const { visibleColumns, columnOrder, columnWidths, toggleColumn, reorderColumns, setColumnWidth } = useListColumnStore()

  const orderedVisible = useMemo(
    () =>
      columnOrder
        .filter((id) => visibleColumns.includes(id))
        .map((id) => ALL_COLUMNS.find((c) => c.id === id)!)
        .filter(Boolean),
    [columnOrder, visibleColumns],
  )

  const handleHeaderClick = useCallback(
    (field: string) => {
      if (sort.field !== field) {
        onSortChange({ field, direction: 'asc' })
      } else if (sort.direction === 'asc') {
        onSortChange({ field, direction: 'desc' })
      } else if (sort.direction === 'desc') {
        onSortChange({ field: null, direction: null })
      } else {
        onSortChange({ field, direction: 'asc' })
      }
    },
    [sort, onSortChange],
  )

  const sensors = useSensors(
    useSensor(PointerSensor, { activationConstraint: { distance: 8 } }),
    useSensor(KeyboardSensor, { coordinateGetter: sortableKeyboardCoordinates }),
  )

  const handleDragEnd = useCallback(
    (event: { active: { id: string | number }; over: { id: string | number } | null }) => {
      const { active, over } = event
      if (!over || active.id === over.id) return

      const visibleIds = orderedVisible.map((c) => c.id)
      const oldIndex = visibleIds.indexOf(String(active.id))
      const newIndex = visibleIds.indexOf(String(over.id))
      if (oldIndex === -1 || newIndex === -1) return

      const newVisibleOrder = arrayMove(visibleIds, oldIndex, newIndex)
      const visibleSet = new Set(newVisibleOrder)
      const hiddenIds = columnOrder.filter((id) => !visibleSet.has(id))
      reorderColumns([...newVisibleOrder, ...hiddenIds])
    },
    [orderedVisible, columnOrder, reorderColumns],
  )

  const getWidth = useCallback(
    (colId: string): number => columnWidths[colId] ?? DEFAULT_WIDTHS[colId] ?? 150,
    [columnWidths],
  )

  const [columnMenuOpen, setColumnMenuOpen] = useState(false)
  const [menuPos, setMenuPos] = useState({ x: 0, y: 0 })

  return (
    <HeaderContainer
      style={{ height: HEADER_HEIGHT }}
      onContextMenu={(e) => {
        e.preventDefault()
        setMenuPos({ x: e.clientX, y: e.clientY })
        setColumnMenuOpen(true)
      }}
    >
      <DndContext sensors={sensors} collisionDetection={closestCenter} onDragEnd={handleDragEnd}>
        <SortableContext items={orderedVisible.map((c) => c.id)} strategy={horizontalListSortingStrategy}>
          <HeaderRow>
            {orderedVisible.map((col, i) => {
              const isLast = i === orderedVisible.length - 1
              const width = getWidth(col.id)

              return (
                <div key={col.id} style={{ display: 'flex', alignItems: 'center', height: HEADER_HEIGHT }}>
                  <DraggableColumn
                    col={col}
                    isActive={sort.field === col.field}
                    sortDirection={sort.direction}
                    width={width}
                    onSortClick={() => handleHeaderClick(col.field)}
                  />
                  {!isLast && (
                    <ColumnResizeHandle
                      columnId={col.id}
                      currentWidth={width}
                      onResize={setColumnWidth}
                    />
                  )}
                </div>
              )
            })}
          </HeaderRow>
        </SortableContext>
      </DndContext>

      {/* Column visibility context menu */}
      {columnMenuOpen && (
        <ContextMenu style={{ left: menuPos.x, top: menuPos.y }}>
          {ALL_COLUMNS.map((col) => (
            <MenuLabel key={col.id}>
              <MenuCheckbox
                type="checkbox"
                checked={visibleColumns.includes(col.id)}
                onChange={() => toggleColumn(col.id)}
              />
              {col.label}
            </MenuLabel>
          ))}
        </ContextMenu>
      )}
      {columnMenuOpen && (
        <Backdrop
          onClick={() => setColumnMenuOpen(false)}
          onContextMenu={(e) => {
            e.preventDefault()
            setColumnMenuOpen(false)
          }}
        />
      )}
    </HeaderContainer>
  )
}
