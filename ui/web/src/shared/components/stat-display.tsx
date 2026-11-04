import styled, { keyframes } from 'styled-components'
import { textRole } from '@/shared/theme'

interface StatDisplayProps {
  label: string
  value: string | number
}

const Container = styled.div``

const Label = styled.div`
  ${textRole('label')}
  color: var(--color-muted-foreground);
`

const Value = styled.div`
  margin-top: 0.25rem;
  ${textRole('title')}
  font-variant-numeric: tabular-nums;
`

const pulse = keyframes`
  0%, 100% { opacity: 1; }
  50% { opacity: 0.5; }
`

const SkeletonBlock = styled.div`
  animation: ${pulse} 2s cubic-bezier(0.4, 0, 0.6, 1) infinite;
  background-color: var(--color-muted);
  border-radius: var(--radius-md);
`

export function StatDisplay({ label, value }: StatDisplayProps) {
  return (
    <Container>
      <Label>{label}</Label>
      <Value>{value}</Value>
    </Container>
  )
}

export function StatDisplaySkeleton() {
  return (
    <Container>
      <SkeletonBlock style={{ height: '0.75rem', width: '5rem' }} />
      <SkeletonBlock style={{ marginTop: '0.5rem', height: '1.75rem', width: '4rem' }} />
    </Container>
  )
}
