import { useId, useMemo, useState } from 'react'
import { ChevronDown } from 'lucide-react'
import styled from 'styled-components'
import { focusVisibleRing, textRole } from '@/shared/theme'
import type { LogCoverage } from '../../api/logs-api'
import { safeLogText } from './log-text'

const Notice = styled.details`
  flex-shrink: 0;
  min-width: 0;
  border-bottom: 1px solid var(--color-border);
  ${textRole('caption')}
  &[open] > summary > svg { transform: rotate(180deg); }
`
const Trigger = styled.summary`
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 6px;
  padding: 6px 8px;
  cursor: pointer;
  list-style: none;
  ${focusVisibleRing}
  &::-webkit-details-marker { display: none; }
  svg { flex-shrink: 0; }
`
const Summary = styled.div`
  min-width: 0;
  span { display: block; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
  span + span { color: var(--color-muted-foreground); }
`
const Issues = styled.div`
  max-height: min(18rem, 35vh);
  overflow: auto;
  padding: 8px 12px;
  border-top: 1px solid var(--color-border);
  overflow-wrap: anywhere;
  ${textRole('mono-sm')}
  ol { padding-left: 24px; margin: 0; }
  li + li { margin-top: 12px; }
  section + section { margin-top: 16px; }
  h3 { ${textRole('body-sm')} margin: 0 0 6px; }
  p { margin: 0 0 8px; }
`

export interface CoverageIssueGroup {
  title?: string
  description?: string
  reasons: string[]
}

interface CoverageNoticeProps {
  coverage: LogCoverage
  scope: string
  title: string
  description?: string
  groups?: CoverageIssueGroup[]
}

export function CoverageNotice({ coverage, scope, title, description, groups }: CoverageNoticeProps) {
  const summaryId = useId()
  const [open, setOpen] = useState(false)
  const sections = useMemo(() => (groups ?? [{ description, reasons: coverage.reasons }]).map(group => ({ ...group, reasons: [...new Set(group.reasons)] })), [coverage.reasons, description, groups])
  const issueCount = sections.reduce((count, group) => count + group.reasons.length, 0)
  if (coverage.complete && !issueCount) return null

  return <Notice open={open} aria-label={`${scope} coverage notice`}>
    <Trigger role="button" aria-label={`${scope} coverage details`} aria-expanded={open} aria-describedby={summaryId}
      onClick={event => { event.preventDefault(); setOpen(value => !value) }}
      onKeyDown={event => {
        if (event.key === 'Enter' || event.key === ' ') {
          event.preventDefault()
          setOpen(value => !value)
        }
      }}>
      <Summary id={summaryId} role="status" aria-label={`${scope} coverage`}>
        <span title={title}>{title}</span>
        <span>{!coverage.complete && 'Incomplete · '}{issueCount} {issueCount === 1 ? 'issue' : 'issues'}</span>
      </Summary>
      <ChevronDown size={14} aria-hidden />
    </Trigger>
    <Issues role="region" aria-label={`${scope} coverage issues`} tabIndex={0}>
      {issueCount ? sections.map((group, index) => <section key={index}>
        {group.title && <h3>{safeLogText(group.title)}</h3>}
        {group.description && <p>{safeLogText(group.description)}</p>}
        <ol>{group.reasons.map(reason => <li key={reason}>{safeLogText(reason)}</li>)}</ol>
      </section>) : <p>No issue details were supplied.</p>}
    </Issues>
  </Notice>
}
