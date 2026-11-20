import styled from 'styled-components'
import { textRole } from '@/shared/theme'

export const NoticePanel = styled.div`
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
  border-radius: var(--radius-md);
  background-color: var(--color-secondary);
  padding: 0.5rem 0.75rem;
`

export const NoticeStatus = styled.p`
  ${textRole('label')}
  color: var(--color-muted-foreground);
`

export const NoticeMessage = styled.p`
  ${textRole('body')}
  color: var(--color-muted-foreground);
`
