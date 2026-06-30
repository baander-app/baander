import styled, { css } from 'styled-components'
import { useCallback, useRef, useState } from 'react'

const HandleContainer = styled.div<{ $isResizing: boolean }>`
  position: relative;
  z-index: 20;
  height: 100%;
  width: 0.75rem;
  flex-shrink: 0;
  cursor: col-resize;

  ${({ $isResizing }) =>
    $isResizing &&
    css`z-index: 50;`}
`

const HandleBar = styled.div<{ $isResizing: boolean }>`
  position: absolute;
  top: 0.25rem;
  bottom: 0.25rem;
  left: 50%;
  transform: translateX(-50%);
  width: 2px;
  border-radius: 9999px;
  transition: background-color 150ms;
  background-color: ${({ $isResizing }) =>
    $isResizing
      ? 'var(--color-primary)'
      : 'rgba(255, 255, 255, 0.2)'};

  ${HandleContainer}:hover & {
    background-color: ${({ $isResizing }) =>
      $isResizing
        ? 'var(--color-primary)'
        : 'rgba(255, 255, 255, 0.5)'};
  }
`

interface ColumnResizeHandleProps {
  columnId: string
  currentWidth: number
  onResize: (columnId: string, width: number) => void
}

const MIN_WIDTH = 40
const MAX_WIDTH = 600

export function ColumnResizeHandle({ columnId, currentWidth, onResize }: ColumnResizeHandleProps) {
  const [isResizing, setIsResizing] = useState(false)
  const startXRef = useRef(0)
  const startWidthRef = useRef(0)

  const handlePointerDown = useCallback(
    (e: React.PointerEvent) => {
      e.preventDefault()
      e.stopPropagation()

      startXRef.current = e.clientX
      startWidthRef.current = currentWidth
      setIsResizing(true)

      const handlePointerMove = (moveEvent: PointerEvent) => {
        moveEvent.preventDefault()
        const delta = moveEvent.clientX - startXRef.current
        const newWidth = Math.min(MAX_WIDTH, Math.max(MIN_WIDTH, startWidthRef.current + delta))
        onResize(columnId, newWidth)
      }

      const handlePointerUp = () => {
        setIsResizing(false)
        document.removeEventListener('pointermove', handlePointerMove)
        document.removeEventListener('pointerup', handlePointerUp)
        document.body.style.cursor = ''
        document.body.style.userSelect = ''
      }

      document.body.style.cursor = 'col-resize'
      document.body.style.userSelect = 'none'
      document.addEventListener('pointermove', handlePointerMove)
      document.addEventListener('pointerup', handlePointerUp)
    },
    [columnId, currentWidth, onResize],
  )

  return (
    <HandleContainer
      $isResizing={isResizing}
      onPointerDown={handlePointerDown}
    >
      <HandleBar $isResizing={isResizing} />
    </HandleContainer>
  )
}
