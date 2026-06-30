import styled, { css, keyframes } from 'styled-components'
import { Link } from 'react-router-dom'

interface StatCardProps {
  label: string
  value: React.ReactNode
  sub?: string
  icon?: React.ComponentType<{ size?: number; strokeWidth?: number }>
  to?: string
  ok?: boolean
}

const Card = styled.div<{ $interactive: boolean }>`
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
  border-radius: var(--radius-lg);
  border: 1px solid var(--color-border);
  background-color: var(--color-card);
  padding: 1rem;

  ${props => props.$interactive && css`
    transition: background-color 0.15s ease;
    &:hover { background-color: color-mix(in srgb, var(--color-accent) 30%, transparent); }
  `}
`

const CardHeader = styled.div`
  display: flex;
  align-items: center;
  justify-content: space-between;
`

const LabelGroup = styled.div`
  display: flex;
  align-items: center;
  gap: 0.5rem;
  color: var(--color-muted-foreground);
`

const Label = styled.span`
  font-size: 11px;
  font-weight: 500;
  text-transform: uppercase;
  letter-spacing: 0.05em;
`

const ValueGroup = styled.div`
  display: flex;
  align-items: baseline;
  gap: 0.5rem;
`

const Value = styled.span`
  font-size: 1.25rem;
  font-weight: 600;
  font-variant-numeric: tabular-nums;
`

const dotColors = css<{ $ok: boolean }>`
  background-color: ${props => props.$ok ? '#10b981' : '#ef4444'};
`

const StatusDot = styled.span<{ $ok: boolean }>`
  display: inline-block;
  height: 0.375rem;
  width: 0.375rem;
  border-radius: 9999px;
  ${dotColors}
`

const Sub = styled.span`
  font-size: 11px;
  color: var(--color-muted-foreground);
`

const StyledLink = styled(Link)`
  text-decoration: none;
  color: inherit;
`

export function StatCard({ label, value, sub, icon: Icon, to, ok }: StatCardProps) {
  const content = (
    <Card $interactive={!!to}>
      <CardHeader>
        <LabelGroup>
          {Icon && <Icon size={14} strokeWidth={1.5} />}
          <Label>{label}</Label>
        </LabelGroup>
      </CardHeader>
      <ValueGroup>
        <Value>{value}</Value>
        {ok !== undefined && <StatusDot $ok={ok} />}
      </ValueGroup>
      {sub && <Sub>{sub}</Sub>}
    </Card>
  )

  if (to) {
    return <StyledLink to={to}>{content}</StyledLink>
  }

  return content
}
