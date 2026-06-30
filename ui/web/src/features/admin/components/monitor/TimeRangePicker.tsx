import { useMemo } from 'react'
import styled, { css } from 'styled-components'
import { interactiveTransition } from '@/shared/theme'

interface TimeRangePickerProps {
  from: string
  to: string
  onChange: (from: string, to: string) => void
}

const PRESETS = [
  { label: '1h', durationMs: 60 * 60 * 1000 },
  { label: '6h', durationMs: 6 * 60 * 60 * 1000 },
  { label: '24h', durationMs: 24 * 60 * 60 * 1000 },
  { label: '7d', durationMs: 7 * 24 * 60 * 60 * 1000 },
  { label: '30d', durationMs: 30 * 24 * 60 * 60 * 1000 },
]

function nowIso(): string {
  return new Date().toISOString()
}

function isActivePreset(from: string, to: string, durationMs: number): boolean {
  const expectedFrom = new Date(Date.now() - durationMs).toISOString()
  const fromTime = new Date(from).getTime()
  const expectedTime = new Date(expectedFrom).getTime()
  const toTime = new Date(to).getTime()
  const nowTime = new Date(nowIso()).getTime()
  return Math.abs(fromTime - expectedTime) < 60_000 && Math.abs(toTime - nowTime) < 60_000
}

const Wrapper = styled.div`
  display: flex;
  align-items: center;
  gap: 0.25rem;
  border-radius: var(--radius-lg, 0.5rem);
  background-color: var(--color-muted);
  padding: 0.25rem;
`

const PresetButton = styled.button<{ $active: boolean }>`
  border-radius: 0.375rem;
  padding: 0.375rem 0.75rem;
  font-size: 0.875rem;
  font-weight: 500;
  ${interactiveTransition(['color', 'background-color'])}

  ${({ $active }) => $active
    ? css`
        background-color: var(--color-background);
        color: var(--color-foreground);
        box-shadow: 0 1px 2px rgba(0, 0, 0, 0.05);
      `
    : css`
        color: var(--color-muted-foreground);
        &:hover { color: var(--color-foreground); }
      `
  }
`

export function TimeRangePicker({ from, to, onChange }: TimeRangePickerProps) {
  const activePresetIndex = useMemo(() => {
    return PRESETS.findIndex((p) => isActivePreset(from, to, p.durationMs))
  }, [from, to])

  return (
    <Wrapper>
      {PRESETS.map((preset, i) => (
        <PresetButton
          key={preset.label}
          type="button"
          $active={i === activePresetIndex}
          onClick={() => {
            const newFrom = new Date(Date.now() - preset.durationMs).toISOString()
            onChange(newFrom, nowIso())
          }}
        >
          {preset.label}
        </PresetButton>
      ))}
    </Wrapper>
  )
}
