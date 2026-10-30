import styled, { css, keyframes } from 'styled-components'

interface StatusDotProps {
  color: 'green' | 'red' | 'amber' | 'blue' | 'gray'
  label?: string
  pulse?: boolean
}

const pulse = keyframes`
  0%, 100% { opacity: 1; }
  50% { opacity: 0.5; }
`

const COLOR_MAP: Record<StatusDotProps['color'], string> = {
  green: 'var(--color-success)',
  red: 'var(--color-destructive)',
  amber: 'var(--color-warning)',
  blue: 'var(--color-info)',
  gray: 'var(--color-muted-foreground)',
}

const Container = styled.span`
  display: flex;
  align-items: center;
  gap: 0.375rem;
`

const Dot = styled.span<{ $color: string; $pulse: boolean }>`
  display: inline-block;
  height: 0.375rem;
  width: 0.375rem;
  border-radius: 9999px;
  background-color: ${props => props.$color};
  @media (prefers-reduced-motion: reduce) { animation: none !important; }
  ${props => props.$pulse && css`animation: ${pulse} 2s cubic-bezier(0.4, 0, 0.6, 1) infinite;`}
`

const Label = styled.span`
  font-size: 13px;
  color: var(--color-muted-foreground);
`

export function StatusDot({ color, label, pulse }: StatusDotProps) {
  return (
    <Container>
      <Dot $color={COLOR_MAP[color]} $pulse={!!pulse} />
      {label && <Label>{label}</Label>}
    </Container>
  )
}
