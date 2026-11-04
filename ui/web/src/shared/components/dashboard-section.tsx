import styled from 'styled-components'
import { textRole } from '@/shared/theme'

interface DashboardSectionProps {
  title: string
  children: React.ReactNode
  action?: React.ReactNode
}

const Header = styled.div`
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 0.75rem;
`

const Title = styled.h2`
  ${textRole('subtitle')}
  font-weight: 600;
  color: var(--color-foreground);
`

export function DashboardSection({ title, children, action }: DashboardSectionProps) {
  return (
    <section>
      <Header>
        <Title>{title}</Title>
        {action}
      </Header>
      {children}
    </section>
  )
}
