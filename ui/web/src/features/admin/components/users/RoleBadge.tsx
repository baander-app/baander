import styled from 'styled-components'
import { textRole } from '@/shared/theme'

const Badge = styled.span<{ $bg: string; $text: string }>`
  display: inline-flex;
  align-items: center;
  border-radius: 0.25rem;
  padding: 0.125rem 0.375rem;
  ${textRole('caption')}
  font-size: var(--text-dense-size);
  font-weight: 500;
  background-color: ${({ $bg }) => $bg};
  color: ${({ $text }) => $text};
`

export function RoleBadge({ role }: { role: string }) {
  const variant = role === 'ROLE_SUPER_ADMIN'
    ? { bg: 'var(--color-muted)', text: 'var(--color-warning)' }
    : role === 'ROLE_ADMIN'
      ? { bg: 'var(--color-muted)', text: 'var(--color-info)' }
      : { bg: 'var(--color-secondary)', text: 'var(--color-muted-foreground)' }

  const label = role.replace('ROLE_', '')

  return (
    <Badge $bg={variant.bg} $text={variant.text}>
      {label}
    </Badge>
  )
}
