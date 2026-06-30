import styled, { css } from 'styled-components'
import { Lock } from 'lucide-react'
import type { FieldDef } from './field-config'
import { interactiveTransition } from '@/shared/theme'

const Container = styled.div`
  display: flex;
  flex-direction: column;
  gap: 0.25rem;
  padding: 0.5rem 0;
`

const Header = styled.div`
  display: flex;
  align-items: center;
  gap: 0.375rem;
`

const HeaderIcon = styled(Lock)`
  color: var(--color-muted-foreground);
`

const HeaderLabel = styled.span`
  font-size: 11px;
  font-weight: 500;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  color: var(--color-muted-foreground);
`

const Description = styled.p`
  font-size: 0.75rem;
  color: color-mix(in srgb, var(--color-muted-foreground) 70%, transparent);
  margin-bottom: 0.25rem;
`

const LockButton = styled.button<{ $isLocked: boolean }>`
  display: flex;
  align-items: center;
  justify-content: between;
  border-radius: var(--radius-md);
  padding: 0.375rem 0.5rem;
  font-size: 0.875rem;
  ${interactiveTransition(['color', 'background-color'])}
  background: none;
  border: none;
  cursor: pointer;
  width: 100%;
  text-align: left;

  &:hover {
    background-color: color-mix(in srgb, var(--color-accent) 50%, transparent);
  }

  ${({ $isLocked }) =>
    $isLocked &&
    css`
      background-color: color-mix(in srgb, var(--color-accent) 30%, transparent);
    `}
`

const FieldLabel = styled.span`
  color: var(--color-foreground);
  flex: 1;
`

const LockStatus = styled.span<{ $isLocked: boolean }>`
  font-size: 0.75rem;

  ${({ $isLocked }) =>
    $isLocked
      ? css`
        color: var(--color-primary);
        font-weight: 500;
      `
      : css`
        color: var(--color-muted-foreground);
      `}
`

interface ProtectionSectionProps {
  fields: FieldDef[]
  lockedFields: string[]
  onToggleLock: (field: string) => void
}

export function ProtectionSection({ fields, lockedFields, onToggleLock }: ProtectionSectionProps) {
  const lockableFields = fields.filter((f) => f.lockable)

  if (lockableFields.length === 0) return null

  return (
    <Container>
      <Header>
        <HeaderIcon size={10} />
        <HeaderLabel>Protected Fields</HeaderLabel>
      </Header>
      <Description>Protected fields won&apos;t change during sync</Description>
      {lockableFields.map((field) => {
        const isLocked = lockedFields.includes(field.key)
        return (
          <LockButton
            key={field.key}
            type="button"
            onClick={() => onToggleLock(field.key)}
            $isLocked={isLocked}
          >
            <FieldLabel>{field.label}</FieldLabel>
            <LockStatus $isLocked={isLocked}>
              {isLocked ? 'Protected' : 'Open'}
            </LockStatus>
          </LockButton>
        )
      })}
    </Container>
  )
}
