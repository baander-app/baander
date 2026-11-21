import type { KeyboardEvent } from 'react'
import styled from 'styled-components'
import { Button } from '@/shared/components/ui/button'
import { focusVisibleRing, textRole } from '@/shared/theme'
import type { Span } from '../../api/debug-api'
import { getHttpStatus, spanStartTime } from './span-inspector'
import { DetailGrid, Notice } from './span-inspector-styles'
import { formatDurationMs } from './format'

const Region = styled.section`
  min-width: 0;
  padding: var(--space-md);
  overflow-wrap: anywhere;
  ${textRole('body-sm')}
`
const Header = styled.div`
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-between;
  gap: var(--space-md);
`
const Heading = styled.h3`
  margin: 0;
  min-width: 0;
  ${textRole('body')}
`
const Summary = styled.div`
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: var(--space-md);
  color: var(--color-muted-foreground);
`
const Timing = styled.span`
  ${textRole('mono-sm')}
`
const Disclosure = styled.details`
  margin-top: var(--space-md);
  padding-top: var(--space-md);
  border-top: 1px solid var(--color-border);
  min-width: 0;
  & > summary {
    cursor: pointer;
    ${textRole('body-sm')}
    ${focusVisibleRing}
  }
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
  offsetUs?: number
}
export function SpanInspector({ span, hidden, onClose, offsetUs }: Props) {
  function onKeyDown(event: KeyboardEvent) {
    if (event.key === 'Escape') {
      event.preventDefault()
      event.stopPropagation()
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
    <Region aria-label="Span inspector" onKeyDown={onKeyDown}>
      <Header>
        <Heading>{span.operation_name}</Heading>
        <Summary>
          <span>Service: {span.service}</span>
          <Timing>Duration: {formatDurationMs(span.duration_us)}</Timing>
          {offsetUs !== undefined && <Timing>Start offset: {formatDurationMs(offsetUs)}</Timing>}
          <Button variant="ghost" size="sm" onClick={onClose}>Close inspector</Button>
        </Summary>
      </Header>
      {hidden && <Notice>Selected span is hidden by the current filters.</Notice>}
      <Disclosure open>
        <summary>Attributes</summary>
        {Object.keys(span.attributes).length ? (
          <DetailGrid>{Object.entries(span.attributes).map(([key, value]) => <div key={key} style={{ display: 'contents' }}><dt>{key}</dt><dd>{attributeText(value)}</dd></div>)}</DetailGrid>
        ) : <Notice>No attributes recorded.</Notice>}
      </Disclosure>
      <Disclosure>
        <summary>Span metadata</summary>
        <DetailGrid>{fields.map(([label, value]) => <div key={label} style={{ display: 'contents' }}><dt>{label}</dt><dd>{value}</dd></div>)}</DetailGrid>
      </Disclosure>
    </Region>
  )
}
