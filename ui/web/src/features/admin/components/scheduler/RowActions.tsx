import type { ScheduledJob } from '../../api/scheduler-admin-api'
import {
  usePauseScheduledJob,
  useResumeScheduledJob,
  useTriggerScheduledJob,
  useEnableScheduledJob,
  useDisableScheduledJob,
} from '../../hooks/use-scheduler-admin'
import { Button } from '@/shared/components/ui/button'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from '@/shared/components/ui/dropdown-menu'
import { MoreHorizontal, Play, Pause, Trash2, Zap, Ban, CheckCircle } from 'lucide-react'

export function RowActions({
  job,
  onEdit,
  onDelete,
}: {
  job: ScheduledJob
  onEdit: () => void
  onDelete: () => void
}) {
  const pauseJob = usePauseScheduledJob()
  const resumeJob = useResumeScheduledJob()
  const enableJob = useEnableScheduledJob()
  const disableJob = useDisableScheduledJob()
  const triggerJob = useTriggerScheduledJob()

  return (
    <DropdownMenu>
      <DropdownMenuTrigger asChild>
        <Button variant="ghost" size="icon-xs">
          <MoreHorizontal size={14} />
        </Button>
      </DropdownMenuTrigger>
      <DropdownMenuContent align="end">
        <DropdownMenuItem onSelect={onEdit}>
          Edit
        </DropdownMenuItem>
        {job.status === 'active' && (
          <DropdownMenuItem onSelect={() => pauseJob.mutate(job.id)}>
            <Pause size={14} /> Pause
          </DropdownMenuItem>
        )}
        {job.status === 'paused' && (
          <DropdownMenuItem onSelect={() => resumeJob.mutate(job.id)}>
            <Play size={14} /> Resume
          </DropdownMenuItem>
        )}
        {(job.status === 'active' || job.status === 'paused') && (
          <DropdownMenuItem onSelect={() => disableJob.mutate(job.id)}>
            <Ban size={14} /> Disable
          </DropdownMenuItem>
        )}
        {job.status === 'disabled' && (
          <DropdownMenuItem onSelect={() => enableJob.mutate(job.id)}>
            <CheckCircle size={14} /> Enable
          </DropdownMenuItem>
        )}
        <DropdownMenuItem onSelect={() => triggerJob.mutate(job.id)}>
          <Zap size={14} /> Trigger Now
        </DropdownMenuItem>
        <DropdownMenuSeparator />
        <DropdownMenuItem variant="destructive" onSelect={onDelete}>
          <Trash2 size={14} /> Delete
        </DropdownMenuItem>
      </DropdownMenuContent>
    </DropdownMenu>
  )
}
