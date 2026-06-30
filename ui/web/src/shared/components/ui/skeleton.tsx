import styled from 'styled-components';

export const Skeleton = styled.div.attrs({ 'data-slot': 'skeleton' })`
  display: inline-block;
  width: 100%;
  border-radius: ${({ theme }) => theme.radii.md};
  animation: pulse 2s cubic-bezier(0.4, 0, 0.6, 1) infinite;

  @keyframes pulse {
    0%, 100% { opacity: 1; }
    50% { opacity: 0.5; }
  }
`;
