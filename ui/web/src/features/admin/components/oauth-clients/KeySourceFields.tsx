import styled from 'styled-components'
import { textRole } from '@/shared/theme'
import { Input } from '@/shared/components/ui/input'
import { Textarea } from '@/shared/components/ui/textarea'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/shared/components/ui/select'
import { isKeySourceKind, KEY_SOURCE_KINDS, KEY_SOURCE_LABELS, type KeySourceKind } from './key-source-rules'

const FieldGroup = styled.div`
  display: flex;
  flex-direction: column;
  gap: 0.25rem;
`

const Label = styled.label`
  ${textRole('label')}
  color: var(--color-muted-foreground);
`

const Hint = styled.p`
  ${textRole('caption')}
  color: var(--color-muted-foreground);
`

const MonoTextarea = styled(Textarea)`
  ${textRole('mono-sm')}
`

interface KeySourceFieldsProps {
  idPrefix: string
  kind: KeySourceKind
  value: string
  onKindChange: (kind: KeySourceKind) => void
  onValueChange: (value: string) => void
}

/** The public keys of a private_key_jwt client: a URL where it publishes them, or the key set itself. */
export function KeySourceFields({ idPrefix, kind, value, onKindChange, onValueChange }: KeySourceFieldsProps) {
  const labelId = `${idPrefix}-key-source-label`
  const valueId = `${idPrefix}-key-source-value`

  return (
    <>
      <FieldGroup>
        <Label id={labelId}>Key source</Label>
        <Select value={kind} onValueChange={(next) => {
          if (isKeySourceKind(next)) onKindChange(next)
        }}>
          <SelectTrigger aria-labelledby={labelId}>
            <SelectValue />
          </SelectTrigger>
          <SelectContent>
            {KEY_SOURCE_KINDS.map((option) => (
              <SelectItem key={option} value={option}>{KEY_SOURCE_LABELS[option]}</SelectItem>
            ))}
          </SelectContent>
        </Select>
      </FieldGroup>
      {kind === 'jwksUri' ? (
        <FieldGroup>
          <Label htmlFor={valueId}>Key set URL</Label>
          <Input
            id={valueId}
            value={value}
            onChange={(e) => onValueChange(e.target.value)}
            placeholder="https://app.baander.app/jwks.json"
          />
          <Hint>An https URL on the default port. Bånder fetches the keys from it, so the app can rotate them itself.</Hint>
        </FieldGroup>
      ) : (
        <FieldGroup>
          <Label htmlFor={valueId}>Key set (JWKS)</Label>
          <MonoTextarea
            id={valueId}
            value={value}
            onChange={(e) => onValueChange(e.target.value)}
            placeholder='{"keys": [...]}'
            rows={5}
          />
          <Hint>Up to 10 public keys as a JSON Web Key Set. Give each key a kid when there are several.</Hint>
        </FieldGroup>
      )}
    </>
  )
}
