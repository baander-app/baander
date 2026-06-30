import styled from 'styled-components'

const Wrapper = styled.div`
  display: flex;
  align-items: center;
  gap: 0.5rem;
  font-size: 11px;
  color: var(--color-muted-foreground);
`

const Dot = styled.span`
  display: inline-block;
  height: 0.375rem;
  width: 0.375rem;
  border-radius: 50%;
  background-color: color-mix(in srgb, var(--color-muted-foreground) 30%, transparent);
`

export function LiveHealthBar() {
  return (
    <Wrapper>
      <Dot />
      Health checks unavailable
    </Wrapper>
  )

  // SSE real-time health removed — react-query polling handles freshness.
  // TODO: Restore real-time health via WebSocket when admin WS channel is added.
}
