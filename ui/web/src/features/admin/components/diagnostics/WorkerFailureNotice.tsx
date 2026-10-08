import { useId } from 'react'
import styled from 'styled-components'
import type { WorkerError } from '../../api/server-stats-api'

const Notice = styled.section`
  border-radius: var(--radius-md);
  border: 1px solid color-mix(in srgb, var(--color-destructive) 40%, transparent);
  padding: 0.625rem var(--space-md);
  font-size: 0.875rem;
  color: var(--color-destructive);
`

const Title = styled.h3`
  font-weight: 500;
`

const List = styled.ul`
  margin-top: 0.25rem;
  list-style: none;
`

interface WorkerFailureNoticeProps {
  title: string
  missingWorkers: number[]
  workerErrors: WorkerError[]
}

/**
 * Names every worker that did not answer or answered with an error, so a partial
 * snapshot never looks complete.
 */
export function WorkerFailureNotice({ title, missingWorkers, workerErrors }: WorkerFailureNoticeProps) {
  const titleId = useId()

  if (missingWorkers.length === 0 && workerErrors.length === 0) {
    return null
  }

  return (
    <Notice aria-labelledby={titleId}>
      <Title id={titleId}>{title}</Title>
      <List>
        {missingWorkers.map((workerId) => (
          <li key={`missing-${workerId}`}>Worker {workerId} did not answer in time.</li>
        ))}
        {workerErrors.map(({ worker_id: workerId, error }) => (
          <li key={`error-${workerId}`}>Worker {workerId} failed: {error}</li>
        ))}
      </List>
    </Notice>
  )
}
