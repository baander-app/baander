import { useMemo, useRef, useState } from 'react'
import { Navigate } from 'react-router-dom'
import styled from 'styled-components'
import { ChevronDown, Pause, Play, Terminal } from 'lucide-react'
import { useAdminCheck } from '@/features/auth/hooks/use-admin-check'
import { Button } from '@/shared/components/ui/button'
import { DropdownMenu, DropdownMenuCheckboxItem, DropdownMenuContent, DropdownMenuTrigger } from '@/shared/components/ui/dropdown-menu'
import { Input } from '@/shared/components/ui/input'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/shared/components/ui/select'
import { textRole } from '@/shared/theme'
import { LOG_TIME_RANGES, type LogTimeRange, type LogRecord } from '../api/logs-api'
import { useLogs, useLogSources } from '../hooks/use-logs'
import { LogMessages } from '../components/logs/LogMessages'
import { safeLogText } from '../components/logs/log-text'
import { LogInspector } from '../components/logs/LogInspector'
import { useLogFilters } from '../components/logs/use-log-filters'
import { LogSources } from '../components/logs/LogSources'
import { CoverageNotice } from '../components/logs/CoverageNotice'
import { LogSqlPanel } from '../components/logs/LogSqlPanel'

const Workspace = styled.div`
  display: flex;
  flex-direction: column;
  height: 100%;
  min-height: 0;
  min-width: 0;
  background: var(--color-background);
  ${textRole('body-sm')}
`
const Toolbar = styled.div`
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 8px;
  padding: 10px 12px;
  border-bottom: 1px solid var(--color-border);
  background: var(--color-muted);
  h1 { ${textRole('subtitle')} display: flex; align-items: center; gap: 6px; margin: 0 auto 0 0; }
  input { ${textRole('body-sm')} }
  & > input { width: 210px; }
`
const Filters = styled.form`
  display: flex;
  flex-wrap: wrap;
  align-items: end;
  gap: 8px;
  padding: 8px 12px;
  border-bottom: 1px solid var(--color-border);
  label { display: flex; flex-direction: column; gap: 3px; min-width: 100px; flex: 1; ${textRole('caption')} }
  input { ${textRole('body-sm')} }
`
const Body = styled.div`
  display: flex;
  flex: 1;
  min-height: 0;
  min-width: 0;
  @media (max-width: 900px) { flex-direction: column; overflow: auto; }
`
const Messages = styled.section`
  display: flex;
  flex-direction: column;
  flex: 1;
  min-width: 0;
  min-height: 0;
  @media (max-width: 900px) { min-height: 340px; }
`
const Status = styled.div`
  padding: 6px 12px;
  ${textRole('caption')}
  border-bottom: 1px solid var(--color-border);
  overflow-wrap: anywhere;
`

export function LogsPage() {
  const { isSuperAdmin } = useAdminCheck()
  return isSuperAdmin ? <LogWorkspace /> : <Navigate to="/admin" replace />
}

function LogWorkspace() {
  const { filter, filterKey, draft, updateText, applyPatch, flushText } = useLogFilters()
  const messageListRef = useRef<HTMLDivElement>(null)
  const [following, setFollowing] = useState(true)
  const [selection, setSelection] = useState<{ key: string; record: LogRecord | null }>({ key: filterKey, record: null })
  const selectedRecord = selection.key === filterKey ? selection.record : null
  const selectedId = selectedRecord?.id ?? null
  const [sqlMode, setSqlMode] = useState(false)
  const sources = useLogSources(following && !sqlMode)
  const { records, history, tail, limited, reloadHistory, coverage } = useLogs(filter, following && !sqlMode)
  const page = tail.data ?? history.data?.pages[0]
  function setSelectedId(id: string | null) {
    setSelection({ key: filterKey, record: id ? records.find(record => record.id === id) ?? null : null })
  }
  const channels = useMemo(() => [...new Set([
    ...(sources.data?.channels ?? []),
    ...filter.channels,
    ...draft.channels,
  ])].sort((a, b) => a.localeCompare(b)), [sources.data?.channels, filter.channels, draft.channels])

  function selectSource(id: string, checked: boolean) {
    applyPatch({ sources: checked ? [...filter.sources, id] : filter.sources.filter(source => source !== id) })
  }
  return <Workspace>
    <Toolbar>
      <h1><Terminal size={18} aria-hidden />Logs</h1>
      <Input aria-label="Search log messages" placeholder="Search messages" value={draft.search} onChange={event => updateText({ search: event.target.value })} onKeyDown={event => { if (event.key === 'Enter') { event.preventDefault(); flushText() } }} />
      <Button variant="outline" size="sm" aria-pressed={!following} onClick={() => setFollowing(value => !value)}>{following ? <Pause aria-hidden /> : <Play aria-hidden />}{following ? 'Pause' : 'Resume'}</Button>
      <Button variant="outline" size="sm" aria-pressed={sqlMode} onClick={() => { setSqlMode(value => !value); if (!sqlMode) setFollowing(false) }}>{sqlMode ? 'Messages' : 'SQL query'}</Button>
    </Toolbar>
    <Filters onSubmit={event => { event.preventDefault(); flushText() }}>
      <label>Level<Select value={draft.levels[0] ?? 'all'} onValueChange={level => applyPatch({ levels: level === 'all' ? [] : [level] })}><SelectTrigger aria-label="Level"><SelectValue /></SelectTrigger><SelectContent>{['all', 'debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency'].map(level => <SelectItem value={level} key={level}>{level === 'all' ? 'All levels' : level}</SelectItem>)}</SelectContent></Select></label>
      <label>Channels
        <DropdownMenu>
          <DropdownMenuTrigger asChild>
            <Button variant="outline" aria-label="Channels">{draft.channels.length ? `${draft.channels.length} selected` : 'All channels'}<ChevronDown aria-hidden /></Button>
          </DropdownMenuTrigger>
          <DropdownMenuContent>
            <DropdownMenuCheckboxItem checked={!draft.channels.length} onSelect={event => event.preventDefault()} onCheckedChange={() => applyPatch({ channels: [] })}>All channels</DropdownMenuCheckboxItem>
            {channels.map(channel => <DropdownMenuCheckboxItem key={channel} checked={draft.channels.includes(channel)}
              onSelect={event => event.preventDefault()}
              onCheckedChange={checked => applyPatch({ channels: checked ? [...draft.channels, channel] : draft.channels.filter(value => value !== channel) })}>
              {safeLogText(channel)}
            </DropdownMenuCheckboxItem>)}
          </DropdownMenuContent>
        </DropdownMenu>
      </label>
      <label>Correlation ID<Input value={draft.correlationId ?? ''} onChange={event => updateText({ correlationId: event.target.value || undefined })} /></label>
      <label>Time range
        <Select value={draft.timeRange ?? 'all'} onValueChange={value => applyPatch(value === 'custom' ? { timeRange: 'custom' } : { timeRange: value === 'all' ? undefined : value as LogTimeRange, since: undefined, until: undefined })}>
          <SelectTrigger aria-label="Time range"><SelectValue /></SelectTrigger>
          <SelectContent>
            <SelectItem value="all">All time</SelectItem>
            <SelectItem value="custom">Custom</SelectItem>
            {LOG_TIME_RANGES.map(range => <SelectItem key={range} value={range}>{({ '5m': '5 minutes', '15m': '15 minutes', '30m': '30 minutes', '1h': '1 hour', '3h': '3 hours', '6h': '6 hours', '12h': '12 hours', '1w': '1 week', '1mo': '1 month', '1y': '1 year' })[range]}</SelectItem>)}
          </SelectContent>
        </Select>
      </label>
      <label>From<Input type="datetime-local" value={draft.since ?? ''} onChange={event => applyPatch({ since: event.target.value || undefined, timeRange: 'custom' })} /></label>
      <label>Until<Input type="datetime-local" value={draft.until ?? ''} onChange={event => applyPatch({ until: event.target.value || undefined, timeRange: 'custom' })} /></label>
    </Filters>
    <Body>
      <LogSources data={sources.data} loading={sources.isPending} error={sources.isError} selected={filter.sources} onAll={() => applyPatch({ sources: [] })} onSelect={selectSource} onRetry={() => { sources.refetch() }} />
      <Messages aria-label={sqlMode ? 'Log SQL workspace' : 'Log message workspace'}>
        <Status role="status">{following && !sqlMode ? 'Following' : 'Paused'} · {records.length.toLocaleString()} messages{limited ? ' · View limit reached; narrow your filters' : ''}{page ? ` · Snapshot ${page.snapshot.createdAt}` : ''}</Status>
        {coverage && <CoverageNotice scope="Message" title="Message collection issues" coverage={coverage} />}
        {sqlMode ? <LogSqlPanel key={JSON.stringify(filter)} filter={filter} /> : <>
          {history.isPending && <Status role="status">Loading messages…</Status>}
          {history.isError && <Status role="alert">Message history unavailable. <Button size="sm" variant="ghost" onClick={() => history.refetch()}>Retry</Button></Status>}
          {tail.isError && following && <Status role="alert">Live collection unavailable. Retained messages remain visible.</Status>}
          {!history.isPending && !history.isError && !records.length && <Status>No messages match these filters.</Status>}
          <LogMessages listRef={messageListRef} records={records} selectedId={selectedId} onSelect={setSelectedId} search={filter.search} />
          <Status><Button size="sm" variant="ghost" onClick={() => { setFollowing(false); setSelectedId(null); reloadHistory().catch(() => undefined) }}>Reload history</Button><Button size="sm" variant="ghost" disabled={!history.hasNextPage || history.isFetchingNextPage || limited} onClick={() => { setFollowing(false); history.fetchNextPage() }}>{history.isFetchingNextPage ? 'Loading older messages…' : 'Load older messages'}</Button></Status>
          {selectedRecord && <LogInspector key={selectedRecord.id} record={selectedRecord} onClose={() => { setSelectedId(null); messageListRef.current?.focus() }} />}
        </>}
      </Messages>
    </Body>
  </Workspace>
}
