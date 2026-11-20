import { useEffect, useRef, useState } from 'react'
import styled from 'styled-components'
import { Button } from '@/shared/components/ui/button'
import { Slider } from '@/shared/components/ui/slider'
import { textRole } from '@/shared/theme'
import type { LogRecord } from '../../api/logs-api'
import { safeLogText } from './log-text'

const Inspector = styled.section<{ $height: number }>`
  padding: 12px;
  overflow: auto;
  min-height: 180px;
  max-height: 45vh;
  flex: 0 1 ${({ $height }) => $height}px;
  width: 100%;
  border-top: 1px solid var(--color-border);
  ${textRole('body-sm')}
  header { display: flex; align-items: center; flex-wrap: wrap; gap: 8px; margin-bottom: 10px; }
  h2 { ${textRole('subtitle')} margin: 0 auto 0 0; }
  pre { ${textRole('mono-sm')} white-space: pre-wrap; overflow-wrap: anywhere; margin: 0; }
  details { margin-top: 12px; }
  summary { cursor: pointer; color: var(--color-muted-foreground); ${textRole('caption')} }
  dl { display: grid; grid-template-columns: auto minmax(0, 1fr); gap: 4px 12px; }
  dt { color: var(--color-muted-foreground); }
  dd { margin: 0; overflow-wrap: anywhere; }
`
const Resize = styled.label`
  display: flex;
  align-items: center;
  gap: 12px;
  margin: 14px 0;
  ${textRole('caption')}
  & > span { flex-shrink: 0; }
  & > [data-slot='slider'] { max-width: 220px; }
`

export function LogInspector({ record, onClose }: { record: LogRecord; onClose: () => void }) {
  const root = useRef<HTMLElement>(null)
  const [height, setHeight] = useState(280)
  const [copyStatus, setCopyStatus] = useState('')
  useEffect(() => {
    root.current?.scrollIntoView({ block: 'nearest' })
  }, [record.id])

  async function copy() {
    try {
      await navigator.clipboard.writeText(safeLogText(record.raw))
      setCopyStatus('Copied raw record')
    } catch {
      setCopyStatus('Clipboard unavailable. Select and copy the raw text below.')
    }
  }
  return <Inspector ref={root} $height={height} aria-label="Record inspector" onKeyDown={event => { if (event.key === 'Escape') onClose() }}>
    <header>
      <h2>Log message</h2>
      <Button size="sm" variant="outline" onClick={copy}>Copy raw record</Button>
      <Button size="sm" variant="ghost" onClick={onClose}>Close inspector</Button>
    </header>
    <pre aria-label="Full log message">{safeLogText(record.message)}</pre>
    {record.truncated && <p role="status">This record reached the retained size limit.</p>}
    {copyStatus && <p role="status">{copyStatus}</p>}
    <Resize><span>View height</span><Slider aria-label="Expanded view height" min={180} max={440} step={20} value={[height]} onValueChange={values => setHeight(values[0])} /></Resize>
    <details><summary>Context</summary><pre>{safeLogText(JSON.stringify(record.context, null, 2) ?? 'null')}</pre></details>
    <details><summary>Extra fields</summary><pre>{safeLogText(JSON.stringify(record.extra, null, 2) ?? 'null')}</pre></details>
    <details><summary>Raw record</summary><pre>{safeLogText(record.raw)}</pre></details>
    <details><summary>Record fields</summary><dl>{Object.entries({ Time: record.timestamp, Level: record.level, Node: record.node, Service: record.service, Stream: record.stream, Channel: record.channel, Correlation: record.correlationId }).map(([label, value]) => <div key={label} style={{ display: 'contents' }}><dt>{label}</dt><dd>{value ?? '—'}</dd></div>)}</dl></details>
  </Inspector>
}
