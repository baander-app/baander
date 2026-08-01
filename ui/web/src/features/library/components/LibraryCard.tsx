import styled, { css } from 'styled-components'
import { Card, CardContent } from '@/shared/components/ui/card'
import type { Library } from '../api/library-api'
import { formatRelativeTime } from '../utils/format'

interface LibraryCardProps {
  library: Library
  isScanning: boolean
  onEdit: (library: Library) => void
  onDelete: (library: Library) => void
  onScan: (library: Library) => void
  onViewDetail: (library: Library) => void
}

const ScanStatusStyles = {
  scanning: css`
    background-color: rgba(59, 130, 246, 0.15);
    color: #3b82f6;
  `,
  completed: css`
    background-color: rgba(16, 185, 129, 0.15);
    color: #10b981;
  `,
  failed: css`
    background-color: rgba(239, 68, 68, 0.15);
    color: #ef4444;
  `,
}

const Badge = styled.span<{ $variant?: string }>`
  display: inline-flex;
  align-items: center;
  gap: 0.25rem;
  border-radius: 9999px;
  padding: 0.125rem 0.5rem;
  font-size: 10px;
  font-weight: 500;

  ${(p) => {
    const variant = p.$variant ?? 'default'
    if (variant in ScanStatusStyles) return ScanStatusStyles[variant as keyof typeof ScanStatusStyles]
    return css`
      background-color: var(--color-muted);
      color: var(--color-muted-foreground);
    `
  }}
`

const SpinIcon = styled.svg`
  height: 0.75rem;
  width: 0.75rem;
  animation: spin 1s linear infinite;

  @keyframes spin {
    from { transform: rotate(0deg); }
    to { transform: rotate(360deg); }
  }
`

const TypeBadgeWrapper = styled.span`
  flex-shrink: 0;
  border-radius: 9999px;
  background-color: var(--color-secondary);
  padding: 0.125rem 0.5rem;
  font-size: 10px;
  font-weight: 500;
  text-transform: uppercase;
  color: var(--color-muted-foreground);
`

const HeaderRow = styled.div`
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 0.5rem;
`

const InfoArea = styled.div`
  min-width: 0;
  flex: 1;
  cursor: pointer;
`

const NameRow = styled.div`
  display: flex;
  align-items: center;
  gap: 0.5rem;

  p:first-child {
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    font-size: 0.875rem;
    font-weight: 500;

    &:hover {
      text-decoration: underline;
    }
  }
`

const PathText = styled.p`
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  font-size: 0.75rem;
  color: var(--color-muted-foreground);
  font-family: monospace;
`

const MetaRow = styled.div`
  display: flex;
  align-items: center;
  justify-content: space-between;
  font-size: 0.75rem;
  color: var(--color-muted-foreground);

  span:last-child {
    font-family: monospace;
  }
`

const ActionsRow = styled.div`
  display: flex;
  gap: 0.5rem;
`

const ActionButton = styled.button<{ $variant?: 'primary' | 'secondary' | 'destructive' }>`
  border-radius: var(--radius-md);
  padding: 0.375rem 0.75rem;
  font-size: 0.75rem;
  font-weight: 500;
  transition: background-color 0.15s, opacity 0.15s;
  border: none;
  cursor: pointer;

  ${(p) => {
    switch (p.$variant) {
      case 'primary':
        return css`
          background-color: rgba(var(--color-primary-rgb, 0 0 0), 0.1);
          color: var(--color-primary);
          &:hover { background-color: rgba(var(--color-primary-rgb, 0 0 0), 0.2); }
        `
      case 'destructive':
        return css`
          background-color: transparent;
          color: var(--color-destructive);
          &:hover { background-color: rgba(239, 68, 68, 0.1); }
        `
      default:
        return css`
          background-color: var(--color-secondary);
          color: inherit;
          &:hover { background-color: var(--color-secondary); opacity: 0.8; }
        `
    }
  }}

  &:disabled {
    opacity: 0.5;
  }
`

function ScanStatusBadge({ status }: { status: string | null }) {
  if (!status) return null
  const labels: Record<string, string> = {
    scanning: 'Scanning',
    completed: 'Completed',
    failed: 'Failed',
  }
  return (
    <Badge $variant={status}>
      {status === 'scanning' && (
        <SpinIcon viewBox="0 0 24 24" fill="none">
          <circle cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="3" style={{ opacity: 0.25 }} />
          <path d="M4 12a8 8 0 018-8" stroke="currentColor" strokeWidth="3" strokeLinecap="round" />
        </SpinIcon>
      )}
      {labels[status] ?? status}
    </Badge>
  )
}

function TypeBadge({ type }: { type: string }) {
  return (
    <TypeBadgeWrapper>
      {type.replace('_', ' ')}
    </TypeBadgeWrapper>
  )
}

export function LibraryCard({ library, isScanning, onEdit, onDelete, onScan, onViewDetail }: LibraryCardProps) {
  return (
    <Card size="sm">
      <CardContent style={{ display: 'flex', flexDirection: 'column', gap: '0.75rem' }}>
        <HeaderRow>
          <InfoArea onClick={() => onViewDetail(library)}>
            <NameRow>
              <p>{library.name}</p>
              <ScanStatusBadge status={library.scanStatus} />
            </NameRow>
            <PathText>{library.path}</PathText>
          </InfoArea>
          <TypeBadge type={library.type} />
        </HeaderRow>

        <MetaRow>
          <span>Last scan: {formatRelativeTime(library.lastScan)}</span>
          <span>/{library.slug}</span>
        </MetaRow>

        <ActionsRow>
          <ActionButton $variant="primary" onClick={() => onScan(library)} disabled={isScanning}>
            {isScanning ? 'Scanning...' : 'Scan'}
          </ActionButton>
          <ActionButton onClick={() => onViewDetail(library)}>
            Detail
          </ActionButton>
          <ActionButton onClick={() => onEdit(library)}>
            Edit
          </ActionButton>
          <ActionButton $variant="destructive" onClick={() => onDelete(library)}>
            Delete
          </ActionButton>
        </ActionsRow>
      </CardContent>
    </Card>
  )
}
