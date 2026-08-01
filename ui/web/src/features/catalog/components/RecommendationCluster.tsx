import styled, { css } from 'styled-components'
import { useNavigate } from 'react-router-dom'
import { Music } from 'lucide-react'
import { useSelectionStore } from '../stores/selection-store'
import { useImageBlob } from '@/shared/hooks/use-image-blob'
import type { Recommendation, RecommendationCluster } from '../types/recommendation'
import { focusVisibleRing } from '@/shared/theme'

const RecCard = styled.div<{ $isSelected: boolean }>`
  flex-shrink: 0;
  width: 120px;
  cursor: pointer;
  border-radius: var(--radius-md);
  ${focusVisibleRing}

  ${({ $isSelected }) =>
    $isSelected &&
    css`
      outline: 2px solid var(--color-primary);
      outline-offset: -2px;
    `}
`

const RecCover = styled.div`
  aspect-ratio: 1;
  width: 120px;
  overflow: hidden;
  border-radius: var(--radius-md);
  background-color: var(--color-secondary);
`

const LoadingPlaceholder = styled.div`
  display: flex;
  height: 100%;
  width: 100%;
  align-items: center;
  justify-content: center;
  background-color: color-mix(in srgb, var(--color-muted) 50%, transparent);
`

const LoadingSpinner = styled.div`
  height: 2rem;
  width: 2rem;
  animation: pulse 2s cubic-bezier(0.4, 0, 0.6, 1) infinite;
  border-radius: 50%;
  background-color: var(--color-muted);
`

const CoverImage = styled.img`
  height: 100%;
  width: 100%;
  object-fit: cover;
`

const CoverPlaceholder = styled.div`
  display: flex;
  height: 100%;
  width: 100%;
  align-items: center;
  justify-content: center;
  color: color-mix(in srgb, var(--color-muted-foreground) 30%, transparent);
`

const RecTitle = styled.p`
  margin-top: 0.5rem;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  font-size: 0.75rem;
  font-weight: 500;
`

const RecSubtitle = styled.p`
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  font-size: 11px;
  color: var(--color-muted-foreground);
`

const SectionTitle = styled.h3`
  margin-bottom: 0.75rem;
  font-size: 0.875rem;
  font-weight: 600;
  letter-spacing: -0.025em;
  color: var(--color-foreground);
`

const RecStrip = styled.div`
  display: flex;
  gap: 1rem;
  overflow-x: auto;
  padding-bottom: 0.5rem;
`

interface RecommendationClusterProps {
  cluster: RecommendationCluster
}

function targetPath(rec: Recommendation): string {
  switch (rec.target_type) {
    case 'album':
      return `/albums/${rec.target_id}`
    case 'artist':
      return `/artists/${rec.target_id}`
    default:
      return '#'
  }
}

function RecommendationCard({ rec }: { rec: Recommendation }) {
  const navigate = useNavigate()
  const select = useSelectionStore((s) => s.select)
  const selectedId = useSelectionStore((s) => s.selectedId)

  const isSelected = selectedId === rec.target_id
  const { src, isLoading } = useImageBlob(rec.coverImageUrl ?? null)

  const displayName = rec.targetTitle ?? rec.target_id
  const subtitleName =
    rec.target_type === 'album'
      ? rec.targetArtistName ?? rec.target_type
      : null

  return (
    <RecCard
      role="button"
      tabIndex={0}
      $isSelected={isSelected}
      onClick={() => select(rec.target_id, rec.target_type === 'album' ? 'album' : 'artist')}
      onKeyDown={(e) => {
        if (e.key === 'Enter' || e.key === ' ') {
          e.preventDefault()
          navigate(targetPath(rec))
        }
      }}
    >
      <RecCover>
        {isLoading ? (
          <LoadingPlaceholder>
            <LoadingSpinner />
          </LoadingPlaceholder>
        ) : src ? (
          <CoverImage src={src} alt={displayName} loading="lazy" />
        ) : (
          <CoverPlaceholder>
            <Music width={24} height={24} strokeWidth={1.5} />
          </CoverPlaceholder>
        )}
      </RecCover>
      <RecTitle title={displayName}>{displayName}</RecTitle>
      {subtitleName && (
        <RecSubtitle title={subtitleName}>{subtitleName}</RecSubtitle>
      )}
    </RecCard>
  )
}

export function RecommendationClusterRow({ cluster }: RecommendationClusterProps) {
  const headerLabel =
    cluster.sourceType === 'album'
      ? `Because you listened to ${cluster.sourceName}`
      : cluster.sourceType === 'artist'
        ? `Similar to ${cluster.sourceName}`
        : cluster.sourceName

  return (
    <section aria-label={headerLabel}>
      <SectionTitle>{headerLabel}</SectionTitle>
      <RecStrip>
        {cluster.items.map((rec) => (
          <RecommendationCard key={rec.id} rec={rec} />
        ))}
      </RecStrip>
    </section>
  )
}
