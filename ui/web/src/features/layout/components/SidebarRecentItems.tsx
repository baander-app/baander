import styled from 'styled-components'
import { useImageBlob } from '@/shared/hooks/use-image-blob'

export interface RecentItem {
  id: string
  title: string
  subtitle: string
  timestamp: string
  thumbnailUrl: string
  mediaType?: string
  publicId?: string
}

const SectionWrapper = styled.div`
  & > * + * {
    margin-top: 0.125rem;
  }
  padding: 0 0.5rem;
`

const SectionHeader = styled.div`
  padding: 0.75rem 1rem 0.25rem;
  font-size: 11px;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  font-weight: 500;
  color: var(--color-muted-foreground);
`

const EmptyText = styled.p`
  padding: 0.375rem 0.625rem;
  font-size: 0.875rem;
  color: var(--color-muted-foreground);
  font-style: italic;
`

const RecentRow = styled.div`
  display: flex;
  align-items: center;
  gap: 0.5rem;
  border-radius: var(--radius-md);
  padding: 0.375rem 0.625rem;
  transition: background-color 150ms ease;

  &:hover {
    background-color: color-mix(in srgb, var(--color-accent) 50%, transparent);
  }
`

const ThumbnailPlaceholder = styled.div`
  display: flex;
  width: 2rem;
  height: 2rem;
  flex-shrink: 0;
  align-items: center;
  justify-content: center;
  border-radius: var(--radius-md);
  background-color: var(--color-secondary);
`

const ThumbnailLoading = styled.div`
  width: 2rem;
  height: 2rem;
  flex-shrink: 0;
  border-radius: var(--radius-md);
  background-color: var(--color-secondary);
  animation: pulse 2s cubic-bezier(0.4, 0, 0.6, 1) infinite;

  @keyframes pulse {
    0%, 100% { opacity: 1; }
    50% { opacity: 0.5; }
  }
`

const ThumbnailImg = styled.img`
  width: 2rem;
  height: 2rem;
  flex-shrink: 0;
  border-radius: var(--radius-md);
  object-fit: cover;
`

const ItemText = styled.div`
  min-width: 0;
  flex: 1;
`

const ItemTitle = styled.div`
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  font-size: 0.875rem;
  color: var(--color-foreground);
`

const ItemSubtitle = styled.div`
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  font-size: 0.75rem;
  color: var(--color-muted-foreground);
`

const ItemTime = styled.span`
  flex-shrink: 0;
  font-size: 11px;
  color: var(--color-muted-foreground);
`

interface SidebarRecentItemsProps {
  items: RecentItem[]
}

function RecentItemThumbnail({ url }: { url: string }) {
  const { src, isLoading } = useImageBlob(url || null)

  if (isLoading) {
    return <ThumbnailLoading />
  }

  if (!src) {
    return (
      <ThumbnailPlaceholder>
        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.5" style={{ color: 'color-mix(in srgb, var(--color-muted-foreground) 20%, transparent)' }}>
          <circle cx="12" cy="12" r="10" />
          <circle cx="12" cy="12" r="3" />
        </svg>
      </ThumbnailPlaceholder>
    )
  }

  return (
    <ThumbnailImg
      src={src}
      alt=""
      width={32}
      height={32}
      loading="lazy"
    />
  )
}

export function SidebarRecentItems({ items }: SidebarRecentItemsProps) {
  return (
    <div role="group" aria-labelledby="recent-header">
      <SectionHeader id="recent-header">Recent</SectionHeader>
      <SectionWrapper>
        {items.length === 0 ? (
          <EmptyText>Nothing played yet</EmptyText>
        ) : (
          items.map((item) => (
            <RecentRow
              key={item.id}
              aria-label={`${item.title} by ${item.subtitle}, played ${item.timestamp}`}
            >
              <RecentItemThumbnail url={item.thumbnailUrl} />
              <ItemText>
                <ItemTitle>{item.title}</ItemTitle>
                <ItemSubtitle>{item.subtitle}</ItemSubtitle>
              </ItemText>
              <ItemTime>{item.timestamp}</ItemTime>
            </RecentRow>
          ))
        )}
      </SectionWrapper>
    </div>
  )
}
