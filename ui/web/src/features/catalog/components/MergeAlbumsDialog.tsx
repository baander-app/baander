import { catalogLiveDescriptors } from '../live-descriptors'
import { playlistLiveDescriptors } from '@/features/playlist/live-descriptors'
import { useLiveCoordinator } from '@/shared/live/context'
import { captureOwnership, assertCurrentOwnership, isCurrentOwnership } from '@/shared/live/ownership'
import styled from 'styled-components'
import { useMergeStore } from '../stores/merge-store'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/shared/components/ui/dialog'
import { Button } from '@/shared/components/ui/button'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { AXIOS_INSTANCE } from '@/shared/api-client/axios-instance'
import { toast } from 'sonner'
import { Loader2 } from 'lucide-react'
import { useShallow } from 'zustand/react/shallow'

const MergeInfo = styled.div`
  padding: 1rem 0;
`

const MergeSteps = styled.div`
  & > * + * {
    margin-top: 0.75rem;
  }
`

const MergeCard = styled.div`
  display: flex;
  align-items: center;
  justify-content: space-between;
  border-radius: var(--radius-lg);
  border: 1px solid var(--color-border);
  padding: 0.75rem;
`

const MergeCardDashed = styled.div`
  display: flex;
  align-items: center;
  justify-content: space-between;
  border-radius: var(--radius-lg);
  border: 1px dashed var(--color-border);
  padding: 0.75rem;
`

const CardTitle = styled.p`
  font-size: 0.875rem;
  font-weight: 500;
`

const CardSubtitle = styled.p`
  font-size: 0.75rem;
  color: var(--color-muted-foreground);
`

const ArrowText = styled.div`
  display: flex;
  justify-content: center;
`

const ArrowSpan = styled.span`
  font-size: 0.75rem;
  color: var(--color-muted-foreground);
`

const LoadingText = styled.p`
  font-size: 0.875rem;
  color: var(--color-muted-foreground);
`

const SpinningIcon = styled(Loader2)`
  margin-right: 0.5rem;
  animation: spin 1s linear infinite;
`

export function MergeAlbumsDialog() {
  const { isOpen, sourcePublicId, sourceTitle, targetPublicId, targetTitle, closeMerge } = useMergeStore(
    useShallow((s) => ({
      isOpen: s.isOpen,
      sourcePublicId: s.sourcePublicId,
      sourceTitle: s.sourceTitle,
      targetPublicId: s.targetPublicId,
      targetTitle: s.targetTitle,
      closeMerge: s.closeMerge,
    }))
  )

  const queryClient = useQueryClient()
  const coordinator = useLiveCoordinator()

  const mergeMutation = useMutation({
    mutationFn: async ({ sourcePublicId, targetPublicId }: { sourcePublicId: string; targetPublicId: string }) => {
      const owner = captureOwnership()
      const release = coordinator?.beginMutation(['catalog', 'playlists'])
      try {
        const result = await AXIOS_INSTANCE.post('/api/albums/merge', { targetPublicId, sourcePublicId })
        assertCurrentOwnership(owner)
        return result
      } finally {
        release?.()
        if (!coordinator && isCurrentOwnership(owner)) {
          await queryClient.invalidateQueries({
            predicate: query => [...catalogLiveDescriptors, ...playlistLiveDescriptors]
              .some(descriptor => descriptor.refreshPolicy !== 'demand' && descriptor.matches(query.queryKey)),
          })
        }
      }
    },
    onSuccess: (_result, submitted) => {
      toast.success('Albums merged successfully')
      const current = useMergeStore.getState()
      if (current.sourcePublicId === submitted.sourcePublicId && current.targetPublicId === submitted.targetPublicId) closeMerge()
    },
    onError: (error: unknown) => {
      toast.error(error instanceof Error ? error.message : 'Failed to merge albums')
    },
  })

  if (!isOpen || !sourcePublicId) {
    return null
  }

  const handleMerge = () => {
    if (!targetPublicId) return
    mergeMutation.mutate({ sourcePublicId, targetPublicId })
  }

  return (
    <Dialog open={isOpen} onOpenChange={(open) => !open && closeMerge()}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>Merge Duplicate Albums</DialogTitle>
          <DialogDescription>
            The source album will be merged into the target album, then deleted.
          </DialogDescription>
        </DialogHeader>

        <MergeInfo>
          {sourceTitle && targetTitle ? (
            <MergeSteps>
              <MergeCard>
                <div>
                  <CardTitle>{targetTitle}</CardTitle>
                  <CardSubtitle>Target (kept)</CardSubtitle>
                </div>
              </MergeCard>
              <ArrowText>
                <ArrowSpan>will be merged into ↓</ArrowSpan>
              </ArrowText>
              <MergeCardDashed>
                <div>
                  <CardTitle>{sourceTitle}</CardTitle>
                  <CardSubtitle>Source (deleted)</CardSubtitle>
                </div>
              </MergeCardDashed>
            </MergeSteps>
          ) : (
            <LoadingText>Loading album information...</LoadingText>
          )}
        </MergeInfo>

        <DialogFooter>
          <Button
            variant="ghost"
            onClick={closeMerge}
            disabled={mergeMutation.isPending}
          >
            Cancel
          </Button>
          <Button
            onClick={handleMerge}
            disabled={!targetPublicId || mergeMutation.isPending}
          >
            {mergeMutation.isPending && <SpinningIcon size={16} />}
            Merge Albums
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}
