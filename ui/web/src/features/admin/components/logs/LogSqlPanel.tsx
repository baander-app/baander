import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import styled from 'styled-components'
import { Button } from '@/shared/components/ui/button'
import { Checkbox } from '@/shared/components/ui/checkbox'
import { Textarea } from '@/shared/components/ui/textarea'
import { textRole } from '@/shared/theme'
import { queryLogs, type LogFilter } from '../../api/logs-api'
import { CoverageNotice } from './CoverageNotice'
import { safeLogText } from './log-text'

const Panel = styled.section`
  padding: 12px;
  min-height: 0;
  overflow: auto;
  ${textRole('body-sm')}
  form { display: flex; flex-direction: column; gap: 10px; }
  textarea { ${textRole('mono-sm')} }
  label { display: flex; gap: 8px; align-items: center; }
  table { border-collapse: collapse; width: 100%; ${textRole('mono-sm')} }
  th, td { text-align: left; border-bottom: 1px solid var(--color-border); padding: 6px 10px; overflow-wrap: anywhere; max-width: 400px; }
`

export function LogSqlPanel({ filter }: { filter: LogFilter }) {
  const [sql, setSql] = useState('SELECT level, count(*) AS records FROM logs GROUP BY level ORDER BY records DESC')
  const [allowPartial, setAllowPartial] = useState(false)
  const [request, setRequest] = useState<{ sql: string; allowPartial: boolean; attempt: number } | null>(null)
  const result = useQuery({
    queryKey: ['admin', 'logs', 'sql', filter, request],
    queryFn: ({ signal }) => queryLogs(request!.sql, request!.allowPartial, filter, signal),
    enabled: request !== null,
    retry: false,
    staleTime: Infinity,
    gcTime: 0,
  })
  return <Panel aria-label="SQL query">
    <form onSubmit={event => { event.preventDefault(); setRequest({ sql, allowPartial, attempt: (request?.attempt ?? 0) + 1 }) }}>
      <label htmlFor="log-sql">Read-only SQL · selected sources and filters</label>
      <Textarea id="log-sql" value={sql} onChange={event => setSql(event.target.value)} spellCheck={false} />
      <label><Checkbox checked={allowPartial} onCheckedChange={value => setAllowPartial(value === true)} />Allow an incomplete snapshot</label>
      <div><Button size="sm" disabled={!sql.trim() || result.isFetching}>Run query</Button></div>
    </form>
    {result.isFetching && <p role="status">Querying a bounded log snapshot…</p>}
    {result.isError && <p role="alert">Query failed. Check the read-only SQL and source coverage. Incomplete snapshots require explicit acceptance.</p>}
    {result.data && <>
      <p role="status">Snapshot {result.data.snapshot.id} · {result.data.snapshot.createdAt} · {result.data.coverage.complete ? 'Complete coverage' : 'Incomplete snapshot'}{result.data.truncated ? ' · Result limit reached' : ''}</p>
      <CoverageNotice scope="SQL result" title="Query coverage issues" coverage={result.data.coverage} />
      <div style={{ overflow: 'auto' }}><table aria-label="SQL results"><thead><tr>{result.data.columns.map((column, index) => <th key={index}>{safeLogText(column)}</th>)}</tr></thead>
        <tbody>{result.data.rows.map((row, index) => <tr key={index}>{row.map((value, column) => <td key={column}>{value === null ? 'NULL' : safeLogText(String(value))}</td>)}</tr>)}</tbody>
      </table></div>
      {!result.data.rows.length && <p>No rows returned.</p>}
    </>}
  </Panel>
}
