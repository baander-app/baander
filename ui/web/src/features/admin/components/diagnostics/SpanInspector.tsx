import { useEffect, useMemo, useRef, type KeyboardEvent } from 'react'
import styled from 'styled-components'
import { Button } from '@/shared/components/ui/button'
import { textRole } from '@/shared/theme'
import type { Span } from '../../api/debug-api'
import { buildTraceWaterfall, getHttpStatus, spanKey, spanStartTime } from './span-inspector'
import { SpanResults } from './SpanResults'
import { DetailGrid, Notice } from './span-inspector-styles'
import { formatDurationMs } from './format'

const Region = styled.section`
  min-width: 0;
  max-height: 48rem;
  overflow: auto;
  border: 1px solid var(--color-border);
  border-radius: ${({ theme }) => theme.radii.md};
  padding: var(--space-sm);
  overflow-wrap: anywhere;
  ${textRole('body-sm')}
`
const Heading = styled.h3`
  margin: var(--space-sm) 0;
  ${textRole('body')}
`
function spanKind(kind: string): string {
  const names: Record<string, string> = { '0': 'internal', '1': 'client', '2': 'server', '3': 'producer', '4': 'consumer' }
  return names[kind] ?? kind
}
function attributeText(value: unknown): string {
  return typeof value === 'string' ? value : JSON.stringify(value, null, 2) ?? String(value)
}
interface Props {
  span: Span
  hidden: boolean
  onClose: () => void
}
export function SpanInspector({ span, hidden, onClose }: Props) {
  const regionRef = useRef<HTMLElement>(null)
  useEffect(() => {
    if (window.innerWidth <= 767) regionRef.current?.scrollIntoView?.({ block: 'nearest' })
  }, [span.trace_id, span.span_id])
  function onKeyDown(event: KeyboardEvent) {
    if (event.key === 'Escape') {
      event.preventDefault()
      onClose()
    }
  }
  const fields = [
    ['Operation', span.operation_name], ['Trace ID', span.trace_id], ['Span ID', span.span_id],
    ['Parent span ID', span.parent_span_id ?? 'None'], ['Service', span.service], ['Kind', spanKind(span.kind)],
    ['Start time (UTC)', spanStartTime(span.start_time_us)], ['Start time (µs since epoch)', String(span.start_time_us)],
    ['Duration', `${formatDurationMs(span.duration_us)} (${span.duration_us} µs)`],
    ['HTTP status', getHttpStatus(span) ?? 'None'], ['Telemetry status', span.status_code],
    ['Status message', span.status_message || 'None'],
    ['Source', span.file_path === null ? 'Not available' : `${span.file_path}${span.line_number === null ? '' : `:${span.line_number}`}`],
  ]
  return (
    <Region ref={regionRef} aria-label="Span inspector" onKeyDown={onKeyDown}>
      <Button variant="ghost" size="sm" onClick={onClose}>Close inspector</Button>
      {hidden && <Notice>Selected span is hidden by the current filters.</Notice>}
      <Heading>Span details</Heading>
      <DetailGrid>{fields.map(([label, value]) => <div key={label} style={{ display: 'contents' }}><dt>{label}</dt><dd>{value}</dd></div>)}</DetailGrid>
      <Heading>Attributes</Heading>
      {Object.keys(span.attributes).length ? (
        <DetailGrid>{Object.entries(span.attributes).map(([key, value]) => <div key={key} style={{ display: 'contents' }}><dt>{key}</dt><dd>{attributeText(value)}</dd></div>)}</DetailGrid>
      ) : <Notice>No attributes recorded.</Notice>}
    </Region>
  )
}

interface TraceProps {
  span: Span
  snapshot: readonly Span[]
  onSelect: (span: Span) => void
}
export function SpanTrace({ span, snapshot, onSelect }: TraceProps) {
  const trace = useMemo(() => buildTraceWaterfall(snapshot, span.trace_id), [snapshot, span.trace_id])
  return (
    <div>
      <Heading>Retained trace</Heading>
      <Notice>Only retained spans are shown; this trace may be incomplete. Start: {spanStartTime(trace.startTimeUs)}. Retained bounds: +0 to +{formatDurationMs(trace.durationUs)}.</Notice>
      <SpanResults spans={trace.rows.map((row) => row.span)} timeline={trace.rows} selectedKey={spanKey(span)} onSelect={onSelect} />
    </div>
  )
}
