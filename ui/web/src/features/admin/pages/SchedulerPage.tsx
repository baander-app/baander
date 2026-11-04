import styled from 'styled-components'
import { textRole } from '@/shared/theme'
import { ContentText } from '@/shared/typography'
import { useState } from 'react'
import { useScheduledJobs } from '../hooks/use-scheduler-admin'
import type { ScheduledJob } from '../api/scheduler-admin-api'
import { Button } from '@/shared/components/ui/button'
import { Plus } from 'lucide-react'
import { StatusBadge } from '../components/scheduler/StatusBadge'
import { RowActions } from '../components/scheduler/RowActions'
import { CreateJobDialog } from '../components/scheduler/CreateJobDialog'
import { EditJobDialog } from '../components/scheduler/EditJobDialog'
import { DeleteJobDialog } from '../components/scheduler/DeleteJobDialog'

type ActiveDialog =
  | { type: 'edit'; job: ScheduledJob }
  | { type: 'delete'; job: ScheduledJob }
  | null

const Container = styled.div`
  display: flex;
  flex-direction: column;
  gap: 1rem;
  padding: 1.5rem;
`

const HeaderRow = styled.div`
  display: flex;
  justify-content: flex-end;
`

const DividerStack = styled.div`
  overflow-x: auto;
  & > div { min-width: 48rem; }
  & > div + div {
    border-top: 1px solid var(--color-border);
  }
`

const ColumnHeader = styled.div`
  display: grid;
  grid-template-columns: 1.5fr 110px 1.5fr 90px 120px 120px 40px;
  gap: 0.75rem;
  padding: 0.375rem 0.5rem;
`

const ColLabel = styled.span`
  ${textRole('label', { dense: true })}
  color: var(--color-muted-foreground);
`

const JobRow = styled.div`
  display: grid;
  grid-template-columns: 1.5fr 110px 1.5fr 90px 120px 120px 40px;
  align-items: center;
  gap: 0.75rem;
  padding: 0.5rem;
  ${textRole('body-sm')}
  transition: background-color 100ms ease-out;

  &:hover {
    background: color-mix(in srgb, var(--color-highlight) 20%, transparent);
  }
`

const JobNameGroup = styled.div`
  overflow: hidden;
`

const JobName = styled(ContentText)`
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  font-weight: 500;
  display: block;
`

const JobDesc = styled(ContentText)`
  font-size: calc(var(--text-dense-size) + var(--script-size-boost, 0px));
  color: var(--color-muted-foreground);
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  display: block;
`

const JobMono = styled.span`
  ${textRole('mono-sm')}
  color: var(--color-muted-foreground);
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
`

const JobMonoDark = styled.span`
  ${textRole('mono-sm')}
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
`

const JobDate = styled.span`
  ${textRole('mono-sm', { dense: true })}
  color: var(--color-muted-foreground);
`

const LoadingRow = styled.div`
  height: 2.5rem;
  border-radius: var(--radius-md);
  background: var(--color-muted);
  animation: pulse 2s cubic-bezier(0.4, 0, 0.6, 1) infinite;

  @keyframes pulse {
    0%, 100% { opacity: 1; }
    50% { opacity: 0.5; }
  }
`

const LoadingStack = styled.div`
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
`

const EmptyState = styled.div`
  padding: 3rem 0;
  text-align: center;
  ${textRole('body')}
  color: var(--color-muted-foreground);
`

export function SchedulerPage() {
  const [showCreate, setShowCreate] = useState(false)
  const [activeDialog, setActiveDialog] = useState<ActiveDialog>(null)

  const { data: jobs, isPending: isLoading } = useScheduledJobs()

  const activeJob = activeDialog?.job ?? null

  return (
    <Container>
      {/* Header */}
      <HeaderRow>
        <Button size="sm" onClick={() => setShowCreate(true)}>
          <Plus size={14} /> Create Job
        </Button>
      </HeaderRow>

      {/* Table */}
      {isLoading ? (
        <LoadingStack>
          {Array.from({ length: 5 }).map((_, i) => (
            <LoadingRow key={i} />
          ))}
        </LoadingStack>
      ) : !jobs || jobs.length === 0 ? (
        <EmptyState>No scheduled jobs found. Create one to get started.</EmptyState>
      ) : (
        <DividerStack>
          {/* Column headers */}
          <ColumnHeader>
            <ColLabel>Name</ColLabel>
            <ColLabel>Expression</ColLabel>
            <ColLabel>Command</ColLabel>
            <ColLabel>Status</ColLabel>
            <ColLabel>Last Run</ColLabel>
            <ColLabel>Next Run</ColLabel>
            <span />
          </ColumnHeader>

          {jobs.map((job) => (
            <JobRow key={job.publicId}>
              <JobNameGroup>
                <JobName textRole="body-sm" text={job.name} />
                {job.description && <JobDesc textRole="caption" text={job.description} />}
              </JobNameGroup>
              <JobMono>{job.expression}</JobMono>
              <JobMonoDark>{job.command}</JobMonoDark>
              <StatusBadge status={job.status} />
              <JobDate>{job.lastRunAt ? new Date(job.lastRunAt).toLocaleString() : '—'}</JobDate>
              <JobDate>{job.nextRunAt ? new Date(job.nextRunAt).toLocaleString() : '—'}</JobDate>
              <RowActions
                job={job}
                onEdit={() => setActiveDialog({ type: 'edit', job })}
                onDelete={() => setActiveDialog({ type: 'delete', job })}
              />
            </JobRow>
          ))}
        </DividerStack>
      )}

      {/* Dialogs */}
      <CreateJobDialog open={showCreate} onOpenChange={setShowCreate} />
      <EditJobDialog
        job={activeJob}
        open={activeDialog?.type === 'edit'}
        onOpenChange={(v) => { if (!v) setActiveDialog(null) }}
      />
      <DeleteJobDialog
        job={activeJob}
        open={activeDialog?.type === 'delete'}
        onOpenChange={(v) => { if (!v) setActiveDialog(null) }}
      />
    </Container>
  )
}
