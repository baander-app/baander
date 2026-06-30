import styled from 'styled-components';
import * as SeparatorPrimitive from '@radix-ui/react-separator';

export const Separator = styled(SeparatorPrimitive.Root).attrs({ 'data-slot': 'separator' })`
  flex-shrink: 0;
  background-color: var(--color-border);

  &[data-orientation="horizontal"] { height: 1px; width: 100%; }
  &[data-orientation="vertical"] { height: 100%; width: 1px; }
`;
