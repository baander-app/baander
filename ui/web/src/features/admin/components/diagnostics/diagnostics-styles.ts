import styled from 'styled-components'
import { textRole } from '@/shared/theme'
import { TableCell, TableHead } from '@/shared/components/ui/table'
import { Skeleton } from '@/shared/components/ui/skeleton'

export const HeadCell = styled(TableHead)`
  padding: 0 var(--space-md);
  ${textRole('label')}
  font-size: var(--text-dense-size);
  color: var(--color-muted-foreground);
`

export const NumberCell = styled(TableCell)`
  padding: var(--space-sm) var(--space-md);
  ${textRole('mono-sm')}
`

export const MutedLine = styled.p`
  padding: 0.625rem var(--space-md);
  ${textRole('body')}
  color: var(--color-muted-foreground);
`

export const SkeletonBar = styled(Skeleton)`
  display: block;
  height: 1.25rem;
  background-color: var(--color-muted);
`
