import { useState, useEffect } from 'react'
import styled, { css } from 'styled-components'
import { Input } from '@/shared/components/ui/input'
import { interactiveTransition } from '@/shared/theme'

// --- Presets ---

interface Preset {
  label: string
  expression: string
  description: string
}

const PRESETS: Preset[] = [
  { label: 'Every minute', expression: '* * * * *', description: 'Runs every minute' },
  { label: 'Every 5 min', expression: '*/5 * * * *', description: 'Runs every 5 minutes' },
  { label: 'Every 15 min', expression: '*/15 * * * *', description: 'Runs every 15 minutes' },
  { label: 'Every 30 min', expression: '*/30 * * * *', description: 'Runs every 30 minutes' },
  { label: 'Hourly', expression: '0 * * * *', description: 'Runs at the start of every hour' },
  { label: 'Daily midnight', expression: '0 0 * * *', description: 'Runs daily at 00:00' },
  { label: 'Daily 06:00', expression: '0 6 * * *', description: 'Runs daily at 06:00' },
  { label: 'Weekly Mon', expression: '0 0 * * 1', description: 'Runs every Monday at 00:00' },
  { label: 'Monthly 1st', expression: '0 0 1 * *', description: 'Runs on the 1st of every month at 00:00' },
]

const FIELD_LABELS = ['min', 'hour', 'day', 'month', 'dow'] as const

function parseExpression(expr: string): string[] {
  const parts = expr.trim().split(/\s+/)
  if (parts.length !== 5) return ['*', '*', '*', '*', '*']
  return parts
}

function describeExpression(expr: string): string {
  const parts = parseExpression(expr)
  const [min, hour, dom, month, dow] = parts

  const preset = PRESETS.find((p) => p.expression === expr)
  if (preset) return preset.description

  const intervalMatch = (field: string, unit: string): string | null => {
    const m = field.match(/^\*\/(\d+)$/)
    return m ? `every ${m[1]} ${unit}` : null
  }

  if (min.startsWith('*/') && hour === '*' && dom === '*' && month === '*' && dow === '*') {
    return intervalMatch(min, 'minutes') ?? expr ?? expr
  }

  if (min === '0' && hour.startsWith('*/') && dom === '*' && month === '*' && dow === '*') {
    return intervalMatch(hour, 'hours') ?? expr ?? expr
  }

  if (dom === '*' && month === '*' && dow === '*' && !min.includes('*') && !min.includes('/') && !hour.includes('*') && !hour.includes('/')) {
    const h = hour.padStart(2, '0')
    const m = min.padStart(2, '0')
    return `Runs daily at ${h}:${m}`
  }

  const dowNames = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday']
  if (dom === '*' && month === '*' && dow !== '*' && !hour.includes('*') && !hour.includes('/')) {
    const h = hour.padStart(2, '0')
    const m = min.padStart(2, '0')
    const dowNum = parseInt(dow, 10)
    const dayName = dowNames[dowNum] ?? dow
    return `Runs every ${dayName} at ${h}:${m}`
  }

  if (dom !== '*' && month === '*' && dow === '*' && !hour.includes('*') && !hour.includes('/')) {
    const h = hour.padStart(2, '0')
    const m = min.padStart(2, '0')
    return `Runs on day ${dom} of every month at ${h}:${m}`
  }

  return expr
}

// --- Styled Components ---

const Wrapper = styled.div`
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
`

const ModeToggle = styled.div`
  display: flex;
  gap: 0.25rem;
`

const ModeButton = styled.button<{ $active: boolean }>`
  border-radius: 0.25rem;
  padding: 0.125rem 0.5rem;
  font-size: 11px;
  font-weight: 500;
  ${interactiveTransition(['color', 'background-color'])}

  ${({ $active }) => $active
    ? css`
        background-color: color-mix(in srgb, var(--color-primary) 10%, transparent);
        color: var(--color-primary);
      `
    : css`
        color: var(--color-muted-foreground);
        &:hover { color: var(--color-foreground); }
      `
  }
`

const PresetGrid = styled.div`
  display: flex;
  flex-wrap: wrap;
  gap: 0.375rem;
`

const PresetButton = styled.button<{ $active: boolean }>`
  border-radius: 0.25rem;
  border: 1px solid;
  padding: 0.25rem 0.5rem;
  font-size: 11px;
  font-family: monospace;
  ${interactiveTransition(['color', 'background-color', 'border-color'])}

  ${({ $active }) => $active
    ? css`
        border-color: color-mix(in srgb, var(--color-primary) 40%, transparent);
        background-color: color-mix(in srgb, var(--color-primary) 10%, transparent);
        color: var(--color-primary);
      `
    : css`
        border-color: var(--color-border);
        background-color: var(--color-card);
        color: var(--color-muted-foreground);
        &:hover {
          border-color: color-mix(in srgb, var(--color-primary) 30%, transparent);
          color: var(--color-foreground);
        }
      `
  }
`

const CustomFields = styled.div`
  display: flex;
  gap: 0.375rem;
`

const FieldWrapper = styled.div`
  flex: 1;
`

const FieldLabel = styled.span`
  display: block;
  text-align: center;
  font-size: 10px;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  color: var(--color-muted-foreground);
  margin-bottom: 0.25rem;
`

const FieldInput = styled(Input)`
  height: 1.75rem;
  text-align: center;
  font-family: monospace;
  font-size: 12px;
`

const ResultRow = styled.div`
  display: flex;
  align-items: center;
  gap: 0.5rem;
`

const Code = styled.code`
  border-radius: 0.25rem;
  background-color: var(--color-muted);
  padding: 0.125rem 0.375rem;
  font-family: monospace;
  font-size: 12px;
  color: var(--color-foreground);
`

const Description = styled.span`
  font-size: 11px;
  color: var(--color-muted-foreground);
`

// --- Component ---

interface CronExpressionInputProps {
  value: string
  onChange: (expression: string) => void
}

export function CronExpressionInput({ value, onChange }: CronExpressionInputProps) {
  const [mode, setMode] = useState<'preset' | 'custom'>('preset')
  const [fields, setFields] = useState<string[]>(() => parseExpression(value))

  const matchedPreset = PRESETS.find((p) => p.expression === value)

  useEffect(() => {
    setFields(parseExpression(value))
  }, [value])

  const handlePresetSelect = (preset: Preset) => {
    onChange(preset.expression)
    setFields(parseExpression(preset.expression))
    setMode('preset')
  }

  const handleFieldChange = (index: number, raw: string) => {
    const next = [...fields]
    next[index] = raw || '*'
    setFields(next)
    onChange(next.join(' '))
  }

  const description = describeExpression(value)
  const isPreset = matchedPreset !== undefined

  return (
    <Wrapper>
      <ModeToggle>
        <ModeButton type="button" $active={mode === 'preset'} onClick={() => setMode('preset')}>
          Presets
        </ModeButton>
        <ModeButton type="button" $active={mode === 'custom'} onClick={() => setMode('custom')}>
          Custom
        </ModeButton>
      </ModeToggle>

      {mode === 'preset' && (
        <PresetGrid>
          {PRESETS.map((preset) => (
            <PresetButton
              key={preset.expression}
              type="button"
              $active={value === preset.expression}
              onClick={() => handlePresetSelect(preset)}
              title={preset.description}
            >
              {preset.label}
            </PresetButton>
          ))}
        </PresetGrid>
      )}

      {mode === 'custom' && (
        <CustomFields>
          {FIELD_LABELS.map((label, i) => (
            <FieldWrapper key={label}>
              <FieldLabel>{label}</FieldLabel>
              <FieldInput
                value={fields[i]}
                onChange={(e) => handleFieldChange(i, e.target.value)}
                placeholder="*"
              />
            </FieldWrapper>
          ))}
        </CustomFields>
      )}

      <ResultRow>
        <Code>{value || '* * * * *'}</Code>
        {description && !isPreset && <Description>{description}</Description>}
        {isPreset && <Description>{matchedPreset.description}</Description>}
      </ResultRow>
    </Wrapper>
  )
}
