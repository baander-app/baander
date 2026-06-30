import styled, { css } from 'styled-components'
import { Input } from '@/shared/components/ui/input'
import { Textarea } from '@/shared/components/ui/textarea'
import { Select, SelectTrigger, SelectValue, SelectContent, SelectItem } from '@/shared/components/ui/select'
import type { FieldDef } from './field-config'
import { focusVisibleRing } from '@/shared/theme'

const FieldContainer = styled.div`
  display: flex;
  flex-direction: column;
  gap: 0.125rem;
  padding: 0.375rem 0;
`

const ToggleRow = styled.div`
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 0.375rem 0;
`

const FieldLabel = styled.span`
  font-size: 11px;
  font-weight: 500;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  color: var(--color-muted-foreground);
`

const ToggleSwitch = styled.button<{ $checked: boolean }>`
  position: relative;
  display: inline-flex;
  height: 1.25rem;
  width: 2.25rem;
  flex-shrink: 0;
  cursor: pointer;
  border-radius: 9999px;
  border: 2px solid transparent;
  transition: background-color 200ms ease-in-out, border-color 200ms ease-in-out;
  ${focusVisibleRing}
  padding: 0;
  background: none;

  background-color: ${({ $checked }) =>
    $checked ? 'var(--color-primary)' : 'var(--color-input)'};

  &:disabled {
    cursor: not-allowed;
    opacity: 0.5;
  }
`

const ToggleKnob = styled.span<{ $checked: boolean }>`
  display: inline-block;
  height: 1rem;
  width: 1rem;
  transform: ${({ $checked }) => $checked ? 'translateX(1rem)' : 'translateX(0)'};
  border-radius: 50%;
  background-color: var(--color-background);
  box-shadow: 0 1px 2px 0 rgba(0, 0, 0, 0.05);
  transition: transform 200ms ease-in-out;
  pointer-events: none;
  ring: 0;
`

const DirtyInput = styled(Input)`
  ${({ $isDirty }: { $isDirty: boolean }) =>
    $isDirty &&
    css`border-color: color-mix(in srgb, var(--color-primary) 50%, transparent);`}
`

const ReadOnlyInput = styled(Input)`
  ${({ $isReadOnly }: { $isReadOnly: boolean }) =>
    $isReadOnly &&
    css`
      font-family: var(--font-mono);
      font-size: 0.75rem;
    `}
`

const DirtyTextarea = styled(Textarea)`
  min-height: 5rem;
  font-size: 0.875rem;
`

interface MetadataFieldProps {
  field: FieldDef
  value: unknown
  isLocked: boolean
  isDirty: boolean
  isSaving: boolean
  onChange: (value: unknown) => void
  onToggleLock: () => void
}

export function MetadataField({
  field,
  value,
  isLocked,
  isDirty,
  isSaving,
  onChange,
  onToggleLock: _onToggleLock,
}: MetadataFieldProps) {
  const displayValue = value ?? ''
  const isEditable = !field.readOnly && !isLocked

  if (field.type === 'toggle') {
    return (
      <ToggleRow>
        <FieldLabel>{field.label}</FieldLabel>
        <ToggleSwitch
          type="button"
          role="switch"
          aria-checked={Boolean(value)}
          disabled={field.readOnly || isLocked}
          onClick={() => onChange(!value)}
          $checked={Boolean(value)}
        >
          <ToggleKnob $checked={Boolean(value)} />
        </ToggleSwitch>
      </ToggleRow>
    )
  }

  if (field.type === 'select') {
    return (
      <FieldContainer>
        <FieldLabel>{field.label}</FieldLabel>
        <Select
          value={String(displayValue) || '_empty'}
          onValueChange={(v) => onChange(v === '_empty' ? '' : v)}
          disabled={!isEditable || isSaving}
        >
          <SelectTrigger>
            <SelectValue />
          </SelectTrigger>
          <SelectContent>
            <SelectItem value="_empty">\u2014</SelectItem>
            {field.options?.map((opt) => (
              <SelectItem key={opt.value} value={opt.value}>
                {opt.label}
              </SelectItem>
            ))}
          </SelectContent>
        </Select>
      </FieldContainer>
    )
  }

  if (field.type === 'textarea') {
    return (
      <FieldContainer>
        <FieldLabel>{field.label}</FieldLabel>
        <DirtyTextarea
          value={String(displayValue)}
          placeholder={field.placeholder}
          disabled={!isEditable || isSaving}
          onChange={(e) => onChange(e.target.value)}
        />
      </FieldContainer>
    )
  }

  if (field.type === 'number') {
    return (
      <FieldContainer>
        <FieldLabel>{field.label}</FieldLabel>
        <DirtyInput
          type="number"
          value={displayValue === '' || displayValue === null ? '' : String(displayValue)}
          placeholder={field.placeholder}
          disabled={!isEditable || isSaving}
          onChange={(e) => onChange(e.target.value === '' ? null : Number(e.target.value))}
          $isDirty={isDirty}
        />
      </FieldContainer>
    )
  }

  // Default: text
  return (
    <FieldContainer>
      <FieldLabel>{field.label}</FieldLabel>
      <DirtyInput
        type="text"
        value={String(displayValue)}
        placeholder={field.placeholder}
        disabled={!isEditable || isSaving}
        onChange={(e) => onChange(e.target.value)}
        $isDirty={isDirty}
        $isReadOnly={field.readOnly}
        style={field.readOnly ? { fontFamily: 'var(--font-mono)', fontSize: '0.75rem' } : undefined}
      />
    </FieldContainer>
  )
}
