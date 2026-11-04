import styled from 'styled-components'
import { textRole } from '@/shared/theme'

const Text = styled.p`
  ${textRole('body-sm')}
  color: var(--color-muted-foreground);
`

export function ActiveOperationsFeed() {
  return <Text>No active operations</Text>
}
