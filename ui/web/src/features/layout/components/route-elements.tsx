import styled from 'styled-components'
import { Navigate, useParams } from 'react-router-dom'

const PlaceholderContainer = styled.div`
  display: flex;
  height: 100%;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 0.75rem;
  padding: 5rem 0;
`

const PlaceholderTitle = styled.p`
  font-size: 1.125rem;
  font-weight: 500;
  color: var(--color-muted-foreground);
  margin: 0;
`

const PlaceholderSubtitle = styled.p`
  font-size: 0.875rem;
  color: color-mix(in srgb, var(--color-muted-foreground) 70%, transparent);
  margin: 0;
`

/** Redirect component that forwards route params */
export function ParamRedirect({ to }: { to: string }) {
  const params = useParams()
  let target = to
  Object.entries(params).forEach(([key, value]) => {
    if (value) target = target.replace(`:${key}`, value)
  })
  return <Navigate to={target} replace />
}

/** Placeholder home page for non-music media types */
export function MediaPlaceholder({ title }: { title: string }) {
  return (
    <PlaceholderContainer>
      <PlaceholderTitle>{title}</PlaceholderTitle>
      <PlaceholderSubtitle>Coming soon</PlaceholderSubtitle>
    </PlaceholderContainer>
  )
}
