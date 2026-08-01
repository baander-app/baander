import * as React from 'react';
import styled, { css } from 'styled-components';
import type {
  DndContextProps,
  DragEndEvent,
  UniqueIdentifier,
} from '@dnd-kit/core';
import {
  DndContext,
  closestCenter,
  KeyboardSensor,
  PointerSensor,
  useSensor,
  useSensors,
} from '@dnd-kit/core';
import {
  arrayMove,
  SortableContext,
  sortableKeyboardCoordinates,
  useSortable,
  verticalListSortingStrategy,
  horizontalListSortingStrategy,
  rectSortingStrategy,
  type SortingStrategy,
} from '@dnd-kit/sortable';
import { CSS } from '@dnd-kit/utilities';
import { GripVerticalIcon } from 'lucide-react';

// --- Sortable Item ---

const StyledSortableItem = styled.div<{ $isDragging: boolean }>`
  position: relative;
  min-width: 0;
  overflow: hidden;
  ${({ $isDragging }) => $isDragging && css`
    z-index: 50;
    opacity: 0.5;
  `}
`;

const HandleButton = styled.button`
  margin-right: 0.375rem;
  cursor: grab;
  background: none;
  border: none;
  padding: 0;
  color: var(--color-muted-foreground);
  &:hover { color: var(--color-foreground); }
  &:active { cursor: grabbing; }
`;

const VisuallyHidden = styled.span`
  position: absolute; width: 1px; height: 1px; padding: 0; margin: -1px;
  overflow: hidden; clip: rect(0,0,0,0); white-space: nowrap; border: 0;
`;

interface SortableItemProps {
  id: UniqueIdentifier;
  children: React.ReactNode;
  className?: string;
  showHandle?: boolean;
  disabled?: boolean;
}

function SortableItem({
  id,
  children,
  className,
  showHandle = false,
  disabled = false,
}: SortableItemProps) {
  const {
    attributes,
    listeners,
    setNodeRef,
    transform,
    transition,
    isDragging,
  } = useSortable({ id, disabled });

  const style = {
    transform: CSS.Transform.toString(transform),
    transition,
  };

  return (
    <StyledSortableItem
      ref={setNodeRef}
      style={style}
      $isDragging={isDragging}
      className={className}
      {...attributes}
    >
      <div style={{ display: 'flex', minWidth: 0, alignItems: 'center', overflow: 'hidden' }}>
        {showHandle && (
          <HandleButton type="button" {...listeners} tabIndex={-1}>
            <GripVerticalIcon style={{ width: '1rem', height: '1rem' }} />
            <VisuallyHidden>Drag to reorder</VisuallyHidden>
          </HandleButton>
        )}
        <div style={{ minWidth: 0, ...(showHandle ? { flex: 1 } : {}) }}>{children}</div>
      </div>
    </StyledSortableItem>
  );
}

// --- Sortable Container ---

type SortDirection = 'vertical' | 'horizontal' | 'grid';

interface SortableContainerProps {
  items: UniqueIdentifier[];
  children: React.ReactNode;
  direction?: SortDirection;
  onReorder: (items: UniqueIdentifier[]) => void;
  className?: string;
  dndContextProps?: Partial<Omit<DndContextProps, 'children'>>;
}

const strategyMap: Record<SortDirection, SortingStrategy> = {
  vertical: verticalListSortingStrategy,
  horizontal: horizontalListSortingStrategy,
  grid: rectSortingStrategy,
};

function SortableContainer({
  items,
  children,
  direction = 'vertical',
  onReorder,
  className,
  dndContextProps,
}: SortableContainerProps) {
  const sensors = useSensors(
    useSensor(PointerSensor, {
      activationConstraint: { distance: 8 },
    }),
    useSensor(KeyboardSensor, {
      coordinateGetter: sortableKeyboardCoordinates,
    })
  );

  function handleDragEnd(event: DragEndEvent) {
    const { active, over } = event;
    if (over && active.id !== over.id) {
      const oldIndex = items.indexOf(active.id);
      const newIndex = items.indexOf(over.id);
      onReorder(arrayMove(items, oldIndex, newIndex));
    }
  }

  return (
    <DndContext
      sensors={sensors}
      collisionDetection={closestCenter}
      onDragEnd={handleDragEnd}
      {...dndContextProps}
    >
      <SortableContext items={items} strategy={strategyMap[direction]}>
        <div className={className}>{children}</div>
      </SortableContext>
    </DndContext>
  );
}

// --- Drop Indicator ---

const StyledDropIndicator = styled.div<{ $position: 'before' | 'after' }>`
  height: 0.125rem;
  width: 100%;
  border-radius: 9999px;
  background-color: var(--color-primary);
  transition: opacity var(--duration-hover) ease-out;
  ${({ $position }) => $position === 'after' && 'margin-top: 0.125rem;'}
  ${({ $position }) => $position === 'before' && 'margin-bottom: 0.125rem;'}
`;

interface DropIndicatorProps {
  position: 'before' | 'after';
  className?: string;
}

function DropIndicator({ position, className }: DropIndicatorProps) {
  return <StyledDropIndicator $position={position} className={className} />;
}

export { SortableItem, SortableContainer, DropIndicator };
export type { SortableItemProps, SortableContainerProps, SortDirection };
