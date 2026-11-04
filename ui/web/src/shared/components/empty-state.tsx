import styled from 'styled-components'
import { textRole } from '@/shared/theme'

interface EmptyStateProps {
  message: string
  icon?: React.ReactNode
}

const Container = styled.div`
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  padding: 3rem 0;
  text-align: center;
`

const IconWrapper = styled.div`
  margin-bottom: 0.75rem;
  color: var(--color-muted-foreground);
`

const Message = styled.p`
  ${textRole('body')}
  color: var(--color-muted-foreground);
`

export function EmptyState({ message, icon }: EmptyStateProps) {
  return (
    <Container>
      {icon && <IconWrapper>{icon}</IconWrapper>}
      <Message>{message}</Message>
    </Container>
  )
}
