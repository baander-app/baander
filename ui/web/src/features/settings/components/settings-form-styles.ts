import styled from 'styled-components'
import { Loader2 } from 'lucide-react'
import { textRole } from '@/shared/theme'

export const Form = styled.form`
  display: flex;
  flex-direction: column;
  gap: 1rem;
`

export const ErrorBox = styled.div`
  border-radius: var(--radius-md);
  background-color: var(--color-muted);
  padding: 0.75rem;
  ${textRole('body')}
  color: var(--color-destructive);
`

export const FieldLabel = styled.label`
  display: block;
  margin-bottom: 0.375rem;
  ${textRole('caption')}
  font-weight: 500;
  color: var(--color-muted-foreground);
`

export const SpinningIconWithMargin = styled(Loader2)`
  margin-right: 0.25rem;
  animation: spin 1s linear infinite;

  @keyframes spin {
    from { transform: rotate(0deg); }
    to { transform: rotate(360deg); }
  }
`
