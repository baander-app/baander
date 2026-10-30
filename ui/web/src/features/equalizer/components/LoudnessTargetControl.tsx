import { useId, useState } from 'react'
import styled from 'styled-components'
import { Input } from '@/shared/components/ui/input'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/shared/components/ui/select'
import { isLoudnessTarget, LOUDNESS_PRESETS } from '../loudness-targets'

type LoudnessTargetControlProps = {
  value: number
  onChange: (value: number) => void
  disabled?: boolean
}

const Fields = styled.div`
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
  min-width: 0;
  width: 100%;
  max-width: 20rem;
`

const FieldLabel = styled.label`
  font-size: 0.875rem;
  font-weight: 500;
`

const Description = styled.p`
  margin: 0;
  font-size: 0.75rem;
  color: var(--color-muted-foreground);
`

const ErrorMessage = styled(Description)`
  color: var(--color-destructive);
`

export function LoudnessTargetControl({ value, onChange, disabled = false }: LoudnessTargetControlProps) {
  const id = useId()
  const preset = LOUDNESS_PRESETS.find((entry) => entry.value === value)
  const [editing, setEditing] = useState({ value, custom: !preset, draft: String(value), error: false })

  if (editing.value !== value) {
    setEditing({ value, custom: !preset, draft: String(value), error: false })
  }

  const commit = () => {
    const next = Number(editing.draft)
    if (editing.draft.trim() === '' || !isLoudnessTarget(next)) {
      setEditing({ ...editing, error: true })
      return
    }
    setEditing({ value: next, custom: true, draft: String(next), error: false })
    if (next !== value) onChange(next)
  }

  return (
    <Fields>
      <FieldLabel htmlFor={`${id}-preset`}>Loudness target</FieldLabel>
      <Select
        value={editing.custom ? 'custom' : String(value)}
        disabled={disabled}
        onValueChange={(selected) => {
          if (selected === 'custom') {
            setEditing({ value, custom: true, draft: String(value), error: false })
          } else {
            const next = Number(selected)
            setEditing({ value: next, custom: false, draft: String(next), error: false })
            if (next !== value) onChange(next)
          }
        }}
      >
        <SelectTrigger id={`${id}-preset`} aria-describedby={`${id}-hint`} style={{ width: '100%' }}>
          <SelectValue />
        </SelectTrigger>
        <SelectContent>
          {LOUDNESS_PRESETS.map(({ name, value: target }) => (
            <SelectItem key={target} value={String(target)}>{name} (−{Math.abs(target)} LUFS)</SelectItem>
          ))}
          <SelectItem value="custom">Custom</SelectItem>
        </SelectContent>
      </Select>
      {editing.custom && (
        <>
          <FieldLabel htmlFor={`${id}-custom`}>Custom target (LUFS)</FieldLabel>
          <Input
            id={`${id}-custom`}
            type="text"
            inputMode="decimal"
            value={editing.draft}
            disabled={disabled}
            aria-invalid={editing.error || undefined}
            aria-describedby={`${id}-hint${editing.error ? ` ${id}-error` : ''}`}
            onChange={(event) => setEditing({ ...editing, draft: event.target.value, error: false })}
            onBlur={commit}
            onKeyDown={(event) => {
              if (event.key === 'Enter') {
                event.preventDefault()
                commit()
              }
            }}
          />
          {editing.error && <ErrorMessage id={`${id}-error`} role="alert">Enter a finite number in LUFS.</ErrorMessage>}
        </>
      )}
      <Description id={`${id}-hint`}>Playback gain limits still apply, so some recordings may not reach this target.</Description>
    </Fields>
  )
}
