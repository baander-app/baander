import styled from 'styled-components'

interface KVRowProps {
  label: string
  value: React.ReactNode
  muted?: boolean
}

const Row = styled.div`
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 0.625rem 1rem;
  font-size: 0.875rem;
`

const Label = styled.span`
  color: var(--color-muted-foreground);
`

const ValueMono = styled.span`
  font-family: var(--font-mono);
`

const ValueMuted = styled.span`
  color: var(--color-muted-foreground);
`

export function KVRow({ label, value, muted }: KVRowProps) {
  return (
    <Row>
      <Label>{label}</Label>
      {muted ? (
        <ValueMuted>{value}</ValueMuted>
      ) : (
        <ValueMono>{value}</ValueMono>
      )}
    </Row>
  )
}
