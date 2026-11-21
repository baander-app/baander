import { useId, useRef, useState, type KeyboardEvent } from 'react'
import { defaultRangeExtractor, useVirtualizer } from '@tanstack/react-virtual'
import styled from 'styled-components'
import { focusVisibleRing, textRole } from '@/shared/theme'
import type { Span } from '../../api/debug-api'
import { getHttpStatus, isErrorSpan, spanKey, spanStartTime, type TraceWaterfallRow } from './span-inspector'
import { formatDurationMs } from './format'

const ROW_HEIGHT = 48

const Scroll = styled.div`
  max-height: 26rem;
  overflow: auto;
  min-width: 0;
  border: 1px solid var(--color-border);
  border-radius: ${({ theme }) => theme.radii.md};
  ${textRole('body-sm')}
  ${focusVisibleRing}
`
const Row = styled.div`
  position: relative;
  height: ${ROW_HEIGHT}px;
  box-sizing: border-box;
  padding: 3px var(--space-sm);
  border-bottom: 1px solid var(--color-border);
  cursor: pointer;
  &[aria-selected='true'] { background: var(--color-highlight); }
  &[data-active='true'] { outline: 2px solid var(--color-ring); outline-offset: -2px; }
`
const Name = styled.div`
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
`
const Meta = styled.div`
  display: flex;
  gap: var(--space-md);
  overflow: hidden;
  white-space: nowrap;
  color: var(--color-muted-foreground);
  ${textRole('mono-sm')}
  & > span, & > time { flex-shrink: 0; }
  & > span[data-service] { flex: 1 1 auto; min-width: 0; overflow: hidden; text-overflow: ellipsis; }
`
const Track = styled.div`
  position: absolute;
  left: var(--space-sm);
  right: var(--space-sm);
  bottom: 1px;
  height: 2px;
  background: var(--color-muted);
`
const Bar = styled.div`
  position: absolute;
  height: 100%;
  min-width: 2px;
  background: var(--color-primary);
`
interface Props {
  spans: readonly Span[]
  selectedKey?: string
  onSelect: (span: Span) => void
  timeline?: readonly TraceWaterfallRow[]
}
export function SpanResults({ spans, selectedKey, onSelect, timeline }: Props) {
  const id = useId()
  const scrollRef = useRef<HTMLDivElement>(null)
  const [activeKey, setActiveKey] = useState<string | null>(null)
  const activeIndex = Math.max(0, spans.findIndex((span) => spanKey(span) === (activeKey ?? selectedKey)))
  const virtual = spans.length > 50
  const virtualizer = useVirtualizer({
    count: virtual ? spans.length : 0,
    getScrollElement: () => scrollRef.current,
    estimateSize: () => ROW_HEIGHT,
    getItemKey: (index) => spanKey(spans[index]),
    overscan: 5,
    initialRect: { width: 320, height: 416 },
    rangeExtractor: (range) => [...new Set([...defaultRangeExtractor(range), activeIndex])].sort((a, b) => a - b),
  })
  function onKeyDown(event: KeyboardEvent<HTMLDivElement>) {
    let next: number
    if (event.key === 'ArrowDown') next = Math.min(spans.length - 1, activeIndex + 1)
    else if (event.key === 'ArrowUp') next = Math.max(0, activeIndex - 1)
    else if (event.key === 'Home') next = 0
    else if (event.key === 'End') next = spans.length - 1
    else if (event.key === 'Enter' || event.key === ' ') {
      event.preventDefault()
      if (spans[activeIndex]) onSelect(spans[activeIndex])
      return
    } else return
    event.preventDefault()
    if (!spans[next]) return
    setActiveKey(spanKey(spans[next]))
    if (virtual) virtualizer.scrollToIndex(next, { align: 'auto' })
    else document.getElementById(`${id}-${next}`)?.scrollIntoView?.({ block: 'nearest' })
  }
  function row(span: Span, index: number, virtualStart?: number) {
    const traceRow = timeline?.[index]
    const status = getHttpStatus(span)
    const startTime = spanStartTime(span.start_time_us)
    return (
      <Row
        id={`${id}-${index}`}
        key={spanKey(span)}
        role="option"
        aria-selected={spanKey(span) === selectedKey}
        aria-posinset={index + 1}
        aria-setsize={spans.length}
        data-active={index === activeIndex}
        data-index={index}
        style={virtual ? { position: 'absolute', top: 0, left: 0, width: '100%', transform: `translateY(${virtualStart}px)` } : undefined}
        onClick={() => {
          setActiveKey(spanKey(span))
          onSelect(span)
          scrollRef.current?.focus({ preventScroll: true })
        }}
      >
        <Name title={span.operation_name} style={traceRow ? { paddingInlineStart: `${Math.min(traceRow.depth, 8) * 12}px` } : undefined}>
          {span.operation_name}{traceRow?.missingParent && <span title={span.parent_span_id ?? undefined}> · Missing parent: {span.parent_span_id}</span>}
        </Name>
        <Meta>
          <span>{status === null ? 'No HTTP' : status}{isErrorSpan(span) ? ' Error' : ''}</span>
          <span>{formatDurationMs(span.duration_us)}</span>
          <span data-service title={span.service}>{span.service}</span>
          {traceRow
            ? <span>+{formatDurationMs(traceRow.offsetUs)}</span>
            : <time dateTime={startTime} title={startTime}>{startTime.slice(11, 23)}</time>}
        </Meta>
        {traceRow && <Track aria-hidden="true"><Bar style={{ left: `${traceRow.offsetPercent}%`, width: `${traceRow.widthPercent}%` }} /></Track>}
      </Row>
    )
  }
  return (
    <Scroll
      ref={scrollRef}
      role="listbox"
      aria-label={timeline ? 'Trace waterfall' : 'Spans'}
      tabIndex={0}
      aria-activedescendant={spans.length ? `${id}-${activeIndex}` : undefined}
      onKeyDown={onKeyDown}
    >
      {virtual ? (
        <div style={{ height: virtualizer.getTotalSize(), position: 'relative' }}>
          {virtualizer.getVirtualItems().map((item) => row(spans[item.index], item.index, item.start))}
        </div>
      ) : spans.map((span, index) => row(span, index))}
    </Scroll>
  )
}
