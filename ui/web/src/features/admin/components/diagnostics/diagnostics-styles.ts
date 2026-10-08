import styled from 'styled-components'
import { TableCell, TableHead } from '@/shared/components/ui/table'
import { Skeleton } from '@/shared/components/ui/skeleton'

export const HeadCell = styled(TableHead)`
  padding: 0 var(--space-md);
  font-size: 0.6875rem;
  font-weight: 500;
  letter-spacing: 0.05em;
  text-transform: uppercase;
  color: var(--color-muted-foreground);
`

export const NumberCell = styled(TableCell)`
  padding: var(--space-sm) var(--space-md);
  font-family: var(--font-mono);
  font-size: 0.8125rem;
`

export const MutedLine = styled.p`
  padding: 0.625rem var(--space-md);
  font-size: 0.875rem;
  color: var(--color-muted-foreground);
`

export const SkeletonBar = styled(Skeleton)`
  display: block;
  height: 1.25rem;
  background-color: var(--color-muted);
`
