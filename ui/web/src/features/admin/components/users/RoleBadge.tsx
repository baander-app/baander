import styled, { css } from 'styled-components'

const Badge = styled.span<{ $bg: string; $text: string }>`
  display: inline-flex;
  align-items: center;
  border-radius: 0.25rem;
  padding: 0.125rem 0.375rem;
  font-size: 11px;
  font-weight: 500;
  letter-spacing: 0.025em;
  background-color: ${({ $bg }) => $bg};
  color: ${({ $text }) => $text};
`

export function RoleBadge({ role }: { role: string }) {
  const variant = role === 'ROLE_SUPER_ADMIN'
    ? { bg: 'rgba(245, 158, 11, 0.15)', text: '#fbbf24' }
    : role === 'ROLE_ADMIN'
      ? { bg: 'rgba(59, 130, 246, 0.15)', text: '#60a5fa' }
      : { bg: 'var(--color-secondary)', text: 'var(--color-muted-foreground)' }

  const label = role.replace('ROLE_', '')

  return (
    <Badge $bg={variant.bg} $text={variant.text}>
      {label}
    </Badge>
  )
}
