import styled from 'styled-components'
import { EqualizerPanel } from '../components/EqualizerPanel'

const PageWrapper = styled.div`
  flex: 1;
  overflow-y: auto;
  padding: 1.5rem;
`

export function EqualizerPage() {
  return (
    <PageWrapper>
      <EqualizerPanel />
    </PageWrapper>
  )
}
