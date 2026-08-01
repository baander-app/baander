import styled from 'styled-components'
import { Link } from 'react-router-dom'
import { ArrowRight } from 'lucide-react'
import { interactiveTransition } from '@/shared/theme'

const Card = styled(Link)`
  display: block;
  border-radius: var(--radius-lg, 0.5rem);
  border: 1px solid var(--color-border);
  background-color: var(--color-card);
  padding: 1rem;
  ${interactiveTransition(['border-color', 'background-color'])}

  &:hover {
    border-color: color-mix(in srgb, var(--color-border) 80%, transparent);
    background-color: color-mix(in srgb, var(--color-accent) 20%, transparent);
  }
`

const TopRow = styled.div`
  display: flex;
  align-items: center;
  gap: 0.5rem;
`

const IconWrapper = styled.span`
  color: var(--color-muted-foreground);
`

const LabelText = styled.span`
  font-size: 0.875rem;
  font-weight: 500;
`

const Arrow = styled(ArrowRight)`
  margin-left: auto;
  color: transparent;
  transition: color 0.15s;

  ${Card}:hover & {
    color: var(--color-muted-foreground);
  }
`

const Description = styled.p`
  margin-top: 0.25rem;
  font-size: 0.75rem;
  color: var(--color-muted-foreground);
`

export function AdminQuickLink({
  to,
  icon: Icon,
  label,
  description,
}: {
  to: string
  icon: React.ComponentType<{ size?: number; strokeWidth?: number; className?: string }>
  label: string
  description: string
}) {
  return (
    <Card to={to}>
      <TopRow>
        <IconWrapper>
          <Icon size={15} strokeWidth={1.5} />
        </IconWrapper>
        <LabelText>{label}</LabelText>
        <Arrow size={14} />
      </TopRow>
      <Description>{description}</Description>
    </Card>
  )
}
