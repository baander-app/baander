import styled from 'styled-components'

interface HorizontalScrollRowProps {
  children: React.ReactNode
}

const ScrollContainer = styled.div`
  display: flex;
  gap: 0.75rem;
  overflow-x: auto;
  padding-bottom: 0.5rem;
  scrollbar-width: none;
  -ms-overflow-style: none;

  &::-webkit-scrollbar {
    display: none;
  }
`

export function HorizontalScrollRow({ children }: HorizontalScrollRowProps) {
  return (
    <ScrollContainer>
      {children}
    </ScrollContainer>
  )
}
