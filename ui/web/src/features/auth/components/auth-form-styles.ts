import { Link } from 'react-router-dom'
import styled from 'styled-components'
import { Button } from '@/shared/components/ui/button'
import { textRole } from '@/shared/theme'

export const Form = styled.form`
  display: flex;
  flex-direction: column;
  gap: 1rem;
`;

export const ErrorAlert = styled.div`
  border-radius: var(--radius-md);
  background-color: color-mix(in srgb, var(--color-destructive) 10%, transparent);
  padding: 0.75rem;
  ${textRole('body')}
  color: var(--color-destructive);
`;

export const Notice = styled.div`
  border-radius: var(--radius-md);
  background-color: var(--color-secondary);
  padding: 0.75rem;
  ${textRole('body')}
  color: var(--color-foreground);
`;

export const FieldWrapper = styled.div``;

export const Label = styled.label`
  display: block;
  margin-bottom: 0.375rem;
  ${textRole('caption')}
  font-weight: 500;
  color: var(--color-muted-foreground);
`;

export const Hint = styled.p`
  margin-top: 0.375rem;
  ${textRole('caption')}
  color: var(--color-muted-foreground);
`;

export const Intro = styled.p`
  ${textRole('body')}
  color: var(--color-muted-foreground);
`;

export const FullWidthButton = styled(Button)`
  width: 100%;
`;

export const FooterText = styled.p`
  text-align: center;
  ${textRole('caption')}
  color: var(--color-muted-foreground);
`;

export const TextLink = styled(Link)`
  color: var(--color-primary);
  text-decoration: none;

  &:hover {
    text-decoration: underline;
  }
`;
