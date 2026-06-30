import styled, { css } from 'styled-components';
import { focusVisibleRing, interactiveTransition } from '@/shared/theme';

export const Input = styled.input.attrs({ 'data-slot': 'input' })`
  display: flex;
  width: 100%;
  height: 2rem;
  align-items: center;
  border-radius: ${({ theme }) => theme.radii.lg};
  border: 1px solid var(--color-input);
  background: transparent;
  padding: 0.375rem 0.625rem;
  font-size: 0.875rem;
  line-height: 1.6;
  color: var(--color-foreground);
  outline: none;
  ${interactiveTransition(['color', 'background-color', 'border-color', 'box-shadow'])}

  &::placeholder {
    color: var(--color-muted-foreground);
  }

  ${focusVisibleRing}

  &:disabled {
    pointer-events: none;
    opacity: 0.5;
  }
`;
