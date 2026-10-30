import styled, { type DataAttributes } from 'styled-components';
import { focusVisibleRing, interactiveTransition, ariaInvalidRing } from '@/shared/theme';

export const Input = styled.input.attrs<DataAttributes>({ 'data-slot': 'input' })`
  display: flex;
  width: 100%;
  min-width: 0;
  height: 2rem;
  align-items: center;
  border-radius: ${({ theme }) => theme.radii.md};
  border: 1px solid var(--color-input);
  background: transparent;
  padding: 0.25rem 0.625rem;
  font-size: 0.875rem;
  line-height: 1.4;
  color: var(--color-foreground);
  outline: none;
  ${interactiveTransition(['color', 'background-color', 'border-color', 'box-shadow'])}

  &::placeholder {
    color: var(--color-muted-foreground);
  }

  ${focusVisibleRing}
  ${ariaInvalidRing}

  &:disabled {
    pointer-events: none;
    opacity: 0.5;
  }
`;
