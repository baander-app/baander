import styled from 'styled-components'

const Text = styled.p`
  font-size: 13px;
  color: var(--color-muted-foreground);
`

export function ActiveOperationsFeed() {
  return <Text>No active operations</Text>
}
