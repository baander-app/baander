import styled, { css } from 'styled-components'
import { useRef, useEffect, useCallback } from 'react'
import { useVirtualizer } from '@tanstack/react-virtual'
import { useImageBlob } from '@/shared/hooks/use-image-blob'
import { Music } from 'lucide-react'

export interface BrowserItem {
  id: string
  label: string
  sublabel?: string
  secondary?: string
  coverUrl?: string
  blurhash?: string
}

const ColumnContainer = styled.div<{ $className?: string }>`
  display: flex;
  flex-direction: column;
  border-right: 1px solid var(--color-border);
  ${({ $className }) => $className}
`

const ColumnHeader = styled.div`
  display: flex;
  height: 2rem;
  flex-shrink: 0;
  align-items: center;
  border-bottom: 1px solid var(--color-border);
  background-color: color-mix(in srgb, var(--color-muted) 30%, transparent);
  padding: 0 0.75rem;
`

const HeaderLabel = styled.span`
  font-size: 11px;
  font-weight: 500;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  color: var(--color-muted-foreground);
`

const HeaderCount = styled.span`
  margin-left: auto;
  font-size: 11px;
  color: var(--color-muted-foreground);
`

const LoadingArea = styled.div`
  flex: 1;
  padding: 0.5rem;
`

const LoadingSkeleton = styled.div`
  margin-bottom: 0.375rem;
  height: 1.25rem;
  animation: pulse 2s cubic-bezier(0.4, 0, 0.6, 1) infinite;
  border-radius: var(--radius-sm);
  background-color: color-mix(in srgb, var(--color-muted) 40%, transparent);
`

const ScrollArea = styled.div<{ $isFocused: boolean }>`
  flex: 1;
  overflow-y: auto;
  outline: none;
  transition: opacity 80ms ease-out, transform 80ms ease-out;

  ${({ $isFocused }) =>
    $isFocused &&
    css`
      box-shadow: inset 0 0 0 1px color-mix(in srgb, var(--color-primary) 30%, transparent);
    `}
`

const OptionButton = styled.button<{ $isSelected: boolean; $hasCover: boolean }>`
  display: flex;
  width: 100%;
  align-items: center;
  padding: 0 0.75rem;
  gap: ${({ $hasCover }) => ($hasCover ? '0.375rem' : '0')};
  text-align: left;
  transition: color 0ms;
  border: none;
  background: none;
  cursor: pointer;
  font-size: 0.875rem;

  ${({ $isSelected }) =>
    $isSelected
      ? css`
        background-color: var(--color-accent);
        color: var(--color-accent-foreground);
      `
      : css`
        color: color-mix(in srgb, var(--color-foreground) 80%, transparent);
        &:hover {
          background-color: color-mix(in srgb, var(--color-accent) 30%, transparent);
        }
      `}
`

const ItemLabel = styled.span`
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  font-size: 0.875rem;
`

const ItemSecondary = styled.span`
  margin-left: auto;
  flex-shrink: 0;
  padding-left: 0.5rem;
  font-size: 11px;
  color: var(--color-muted-foreground);
`

const CoverThumbnail = styled.span`
  display: flex;
  width: 1.25rem;
  height: 1.25rem;
  flex-shrink: 0;
  align-items: center;
  justify-content: center;
  overflow: hidden;
  border-radius: 2px;
  background-color: var(--color-secondary);
`

const CoverImg = styled.img`
  width: 100%;
  height: 100%;
  object-fit: cover;
`

const PlaceholderIcon = styled(Music)`
  width: 0.5rem;
  height: 0.5rem;
  color: color-mix(in srgb, var(--color-muted-foreground) 30%, transparent);
`

interface BrowserColumnProps {
  title: string
  items: BrowserItem[]
  selectedId: string | null
  onSelect: (id: string | null) => void
  isLoading?: boolean
  isFocused?: boolean
  onFocusColumn?: () => void
  className?: string
}

const ROW_HEIGHT = 28

function BrowserItemRow({
  item,
  isSelected,
  onSelect,
  height,
  translateY,
}: {
  item: BrowserItem
  isSelected: boolean
  onSelect: () => void
  height: number
  translateY: number
}) {
  const coverUrl = item.coverUrl ?? null
  const { src: coverSrc } = useImageBlob(coverUrl)
  const hasCover = !!coverUrl

  return (
    <OptionButton
      role="option"
      aria-selected={isSelected}
      onClick={onSelect}
      $isSelected={isSelected}
      $hasCover={hasCover}
      style={{
        position: 'absolute',
        top: 0,
        left: 0,
        height: `${height}px`,
        transform: `translateY(${translateY}px)`,
      }}
    >
      {hasCover && (
        <CoverThumbnail>
          {coverSrc ? (
            <CoverImg src={coverSrc} alt="" loading="lazy" />
          ) : (
            <PlaceholderIcon />
          )}
        </CoverThumbnail>
      )}
      <ItemLabel>{item.label}</ItemLabel>
      {(item.sublabel ?? item.secondary) && (
        <ItemSecondary>
          {item.sublabel ?? item.secondary}
        </ItemSecondary>
      )}
    </OptionButton>
  )
}

export function BrowserColumn({
  title,
  items,
  selectedId,
  onSelect,
  isLoading,
  isFocused,
  onFocusColumn,
  className,
}: BrowserColumnProps) {
  const parentRef = useRef<HTMLDivElement>(null)
  const prevItemCount = useRef(items.length)

  const virtualizer = useVirtualizer({
    count: items.length,
    getScrollElement: () => parentRef.current,
    estimateSize: () => ROW_HEIGHT,
    overscan: 20,
    getItemKey: (i) => items[i]?.id ?? i,
  })

  // Reset scroll when items change significantly (cascade filter)
  useEffect(() => {
    if (items.length !== prevItemCount.current) {
      parentRef.current?.scrollTo(0, 0)
      prevItemCount.current = items.length
    }
  }, [items.length])

  // Auto-focus the scroll container when this column becomes focused
  useEffect(() => {
    if (isFocused) {
      parentRef.current?.focus()
    }
  }, [isFocused])

  const handleKeyDown = useCallback(
    (e: React.KeyboardEvent) => {
      if (!items.length) return
      const idx = items.findIndex((i) => i.id === selectedId)
      let next = idx

      if (e.key === 'ArrowDown') {
        e.preventDefault()
        next = Math.min(idx + 1, items.length - 1)
      } else if (e.key === 'ArrowUp') {
        e.preventDefault()
        next = Math.max(idx - 1, 0)
      } else if (e.key === 'Home') {
        e.preventDefault()
        next = 0
      } else if (e.key === 'End') {
        e.preventDefault()
        next = items.length - 1
      }

      if (next !== idx && items[next]) {
        onSelect(items[next].id)
        virtualizer.scrollToIndex(next, { align: 'auto' })
      }
    },
    [items, selectedId, onSelect, virtualizer],
  )

  if (isLoading) {
    return (
      <ColumnContainer $className={className}>
        <ColumnHeader>
          <HeaderLabel>{title}</HeaderLabel>
        </ColumnHeader>
        <LoadingArea>
          {Array.from({ length: 8 }).map((_, i) => (
            <LoadingSkeleton key={i} />
          ))}
        </LoadingArea>
      </ColumnContainer>
    )
  }

  return (
    <ColumnContainer $className={className}>
      <ColumnHeader>
        <HeaderLabel>{title}</HeaderLabel>
        {items.length > 0 && (
          <HeaderCount>{items.length}</HeaderCount>
        )}
      </ColumnHeader>
      <ScrollArea
        ref={parentRef}
        $isFocused={!!isFocused}
        tabIndex={0}
        onKeyDown={handleKeyDown}
        onFocus={onFocusColumn}
        role="listbox"
        aria-label={title}
      >
        {/* "All" option */}
        <OptionButton
          role="option"
          aria-selected={selectedId === null}
          onClick={() => onSelect(null)}
          $isSelected={selectedId === null}
          $hasCover={false}
          style={{ height: ROW_HEIGHT }}
        >
          All
        </OptionButton>

        <div
          style={{
            height: `${virtualizer.getTotalSize()}px`,
            width: '100%',
            position: 'relative',
          }}
        >
          {virtualizer.getVirtualItems().map((virtualRow) => {
            const item = items[virtualRow.index]
            return (
              <BrowserItemRow
                key={item.id}
                item={item}
                isSelected={selectedId === item.id}
                onSelect={() => onSelect(item.id)}
                height={virtualRow.size}
                translateY={virtualRow.start}
              />
            )
          })}
        </div>
      </ScrollArea>
    </ColumnContainer>
  )
}
