import styled from 'styled-components'
import type { ScheduledJob } from '../../api/scheduler-admin-api'
import { useDeleteScheduledJob } from '../../hooks/use-scheduler-admin'
import { Button } from '@/shared/components/ui/button'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/shared/components/ui/dialog'

const JobName = styled.span`
  font-family: monospace;
  color: var(--color-foreground);
`

export function DeleteJobDialog({ job, open, onOpenChange }: { job: ScheduledJob | null; open: boolean; onOpenChange: (v: boolean) => void }) {
  const deleteJob = useDeleteScheduledJob()

  if (!job) return null

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent style={{ maxWidth: '28rem' }}>
        <DialogHeader>
          <DialogTitle>Delete Scheduled Job</DialogTitle>
          <DialogDescription>
            Permanently delete <JobName>{job.name}</JobName>? This cannot be undone.
          </DialogDescription>
        </DialogHeader>
        <DialogFooter>
          <Button variant="outline" onClick={() => onOpenChange(false)}>Cancel</Button>
          <Button
            variant="destructive"
            disabled={deleteJob.isPending}
            onClick={() => deleteJob.mutate(job.id, { onSuccess: () => onOpenChange(false) })}
          >
            {deleteJob.isPending ? 'Deleting...' : 'Delete'}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}
