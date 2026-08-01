import * as React from 'react';
import styled, { css } from 'styled-components';

const StyledCard = styled.div<{ $size?: string }>`
  display: flex;
  flex-direction: column;
  gap: 1rem;
  overflow: hidden;
  border-radius: var(--radius-xl);
  background-color: var(--color-card);
  padding-top: 1rem;
  padding-bottom: 1rem;
  font-size: 0.875rem;
  color: var(--color-card-foreground);
  &:has([data-slot="card-footer"]) { padding-bottom: 0; }
  &:has(> img:first-child) { padding-top: 0; }
  & img:first-child { border-radius: var(--radius-xl) var(--radius-xl) 0 0; }
  & img:last-child { border-radius: 0 0 var(--radius-xl) var(--radius-xl); }
  ${({ $size }) => $size === 'sm' && css`
    gap: 0.75rem; padding-top: 0.75rem; padding-bottom: 0.75rem;
    &:has([data-slot="card-footer"]) { padding-bottom: 0; }
  `}
`;

function Card({ size = 'default', ...props }: React.ComponentProps<'div'> & { size?: 'default' | 'sm' }) {
  return <StyledCard data-slot="card" data-size={size} $size={size} {...props} />;
}

const StyledCardHeader = styled.div`
  display: grid;
  grid-auto-rows: min-content;
  align-items: start;
  gap: 0.25rem;
  border-radius: var(--radius-xl) var(--radius-xl) 0 0;
  padding: 0 1rem;
  container-type: inline-size;
  container-name: card-header;
  &:has([data-slot="card-action"]) { grid-template-columns: 1fr auto; }
  &:has([data-slot="card-description"]) { grid-template-rows: auto auto; }
  &[class*="border-b"] { padding-bottom: 1rem; }
  [data-size="sm"] & { padding: 0 0.75rem; }
  [data-size="sm"] &[class*="border-b"] { padding-bottom: 0.75rem; }
`;

function CardHeader({ ...props }: React.ComponentProps<'div'>) {
  return <StyledCardHeader data-slot="card-header" {...props} />;
}

const StyledCardTitle = styled.div`
  font-family: var(--font-heading);
  font-size: 1rem;
  font-weight: 500;
  line-height: 1.375;
  [data-size="sm"] & { font-size: 0.875rem; }
`;

function CardTitle({ ...props }: React.ComponentProps<'div'>) {
  return <StyledCardTitle data-slot="card-title" {...props} />;
}

const StyledCardDescription = styled.div`
  font-size: 0.875rem;
  color: var(--color-muted-foreground);
`;

function CardDescription({ ...props }: React.ComponentProps<'div'>) {
  return <StyledCardDescription data-slot="card-description" {...props} />;
}

const StyledCardAction = styled.div`
  grid-column: 2;
  grid-row: 1 / span 2;
  justify-self: end;
  align-self: start;
`;

function CardAction({ ...props }: React.ComponentProps<'div'>) {
  return <StyledCardAction data-slot="card-action" {...props} />;
}

const StyledCardContent = styled.div`
  padding: 0 1rem;
  [data-size="sm"] & { padding: 0 0.75rem; }
`;

function CardContent({ ...props }: React.ComponentProps<'div'>) {
  return <StyledCardContent data-slot="card-content" {...props} />;
}

const StyledCardFooter = styled.div`
  display: flex;
  align-items: center;
  border-radius: 0 0 var(--radius-xl) var(--radius-xl);
  border-top: 1px solid var(--color-border);
  background-color: color-mix(in srgb, var(--color-muted) 50%, transparent);
  padding: 1rem;
  [data-size="sm"] & { padding: 0.75rem; }
`;

function CardFooter({ ...props }: React.ComponentProps<'div'>) {
  return <StyledCardFooter data-slot="card-footer" {...props} />;
}

export {
  Card,
  CardHeader,
  CardFooter,
  CardTitle,
  CardAction,
  CardDescription,
  CardContent,
};
