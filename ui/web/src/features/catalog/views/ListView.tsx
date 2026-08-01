import { useRef, useCallback, useState, useEffect } from 'react'
import { useVirtualizer } from '@tanstack/react-virtual'
import styled, { keyframes } from 'styled-components'
import { Loader2 } from 'lucide-react'
import { ListHeader, type SortState } from '../components/ListHeader'
import { ListRow } from '../components/ListRow'
import { useSongList } from '../hooks/use-song-list'

const spin = keyframes`
  from { transform: rotate(0deg); }
  to { transform: rotate(360deg); }
`

const pulse = keyframes`
  0%, 100% { opacity: 1; }
  50% { opacity: 0.5; }
`

const PageContainer = styled.div`
  display: flex;
  height: 100%;
  flex-direction: column;
  overflow: hidden;
`

const ScrollArea = styled.div`
  flex: 1;
  overflow-y: auto;
`

const FetchingIndicator = styled.div`
  display: flex;
  height: 2rem;
  align-items: center;
  justify-content: center;

  svg {
    animation: ${spin} 1s linear infinite;
    color: var(--color-muted-foreground);
  }
`

const LoadingContainer = styled.div`
  flex: 1;
  padding: 0.5rem;
`

const SkeletonRow = styled.div`
  display: flex;
  height: 2rem;
  align-items: center;
  gap: 0.5rem;
  margin-bottom: 0.25rem;
  border-radius: var(--radius-sm);
  background-color: color-mix(in srgb, var(--color-muted) 30%, transparent);
  animation: ${pulse} 2s cubic-bezier(0.4, 0, 0.6, 1) infinite;

  & > div {
    height: 0.75rem;
    border-radius: var(--radius-sm);
    background-color: color-mix(in srgb, var(--color-muted) 50%, transparent);
  }
`

const SkeletonCol1 = styled.div`width: 1.5rem;`
const SkeletonCol2 = styled.div`width: 10rem;`
const SkeletonCol3 = styled.div`width: 6rem;`
const SkeletonCol4 = styled.div`width: 7rem;`

const EmptyContainer = styled.div`
  display: flex;
  flex: 1;
  align-items: center;
  justify-content: center;
`

const EmptyText = styled.p`
  font-size: 0.875rem;
  color: var(--color-muted-foreground);
`

const ROW_HEIGHT = 32

export function ListView() {
  const parentRef = useRef<HTMLDivElement>(null)
  const [sort, setSort] = useState<SortState>({ field: null, direction: null })

  const { songs, isLoading, isFetchingMore, hasNextPage, fetchMore } = useSongList({ sort })

  const virtualizer = useVirtualizer({
    count: songs.length,
    getScrollElement: () => parentRef.current,
    estimateSize: () => ROW_HEIGHT,
    overscan: 30,
    getItemKey: (i) => songs[i]?.publicId ?? i,
  })

  // Infinite scroll: trigger fetchMore when near bottom
  const rows = virtualizer.getVirtualItems()
  useEffect(() => {
    const lastRow = rows[rows.length - 1]
    if (!lastRow) return
    if (lastRow.index >= songs.length - 10 && hasNextPage && !isFetchingMore) {
      fetchMore()
    }
  }, [rows, songs.length, hasNextPage, isFetchingMore, fetchMore])

  const handleSortChange = useCallback((newSort: SortState) => {
    setSort(newSort)
  }, [])

  if (isLoading) {
    return (
      <PageContainer>
        <ListHeader sort={sort} onSortChange={handleSortChange} />
        <LoadingContainer>
          {Array.from({ length: 20 }).map((_, i) => (
            <SkeletonRow key={i}>
              <SkeletonCol1 />
              <SkeletonCol2 />
              <SkeletonCol3 />
              <SkeletonCol4 />
            </SkeletonRow>
          ))}
        </LoadingContainer>
      </PageContainer>
    )
  }

  if (!songs.length) {
    return (
      <PageContainer>
        <ListHeader sort={sort} onSortChange={handleSortChange} />
        <EmptyContainer>
          <EmptyText>No songs</EmptyText>
        </EmptyContainer>
      </PageContainer>
    )
  }

  return (
    <PageContainer>
      <ListHeader sort={sort} onSortChange={handleSortChange} />

      <ScrollArea ref={parentRef}>
        <div
          style={{
            height: `${virtualizer.getTotalSize()}px`,
            width: '100%',
            position: 'relative',
          }}
        >
          {virtualizer.getVirtualItems().map((virtualRow) => {
            const song = songs[virtualRow.index]
            return (
              <ListRow
                key={song.publicId}
                song={song}
                allSongs={songs}
                style={{
                  height: `${virtualRow.size}px`,
                  transform: `translateY(${virtualRow.start}px)`,
                }}
              />
            )
          })}
        </div>
        {isFetchingMore && (
          <FetchingIndicator>
            <Loader2 size={14} />
          </FetchingIndicator>
        )}
      </ScrollArea>
    </PageContainer>
  )
}
