import styled from 'styled-components'
import { textRole } from '@/shared/theme'

export const InspectorBody = styled.div`
  padding: var(--space-md);
  min-width: 0;
  ${textRole('body-sm')}
  overflow-wrap: anywhere;
`
export const Notice = styled.p`
  padding: var(--space-sm) 0;
  color: var(--color-muted-foreground);
  ${textRole('body-sm')}
`
export const ErrorNotice = styled(Notice)`
  color: var(--color-destructive);
`
export const Actions = styled.div`
  display: flex;
  flex-wrap: wrap;
  gap: var(--space-md);
`
export const DetailGrid = styled.dl`
  display: grid;
  grid-template-columns: minmax(5rem, 1fr) minmax(0, 2fr);
  gap: var(--space-md);
  margin: var(--space-md) 0;
  & dd { margin: 0; white-space: pre-wrap; overflow-wrap: anywhere; }
  & dt { color: var(--color-muted-foreground); }
`
