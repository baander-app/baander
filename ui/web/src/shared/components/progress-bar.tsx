import styled from 'styled-components'
import type { CSSProperties } from 'react'

interface ProgressBarProps {
  value: number
  label?: string
  className?: string
  color?: string
  size?: 'sm' | 'md'
  style?: CSSProperties
}

const Container = styled.div``

const LabelRow = styled.div`
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 0.25rem;
  font-size: 11px;
  color: var(--color-muted-foreground);
`

const PercentLabel = styled.span`
  font-variant-numeric: tabular-nums;
`

const Track = styled.div<{ $height: string }>`
  width: 100%;
  border-radius: 9999px;
  background-color: var(--color-muted);
  height: ${props => props.$height};
`

const Fill = styled.div<{ $height: string; $color: string }>`
  border-radius: 9999px;
  background-color: ${props => props.$color};
  height: ${props => props.$height};
  transition: all 0.15s ease;
`

export function ProgressBar({
  value,
  label,
  className,
  color = 'var(--color-primary)',
  size = 'sm',
  style,
}: ProgressBarProps) {
  const clamped = Math.max(0, Math.min(100, value))
  const height = size === 'sm' ? '0.25rem' : '0.5rem'

  return (
    <Container className={className} style={style}>
      {label && (
        <LabelRow>
          <span>{label}</span>
          <PercentLabel>{Math.round(clamped)}%</PercentLabel>
        </LabelRow>
      )}
      <Track $height={height}>
        <Fill $height={height} $color={color} style={{ width: `${clamped}%` }} />
      </Track>
    </Container>
  )
}
