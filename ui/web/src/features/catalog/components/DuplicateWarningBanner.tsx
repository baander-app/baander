import styled from 'styled-components'
import { AlertTriangle, X } from 'lucide-react'
import { Button } from '@/shared/components/ui/button'
import { useMergeStore } from '../stores/merge-store'
import type { DuplicateGroup } from '@/features/admin/api/album-duplicates-api'

const Banner = styled.div`
  margin: 1rem;
  margin-top: 1rem;
  display: flex;
  align-items: center;
  justify-content: space-between;
  border-radius: var(--radius-lg);
  border: 1px solid color-mix(in srgb, #f59e0b 30%, transparent);
  background-color: color-mix(in srgb, #f59e0b 10%, transparent);
  padding: 0.75rem 1rem;
`

const InfoArea = styled.div`
  display: flex;
  align-items: center;
  gap: 0.75rem;
`

const AlertIcon = styled(AlertTriangle)`
  color: #f59e0b;
  flex-shrink: 0;
`

const BannerText = styled.div`
  font-size: 0.875rem;
`

const Highlight = styled.span`
  font-weight: 500;
  color: #f59e0b;
`

const Muted = styled.span`
  color: var(--color-muted-foreground);
  margin-left: 0.25rem;
`

const Actions = styled.div`
  display: flex;
  align-items: center;
  gap: 0.5rem;
`

interface DuplicateWarningBannerProps {
  duplicateGroups: DuplicateGroup[]
  albumTitle: string
  albumUuid: string
  onDismiss?: () => void
}

export function DuplicateWarningBanner({
  duplicateGroups,
  albumTitle,
  albumUuid,
  onDismiss,
}: DuplicateWarningBannerProps) {
  const openMerge = useMergeStore((s) => s.openMerge)

  if (duplicateGroups.length === 0) {
    return null
  }

  const totalDuplicates = duplicateGroups.reduce(
    (sum, group) => sum + group.albumIds.filter(id => id !== albumUuid).length,
    0
  )

  const handleMerge = () => {
    const firstGroup = duplicateGroups[0]
    const firstDuplicate = firstGroup.albums.find(a => a.uuid !== albumUuid)

    if (firstDuplicate) {
      openMerge(albumUuid, albumTitle, firstDuplicate.uuid, firstDuplicate.title)
    }
  }

  return (
    <Banner>
      <InfoArea>
        <AlertIcon size={16} strokeWidth={1.5} />
        <BannerText>
          <Highlight>
            {totalDuplicates} potential duplicate{totalDuplicates > 1 ? 's' : ''} found
          </Highlight>
          <Muted>
            for this album. Review and merge if needed.
          </Muted>
        </BannerText>
      </InfoArea>
      <Actions>
        <Button
          variant="ghost"
          size="sm"
          onClick={handleMerge}
          style={{ height: '1.75rem', padding: '0 0.5rem', fontSize: '0.75rem' }}
        >
          Review Duplicates
        </Button>
        {onDismiss && (
          <Button
            variant="ghost"
            size="sm"
            onClick={onDismiss}
            style={{ height: '1.75rem', width: '1.75rem', padding: 0 }}
          >
            <X size={14} strokeWidth={1.5} />
          </Button>
        )}
      </Actions>
    </Banner>
  )
}
