import styled from 'styled-components'
import { Button } from '@/shared/components/ui/button'
import { textRole } from '@/shared/theme'
import type { Span } from '../../api/debug-api'
import { formatDurationMs } from './format'
import { spanKey, spanStartTime, type TraceWaterfall } from './span-inspector'
import { spanServiceColor } from './span-timeline-color'

const Header = styled.header`
  min-width: 0;
  display: grid;
  gap: var(--space-md);
`
const Identity = styled.div`
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: var(--space-md);
  min-width: 0;
`
const Title = styled.h3`
  margin: 0;
  overflow-wrap: anywhere;
  ${textRole('body')}
`
const TraceId = styled.span`
  color: var(--color-muted-foreground);
  ${textRole('mono-sm')}
`
const Summary = styled.dl`
  display: flex;
  flex-wrap: wrap;
  gap: var(--space-md);
  margin: 0;
  padding-block: var(--space-md);
  border-block: 1px solid var(--color-border);
  ${textRole('body-sm')}
  & > div { display: flex; flex-wrap: wrap; gap: var(--space-md); min-width: 0; }
  & dt { color: var(--color-muted-foreground); }
  & dd { margin: 0; overflow-wrap: anywhere; ${textRole('mono-sm')} }
`
const RootNotice = styled.p`
  margin: 0;
  color: var(--color-muted-foreground);
  ${textRole('body-sm')}
`
const Overview = styled.div`
  min-width: 0;
`
const OverviewAxis = styled.div`
  display: flex;
  justify-content: space-between;
  gap: var(--space-md);
  margin-bottom: var(--space-md);
  color: var(--color-muted-foreground);
  ${textRole('mono-sm')}
`
const OverviewChart = styled.svg`
  display: block;
  width: 100%;
  height: 96px;
  border: 1px solid var(--color-border);
  box-sizing: border-box;
`

interface Props {
  timeline: TraceWaterfall
  span: Span
  onBack: () => void
}

export function SpanTraceHeader({ timeline, span, onBack }: Props) {
  const root = timeline.rows.find((row) => row.span.parent_span_id === null)?.span
  const entry = root ?? span
  const services = new Set(timeline.rows.map((row) => row.span.service)).size
  const depth = timeline.rows.reduce((maximum, row) => Math.max(maximum, row.depth + 1), 0)
  const rowHeight = 96 / Math.max(1, timeline.rows.length)
  const summary = [
    ['Start (UTC)', spanStartTime(timeline.startTimeUs)],
    ['Duration', formatDurationMs(timeline.durationUs)],
    ['Services', String(services)],
    ['Depth', String(depth)],
    ['Spans', String(timeline.rows.length)],
  ]

  return (
    <Header aria-label="Selected trace">
      <Identity>
        <Button variant="ghost" size="sm" onClick={onBack}>Back to results</Button>
        <Title>{entry.service}: {entry.operation_name}</Title>
        <TraceId title={entry.trace_id}>{entry.trace_id.slice(0, 7)}</TraceId>
      </Identity>
      {!root && <RootNotice>Root span unavailable in the retained snapshot; showing the entry span.</RootNotice>}
      <Summary aria-label="Retained trace summary">
        {summary.map(([label, value]) => <div key={label}><dt>{label}</dt><dd>{value}</dd></div>)}
      </Summary>
      <Overview>
        <OverviewAxis aria-hidden="true"><span>0 µs</span><span>{formatDurationMs(timeline.durationUs)}</span></OverviewAxis>
        <OverviewChart role="img" aria-label="Trace overview" viewBox="0 0 1000 96" preserveAspectRatio="none">
          {[0, 250, 500, 750, 1000].map((x) => <line key={x} x1={x} x2={x} y1={0} y2={96} stroke="var(--color-border)" />)}
          {timeline.rows.map((row, index) => (
            <rect
              key={spanKey(row.span)}
              x={Math.min(row.offsetPercent * 10, 999)}
              y={index * rowHeight}
              width={Math.max(1, Math.min(row.widthPercent * 10, 1000 - row.offsetPercent * 10))}
              height={Math.max(0.15, Math.min(4, rowHeight * 0.75))}
              fill={spanServiceColor(row.span.service)}
            />
          ))}
        </OverviewChart>
      </Overview>
    </Header>
  )
}
