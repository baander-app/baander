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

  const mergeMutation = useMutation({
    mutationFn: ({ sourcePublicId, targetPublicId }: { sourcePublicId: string; targetPublicId: string }) =>
      AXIOS_INSTANCE.post('/api/albums/merge', {
        targetPublicId,
        sourcePublicId,
      }),
    onSuccess: () => {
      toast.success('Albums merged successfully')
      // A merge restructures album data across index/show/duplicates views with
      // inconsistent query-key shapes, so invalidate all and let active queries refetch
      // rather than destroying SPA state with a full reload.
      queryClient.invalidateQueries()
      closeMerge()
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
