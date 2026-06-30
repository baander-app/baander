import { useState } from 'react'
import styled, { css } from 'styled-components'
import type { DuplicateGroup as DuplicateGroupType } from '../../api/album-duplicates-api'
import { getCoverImageUrl } from '../../api/album-duplicates-api'
import { Merge } from 'lucide-react'
import { Button } from '@/shared/components/ui/button'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/shared/components/ui/dialog'
import { useMutation } from '@tanstack/react-query'
import { AXIOS_INSTANCE } from '@/shared/api-client/axios-instance'
import { toast } from 'sonner'
import { interactiveTransition } from '@/shared/theme'

interface DuplicateGroupCardProps {
  group: DuplicateGroupType
  onMergeComplete?: () => void
}

function formatConfidence(confidence: number): string {
  return `${Math.round(confidence * 100)}%`
}

function getConfidenceLabel(confidence: number): string {
  if (confidence >= 0.95) return 'Very High'
  if (confidence >= 0.85) return 'High'
  if (confidence >= 0.75) return 'Medium'
  return 'Low'
}

function getConfidenceColor(confidence: number): string {
  if (confidence >= 0.95) return '#10b981'
  if (confidence >= 0.85) return '#3b82f6'
  if (confidence >= 0.75) return '#f59e0b'
  return '#f97316'
}

const Card = styled.div`
  border-radius: var(--radius-lg, 0.5rem);
  border: 1px solid var(--color-border);
  background-color: var(--color-card);
`

const CardHeader = styled.div`
  border-bottom: 1px solid var(--color-border);
  padding: 0.75rem 1rem;
  display: flex;
  align-items: center;
  justify-content: space-between;
`

const HeaderLeft = styled.div`
  display: flex;
  align-items: center;
  gap: 0.75rem;
`

const CardTitle = styled.span`
  font-size: 0.875rem;
  font-weight: 500;
`

const ConfidenceBadge = styled.span<{ $color: string }>`
  font-size: 0.75rem;
  font-weight: 500;
  color: ${({ $color }) => $color};
`

const CardBody = styled.div`
  padding: 1rem;
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
`

const AlbumRow = styled.div`
  display: flex;
  align-items: flex-start;
  gap: 0.75rem;
`

const Thumbnail = styled.div`
  height: 3rem;
  width: 3rem;
  flex-shrink: 0;
  border-radius: 0.25rem;
  background-color: var(--color-muted);
  overflow: hidden;
`

const ThumbnailImg = styled.img`
  height: 100%;
  width: 100%;
  object-fit: cover;
`

const NoCoverLabel = styled.div`
  height: 100%;
  width: 100%;
  display: flex;
  align-items: center;
  justify-content: center;
  color: var(--color-muted-foreground);
  font-size: 0.75rem;
`

const AlbumInfo = styled.div`
  min-width: 0;
  flex: 1;
`

const AlbumTitle = styled.p`
  font-size: 0.875rem;
  font-weight: 500;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
`

const AlbumMeta = styled.div`
  display: flex;
  align-items: center;
  gap: 0.5rem;
  font-size: 0.75rem;
  color: var(--color-muted-foreground);
`

const AlbumDetails = styled.div`
  display: flex;
  align-items: center;
  gap: 0.5rem;
  font-size: 11px;
  color: var(--color-muted-foreground);
  margin-top: 0.125rem;
`

// Merge dialog styled components
const TargetList = styled.div`
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
  padding: 1rem 0;
`

const TargetHint = styled.p`
  font-size: 0.75rem;
  color: var(--color-muted-foreground);
`

const TargetButton = styled.button<{ $selected: boolean }>`
  width: 100%;
  display: flex;
  align-items: flex-start;
  gap: 0.75rem;
  border-radius: var(--radius-lg, 0.5rem);
  border: 1px solid;
  padding: 0.75rem;
  text-align: left;
  ${interactiveTransition(['border-color', 'background-color'])}

  ${({ $selected }) => $selected
    ? css`
        border-color: var(--color-primary);
        background-color: color-mix(in srgb, var(--color-primary) 5%, transparent);
      `
    : css`
        border-color: var(--color-border);
        &:hover { background-color: color-mix(in srgb, var(--color-muted) 40%, transparent); }
      `
  }
`

const TargetAlbumInfo = styled.div`
  min-width: 0;
  flex: 1;
`

const TargetAlbumTitle = styled.p`
  font-size: 0.875rem;
  font-weight: 500;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
`

const TargetAlbumMeta = styled.p`
  font-size: 0.75rem;
  color: var(--color-muted-foreground);
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
`

const RadioButton = styled.div<{ $selected: boolean }>`
  height: 1rem;
  width: 1rem;
  border-radius: 50%;
  border: 2px solid ${({ $selected }) => $selected ? 'var(--color-primary)' : 'var(--color-muted-foreground)'};
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
`

const RadioDot = styled.div`
  height: 0.5rem;
  width: 0.5rem;
  border-radius: 50%;
  background-color: var(--color-primary);
`

export function DuplicateGroupCard({ group, onMergeComplete }: DuplicateGroupCardProps) {
  const [isMergeDialogOpen, setIsMergeDialogOpen] = useState(false)
  const [targetAlbumId, setTargetAlbumId] = useState<string | null>(null)

  const mergeMutation = useMutation({
    mutationFn: ({ targetId, sourceId }: { targetId: string; sourceId: string }) =>
      AXIOS_INSTANCE.post('/api/albums/merge', {
        targetPublicId: targetId,
        sourcePublicId: sourceId,
      }),
    onSuccess: () => {
      toast.success('The duplicate albums have been merged.')
      setIsMergeDialogOpen(false)
      onMergeComplete?.()
    },
    onError: (error: unknown) => {
      toast.error(error instanceof Error ? error.message : 'An error occurred')
    },
  })

  const handleMerge = () => {
    if (!targetAlbumId) return
    const sourceAlbum = group.albums.find(a => a.uuid !== targetAlbumId)
    if (!sourceAlbum) return
    mergeMutation.mutate({ targetId: targetAlbumId, sourceId: sourceAlbum.uuid })
  }

  return (
    <Card>
      <CardHeader>
        <HeaderLeft>
          <CardTitle>Duplicate Group</CardTitle>
          <ConfidenceBadge $color={getConfidenceColor(group.confidence)}>
            {getConfidenceLabel(group.confidence)} ({formatConfidence(group.confidence)})
          </ConfidenceBadge>
        </HeaderLeft>
        <Button variant="ghost" size="sm" onClick={() => setIsMergeDialogOpen(true)}>
          <Merge size={14} strokeWidth={1.5} style={{ marginRight: '0.375rem' }} />
          Merge
        </Button>
      </CardHeader>

      <CardBody>
        {group.albums.map((album) => (
          <AlbumRow key={album.uuid}>
            <Thumbnail>
              {album.coverImage?.publicId ? (
                <ThumbnailImg src={getCoverImageUrl(album.coverImage.publicId)} alt="" loading="lazy" />
              ) : (
                <NoCoverLabel>No Cover</NoCoverLabel>
              )}
            </Thumbnail>
            <AlbumInfo>
              <AlbumTitle>{album.title}</AlbumTitle>
              <AlbumMeta>
                {album.year && <span>{album.year}</span>}
                {album.artists && album.artists.length > 0 && (
                  <span>· {album.artists.map(a => a.name).join(', ')}</span>
                )}
              </AlbumMeta>
              <AlbumDetails>
                {album.label && <span>{album.label}</span>}
                {album.barcode && <span>· {album.barcode}</span>}
              </AlbumDetails>
            </AlbumInfo>
          </AlbumRow>
        ))}
      </CardBody>

      <Dialog open={isMergeDialogOpen} onOpenChange={setIsMergeDialogOpen}>
        <DialogContent style={{ maxWidth: '28rem' }}>
          <DialogHeader>
            <DialogTitle>Merge Duplicate Albums</DialogTitle>
            <DialogDescription>
              Choose which album to keep. The other album will be merged into it and then deleted.
            </DialogDescription>
          </DialogHeader>

          <TargetList>
            <TargetHint>Select target album:</TargetHint>
            {group.albums.map((album) => (
              <TargetButton
                key={album.uuid}
                type="button"
                $selected={targetAlbumId === album.uuid}
                onClick={() => setTargetAlbumId(album.uuid)}
              >
                <Thumbnail>
                  {album.coverImage?.publicId ? (
                    <ThumbnailImg src={getCoverImageUrl(album.coverImage.publicId)} alt="" loading="lazy" />
                  ) : (
                    <NoCoverLabel>No Cover</NoCoverLabel>
                  )}
                </Thumbnail>
                <TargetAlbumInfo>
                  <TargetAlbumTitle>{album.title}</TargetAlbumTitle>
                  <TargetAlbumMeta>
                    {album.year && `${album.year} · `}
                    {album.artists && album.artists.map(a => a.name).join(', ')}
                  </TargetAlbumMeta>
                </TargetAlbumInfo>
                <RadioButton $selected={targetAlbumId === album.uuid}>
                  {targetAlbumId === album.uuid && <RadioDot />}
                </RadioButton>
              </TargetButton>
            ))}
          </TargetList>

          <DialogFooter>
            <Button
              variant="ghost"
              onClick={() => setIsMergeDialogOpen(false)}
              disabled={mergeMutation.isPending}
            >
              Cancel
            </Button>
            <Button
              onClick={handleMerge}
              disabled={!targetAlbumId || mergeMutation.isPending}
            >
              {mergeMutation.isPending ? 'Merging...' : 'Merge Albums'}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </Card>
  )
}
