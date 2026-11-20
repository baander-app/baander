import { useMemo } from 'react'
import styled from 'styled-components'
import { Button } from '@/shared/components/ui/button'
import { Checkbox } from '@/shared/components/ui/checkbox'
import { textRole } from '@/shared/theme'
import type { LogSource, LogSources as LogSourceInventory } from '../../api/logs-api'
import { CoverageNotice, type CoverageIssueGroup } from './CoverageNotice'
import { sourceDescription, sourceLabel, sourceSummary } from './source-label'
import { safeLogText } from './log-text'

const Sources = styled.details`
  width: 220px;
  flex-shrink: 0;
  overflow: auto;
  border-right: 1px solid var(--color-border);
  background: var(--color-sidebar);
  & > summary { padding: 12px; cursor: pointer; ${textRole('body-sm')} }
  label { display: flex; gap: 8px; align-items: start; padding: 7px 12px; min-width: 0; }
  label > span { min-width: 0; flex: 1; }
  label > span > span, label small { display: block; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
  small { color: var(--color-muted-foreground); ${textRole('caption')} }
  & > p { padding: 0 12px; overflow-wrap: anywhere; }
  @media (max-width: 900px) { width: 100%; max-height: 220px; border-right: 0; border-bottom: 1px solid var(--color-border); }
`
const Group = styled.details`
  & > summary { padding: 8px 12px; cursor: pointer; ${textRole('caption')} }
`

const serviceTitles: Record<string, string> = {
  execution: 'Execution workers',
  control: 'Control workers',
  web: 'Web application logs',
  app: 'Application container',
  gateway: 'Gateway',
  postgres: 'PostgreSQL',
  redis: 'Redis',
  pooler: 'Database connection pool',
  'pooler-credentials': 'Pool credentials',
  vite: 'Frontend development server',
  nginx: 'Frontend web server',
  docker: 'Docker',
}

function serviceTitle(source: LogSource): string {
  if (source.kind === 'collector') return 'Log collector'
  const service = source.service?.trim()
  if (service) return serviceTitles[service] ?? service
  if (source.kind === 'container') return 'Containers'
  return ['file', 'archive', 'gzip', 'zip'].includes(source.kind) ? 'Log files' : 'Other log sources'
}

function groupSources(sources: LogSource[]): [string, LogSource[]][] {
  const groups = new Map<string, LogSource[]>()
  for (const source of sources) {
    const name = serviceTitle(source)
    const group = groups.get(name) ?? []
    group.push(source)
    groups.set(name, group)
  }
  return [...groups].sort(([left], [right]) => left.localeCompare(right))
}

interface LogSourcesProps {
  data?: LogSourceInventory
  loading: boolean
  error: boolean
  selected: string[]
  onAll: () => void
  onSelect: (id: string, checked: boolean) => void
  onRetry: () => void
}

export function LogSources({ data, loading, error, selected, onAll, onSelect, onRetry }: LogSourcesProps) {
  const available = useMemo(() => groupSources(data?.items.filter(source => source.available !== false) ?? []), [data?.items])
  const unavailable = useMemo(() => groupSources(data?.items.filter(source => source.available === false) ?? []), [data?.items])
  const issues = useMemo(() => {
    const groups: CoverageIssueGroup[] = []
    if (data?.coverage.reasons.length) groups.push({ title: 'Source inventory', reasons: data.coverage.reasons })
    for (const node of data?.nodes ?? []) {
      if (node.status === 'unreachable') groups.push({ title: `Collection host: ${node.id}`, reasons: ['This host is unreachable.'] })
    }
    for (const source of data?.items ?? []) {
      if (source.issues?.length) groups.push({ title: `${sourceLabel(source)} · ${source.node}`, description: source.name, reasons: source.issues })
    }
    return groups
  }, [data])

  function renderSource(source: LogSource) {
    const label = sourceLabel(source)
    const description = sourceDescription(source)
    return <label key={source.id} title={description}>
      <Checkbox aria-label={`Select ${label}`} aria-description={description} checked={selected.includes(source.id)} onCheckedChange={checked => onSelect(source.id, checked === true)} />
      <span><span>{safeLogText(label)}</span><small>{safeLogText(sourceSummary(source))}</small></span>
    </label>
  }
  function renderGroup([name, sources]: [string, LogSource[]]) {
    return <Group key={name} open>
      <summary>{safeLogText(name)} · {sources.length} {sources.length === 1 ? 'source' : 'sources'}</summary>
      {sources.map(renderSource)}
    </Group>
  }
  return <Sources open aria-label="Log sources">
    <summary>Sources · {data?.items.length ?? '…'}</summary>
    <Button variant="ghost" size="sm" onClick={onAll}>All sources</Button>
    {loading && <p role="status">Discovering sources…</p>}
    {error && <p role="alert">Source discovery failed. <Button size="sm" variant="ghost" onClick={onRetry}>Retry</Button></p>}
    {available.map(renderGroup)}
    {unavailable.length > 0 && <Group>
      <summary>Unavailable sources ({unavailable.reduce((count, [, sources]) => count + sources.length, 0)})</summary>
      {unavailable.map(renderGroup)}
    </Group>}
    {data && !data.items.length && <p>No admitted sources.</p>}
    {data && <CoverageNotice scope="Source inventory" title="Collection issues" coverage={data.coverage} groups={issues} />}
  </Sources>
}
