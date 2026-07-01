import styled, { keyframes } from 'styled-components'
import { useImageBlob } from '@/shared/hooks/use-image-blob'
import { DashboardSection } from '@/shared/components/dashboard-section'
import { HorizontalScrollRow } from '@/shared/components/horizontal-scroll-row'
import { useRecentItems } from '@/features/layout/hooks/use-recent-items'
import type { RecentItem } from '@/features/layout/components/SidebarRecentItems'

interface MediaTypeHomePageProps {
  title: string
  subtitle: string
  mediaType: string
  recentLabel: string
  emptyText: string
  icon: React.ReactNode
}

const pulse = keyframes`
  0%, 100% { opacity: 1; }
  50% { opacity: 0.5; }
`

// Card styles
const CardOuter = styled.div`
  width: 7rem;
  flex-shrink: 0;
  overflow: hidden;
  border-radius: var(--radius-lg);
  background-color: var(--color-card);
`

const CardImageArea = styled.div`
  aspect-ratio: 2 / 3;
  background-color: var(--color-secondary);
`

const CardInfo = styled.div`
  padding: 0.375rem;
`

const CardTitle = styled.p`
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  font-size: 0.75rem;
  font-weight: 500;
  color: var(--color-foreground);
`

const CardSubtitle = styled.p`
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  font-size: 11px;
  color: var(--color-muted-foreground);
`

const CoverImage = styled.img`
  height: 100%;
  width: 100%;
  object-fit: cover;
`

const LoadingPulse = styled.div`
  height: 100%;
  width: 100%;
  animation: ${pulse} 2s cubic-bezier(0.4, 0, 0.6, 1) infinite;
  background-color: var(--color-secondary);
`

const PlaceholderCover = styled.div`
  display: flex;
  height: 100%;
  width: 100%;
  align-items: center;
  justify-content: center;
`

const PlaceholderIcon = styled.svg`
  color: color-mix(in srgb, var(--color-muted-foreground) 20%, transparent);
`

// Skeleton styles
const SkeletonSection = styled.div`
  margin-bottom: 0.75rem;
  height: 1rem;
  width: 8rem;
`

const SkeletonRow = styled.div`
  display: flex;
  gap: 0.75rem;
`

const SkeletonCard = styled.div`
  width: 7rem;
  flex-shrink: 0;
`

const SkeletonCardImage = styled.div`
  aspect-ratio: 2 / 3;
  border-radius: var(--radius-lg);
  animation: ${pulse} 2s cubic-bezier(0.4, 0, 0.6, 1) infinite;
  background-color: var(--color-muted);
`

const SkeletonCardTitle = styled.div`
  margin-top: 0.375rem;
  height: 0.75rem;
  width: 5rem;
  animation: ${pulse} 2s cubic-bezier(0.4, 0, 0.6, 1) infinite;
  background-color: var(--color-muted);
  border-radius: var(--radius-md);
`

// Page styles
const PageContainer = styled.div`
  display: flex;
  height: 100%;
  flex-direction: column;
`

const PageHeader = styled.div`
  padding: 1rem 1.5rem;
`

const PageTitle = styled.h1`
  font-size: 1.125rem;
  font-weight: 600;
  letter-spacing: -0.025em;
`

const PageSubtitle = styled.p`
  margin-top: 0.125rem;
  font-size: 0.875rem;
  color: var(--color-muted-foreground);
`

const PageBody = styled.div`
  flex: 1;
  overflow-y: auto;
  padding: 0 1.5rem 1.5rem;
`

const ContentStack = styled.div`
  display: flex;
  flex-direction: column;
  gap: 2rem;
`

const EmptyContainer = styled.div`
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 0.75rem;
  padding: 5rem 0;
`

const EmptyMessage = styled.p`
  font-size: 0.875rem;
  color: var(--color-muted-foreground);
`

function MediaTypeHomePageCard({ item }: { item: RecentItem }) {
  const { src, isLoading } = useImageBlob(item.thumbnailUrl || null)

  return (
    <CardOuter>
      <CardImageArea>
        {isLoading ? (
          <LoadingPulse />
        ) : src ? (
          <CoverImage src={src} alt={item.title} loading="lazy" />
        ) : (
          <PlaceholderCover>
            <PlaceholderIcon width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.5">
              <rect x="2" y="2" width="20" height="20" rx="2" />
            </PlaceholderIcon>
          </PlaceholderCover>
        )}
      </CardImageArea>
      <CardInfo>
        <CardTitle>{item.title}</CardTitle>
        {item.subtitle && <CardSubtitle>{item.subtitle}</CardSubtitle>}
      </CardInfo>
    </CardOuter>
  )
}

function SectionSkeleton() {
  return (
    <div>
      <SkeletonSection />
      <SkeletonRow>
        {Array.from({ length: 6 }).map((_, i) => (
          <SkeletonCard key={i}>
            <SkeletonCardImage />
            <SkeletonCardTitle />
          </SkeletonCard>
        ))}
      </SkeletonRow>
    </div>
  )
}

export function MediaTypeHomePage({ title, subtitle, mediaType, recentLabel, emptyText, icon }: MediaTypeHomePageProps) {
  const { items, isLoading } = useRecentItems({ mediaType, limit: 10 })

  return (
    <PageContainer>
      <PageHeader>
        <PageTitle>{title}</PageTitle>
        <PageSubtitle>{subtitle}</PageSubtitle>
      </PageHeader>

      <PageBody>
        {isLoading ? (
          <ContentStack>
            <SectionSkeleton />
          </ContentStack>
        ) : items.length === 0 ? (
          <EmptyContainer>
            {icon}
            <EmptyMessage>{emptyText}</EmptyMessage>
          </EmptyContainer>
        ) : (
          <ContentStack>
            <DashboardSection title={recentLabel}>
              <HorizontalScrollRow>
                {items.map((item) => (
                  <MediaTypeHomePageCard key={item.id} item={item} />
                ))}
              </HorizontalScrollRow>
            </DashboardSection>
          </ContentStack>
        )}
      </PageBody>
    </PageContainer>
  )
}
