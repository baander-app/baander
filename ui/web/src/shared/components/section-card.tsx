import styled from 'styled-components'
import { textRole } from '@/shared/theme'

interface SectionCardProps {
  title: string
  children: React.ReactNode
  action?: React.ReactNode
}

const Card = styled.div`
  border-radius: var(--radius-md);
  border: 1px solid var(--color-border);
  background-color: var(--color-card);
`

const CardHeader = styled.div`
  display: flex;
  align-items: center;
  justify-content: space-between;
  border-bottom: 1px solid var(--color-border);
  padding: 0.75rem 1rem;
`

const CardTitle = styled.h3`
  ${textRole('subtitle')}
`

const CardBody = styled.div`
  & > * + * {
    border-top: 1px solid var(--color-border);
  }
`

export function SectionCard({ title, children, action }: SectionCardProps) {
  return (
    <Card>
      <CardHeader>
        <CardTitle>{title}</CardTitle>
        {action}
      </CardHeader>
      <CardBody>{children}</CardBody>
    </Card>
  )
}
