import { useCallback, useId, useLayoutEffect, useMemo, useRef, useState, type CSSProperties, type KeyboardEvent } from 'react'
import { defaultRangeExtractor, useVirtualizer } from '@tanstack/react-virtual'
import { ChevronDown, ChevronRight, ChevronsDown, ChevronsRight, CircleAlert } from 'lucide-react'
import styled from 'styled-components'
import { Button } from '@/shared/components/ui/button'
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/shared/components/ui/tooltip'
import { Select, SelectTrigger, SelectValue, SelectContent, SelectItem } from '@/shared/components/ui/select'
import { focusVisibleRing, textRole } from '@/shared/theme'
import type { Span } from '../../api/debug-api'
import { getHttpStatus, isErrorSpan, spanKey, spanStartTime, type TraceWaterfall, type TraceWaterfallRow } from './span-inspector'
import { SpanInspector } from './SpanInspector'
import { spanServiceColor } from './span-timeline-color'
import { formatDurationMs } from './format'

const ROW_HEIGHT = 32
const AXIS_HEIGHT = 40
const TICKS = [0, 25, 50, 75, 100]
const ZOOM_LEVELS = [1, 2, 4, 8]

const Root = styled.div`
  min-width: 0;
  width: 100%;
`
const Toolbar = styled.div`
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-between;
  gap: var(--space-md);
  margin-bottom: var(--space-md);
`
const Origin = styled.div`
  min-width: 0;
  overflow-wrap: anywhere;
  color: var(--color-muted-foreground);
  ${textRole('mono-sm')}
`
const Zoom = styled.div`
  width: 120px;
`
const Scroll = styled.div`
  --timeline-label-width: 320px;
  max-height: 38rem;
  min-width: 0;
  width: 100%;
  overflow: auto;
  overflow-anchor: none;
  isolation: isolate;
  border: 1px solid var(--color-border);
  border-radius: ${({ theme }) => theme.radii.md};
  box-sizing: border-box;
  scroll-padding-top: ${AXIS_HEIGHT}px;
  scroll-padding-inline-start: var(--timeline-label-width);
  ${textRole('body-sm')}
  ${focusVisibleRing}
  @media (max-width: 640px) { --timeline-label-width: 160px; }
`
const Canvas = styled.div<{ $zoom: number }>`
  width: ${({ $zoom }) => `calc(${$zoom * 100}% - ${$zoom - 1} * var(--timeline-label-width))`};
  min-width: ${({ $zoom }) => `calc(var(--timeline-label-width) + ${440 * $zoom}px)`};
`
const Axis = styled.div`
  position: sticky;
  top: 0;
  z-index: 4;
  display: grid;
  grid-template-columns: var(--timeline-label-width) minmax(0, 1fr);
  height: ${AXIS_HEIGHT}px;
  background: var(--color-card);
  border-bottom: 1px solid var(--color-border);
  box-sizing: border-box;
  ${textRole('mono-sm')}
`
const AxisLabel = styled.div`
  position: sticky;
  left: 0;
  z-index: 2;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: var(--space-md);
  padding-inline: var(--space-md);
  background: var(--color-card);
  border-right: 1px solid var(--color-border);
  ${textRole('body-sm')}
  & > span { min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
`
const TreeActions = styled.div`
  display: flex;
  flex-shrink: 0;
`
const AxisTrack = styled.div`
  position: relative;
  min-width: 0;
`
const Tick = styled.span`
  position: absolute;
  top: 0;
  height: 100%;
  display: flex;
  align-items: center;
  transform: translateX(-50%);
  white-space: nowrap;
  color: var(--color-muted-foreground);
  &:first-child { transform: none; padding-left: 4px; }
  &:last-child { transform: translateX(-100%); padding-right: 4px; }
`
const Group = styled.div`
  background: var(--color-card);
`
const Row = styled.div`
  display: grid;
  grid-template-columns: var(--timeline-label-width) minmax(0, 1fr);
  height: ${ROW_HEIGHT}px;
  box-sizing: border-box;
  cursor: pointer;
  outline: none;
  &[aria-selected='true'] { background: var(--color-highlight); }
  &:focus-visible { outline: 2px solid var(--color-ring); outline-offset: -2px; }
`
const Label = styled.div`
  position: sticky;
  left: 0;
  z-index: 2;
  min-width: 0;
  display: flex;
  align-items: center;
  padding-inline: var(--space-md);
  border-right: 1px solid var(--color-border);
  background: var(--color-card);
  box-sizing: border-box;
  overflow: hidden;
  [aria-selected='true'] > & { background: var(--color-highlight); }
`
const Name = styled.div`
  display: flex;
  min-width: 0;
  align-items: center;
  width: 100%;
  height: 100%;
  position: relative;
  box-sizing: border-box;
`
const Guide = styled.span`
  position: absolute;
  top: 0;
  bottom: 0;
  border-left: 1px solid var(--color-border);
  pointer-events: none;
`
const BranchButton = styled(Button)`
  width: 20px;
  height: 24px;
  padding: 0;
`
const BranchSpace = styled.span`
  width: 20px;
  flex-shrink: 0;
`
const Marker = styled.span`
  height: 22px;
  width: 4px;
  flex-shrink: 0;
  margin-inline-end: var(--space-md);
  @media (max-width: 640px) { margin-inline-end: 4px; }
`
const Text = styled.span`
  min-width: 0;
  flex: 1;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
`
const Operation = styled.span`
  min-width: 0;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  color: var(--color-muted-foreground);
`
const Status = styled.span<{ $error: boolean }>`
  display: inline-flex;
  align-items: center;
  gap: var(--space-md);
  flex-shrink: 0;
  max-width: 100%;
  overflow: hidden;
  color: ${({ $error }) => $error ? 'var(--color-destructive)' : 'var(--color-muted-foreground)'};
  ${textRole('mono-sm')}
  & svg { width: 12px; height: 12px; flex-shrink: 0; }
  @media (max-width: 640px) {
    max-width: 12px;
    & > span { display: none; }
    &[data-error='false'] { display: none; }
  }
`
const Track = styled.div`
  position: relative;
  min-width: 0;
  overflow: hidden;
`
const Gridline = styled.span`
  position: absolute;
  top: 0;
  bottom: 0;
  border-left: 1px solid var(--color-border);
  pointer-events: none;
  &[data-position='100'] { transform: translateX(-1px); }
`
const Bar = styled.div`
  position: absolute;
  top: 10px;
  height: 12px;
  min-width: 2px;
  border-radius: var(--radius-sm);
  box-sizing: border-box;
`
const Duration = styled.span`
  position: absolute;
  top: 50%;
  white-space: nowrap;
  color: var(--color-foreground);
  ${textRole('mono-sm')}
`
const Details = styled.div`
  display: grid;
  grid-template-columns: var(--timeline-label-width) minmax(0, 1fr);
  border-top: 2px solid var(--span-service-color);
  border-bottom: 1px solid var(--color-border);
  & > [role='gridcell']:first-child {
    position: sticky;
    left: 0;
    background: var(--color-highlight);
    border-right: 1px solid var(--color-border);
    border-left: 4px solid var(--span-service-color);
  }
  & > [role='gridcell']:last-child { min-width: 0; }
  @media (max-width: 640px) {
    display: block;
    position: sticky;
    left: 0;
    width: var(--timeline-viewport-width);
    & > [role='gridcell']:first-child { display: none; }
  }
`

interface Props {
  timeline: TraceWaterfall
  label: string
  selectedKey?: string
  onSelect: (span: Span) => void
  onClose?: () => void
  hierarchical?: boolean
}
interface ViewAnchor { key: string; offset: number; left: number }

function timelineDuration(durationUs: number): string {
  if (durationUs > 0 && durationUs < 100) return `${(durationUs / 1000).toFixed(3)} ms`
  return formatDurationMs(durationUs)
}

export function SpanTimeline({ timeline, label, selectedKey, onSelect, onClose, hierarchical = false }: Props) {
  const id = useId()
  const scrollRef = useRef<HTMLDivElement>(null)
  const axisTrackRef = useRef<HTMLDivElement>(null)
  const anchorRef = useRef<ViewAnchor | null>(null)
  const [activeKey, setActiveKey] = useState<string | null>(null)
  const [collapsed, setCollapsed] = useState<Set<string>>(() => new Set())
  const [zoom, setZoom] = useState(1)
  const [timingWidth, setTimingWidth] = useState(440)
  const model = useMemo(() => {
    const children = new Set<string>()
    const ancestors = new Map<string, string[]>()
    const stack: string[] = []
    for (const row of timeline.rows) {
      const key = spanKey(row.span)
      stack.length = hierarchical ? row.depth : 0
      ancestors.set(key, [...stack])
      if (stack.length) children.add(stack[stack.length - 1])
      stack.push(key)
    }
    return { children, ancestors }
  }, [timeline.rows, hierarchical])
  const rows = useMemo(() => timeline.rows.filter((row) => !model.ancestors.get(spanKey(row.span))?.some((key) => collapsed.has(key))), [timeline.rows, model, collapsed])
  const activeIndex = Math.max(0, rows.findIndex(({ span }) => spanKey(span) === (activeKey ?? selectedKey)))
  const selectedIndex = rows.findIndex(({ span }) => spanKey(span) === selectedKey)
  const virtual = rows.length > 50
  const virtualizer = useVirtualizer({
    count: virtual ? rows.length : 0,
    getScrollElement: () => scrollRef.current,
    estimateSize: () => ROW_HEIGHT,
    getItemKey: (index) => spanKey(rows[index].span),
    overscan: 5,
    scrollMargin: AXIS_HEIGHT,
    initialRect: { width: 720, height: 480 },
    rangeExtractor: (range) => [...new Set([...defaultRangeExtractor(range), activeIndex, ...(selectedIndex >= 0 ? [selectedIndex] : [])])].sort((a, b) => a - b),
  })

  useLayoutEffect(() => {
    const scroll = scrollRef.current
    const track = axisTrackRef.current
    if (!scroll || !track) return
    const observer = new ResizeObserver(() => {
      setTimingWidth(track.clientWidth)
      scroll.style.setProperty('--timeline-viewport-width', `${scroll.clientWidth}px`)
    })
    observer.observe(scroll)
    observer.observe(track)
    setTimingWidth(track.clientWidth)
    scroll.style.setProperty('--timeline-viewport-width', `${scroll.clientWidth}px`)
    return () => observer.disconnect()
  }, [])

  const rememberAnchor = useCallback(() => {
    const scroll = scrollRef.current
    if (!scroll) return
    const top = scroll.getBoundingClientRect().top + AXIS_HEIGHT
    const group = Array.from(scroll.querySelectorAll<HTMLElement>('[data-span-group]')).find((element) => element.getBoundingClientRect().bottom > top)
    if (group) anchorRef.current = { key: group.dataset.spanGroup!, offset: group.getBoundingClientRect().top - top, left: scroll.scrollLeft }
  }, [])

  useLayoutEffect(() => {
    const scroll = scrollRef.current
    const anchor = anchorRef.current
    if (!scroll) return
    if (!anchor) {
      rememberAnchor()
      return
    }
    const index = rows.findIndex((row) => spanKey(row.span) === anchor.key)
    if (index < 0) return
    function restore() {
      if (!scroll || !anchor) return
      const group = Array.from(scroll.querySelectorAll<HTMLElement>('[data-span-group]')).find((element) => element.dataset.spanGroup === anchor.key)
      if (group) scroll.scrollTop += group.getBoundingClientRect().top - scroll.getBoundingClientRect().top - AXIS_HEIGHT - anchor.offset
      scroll.scrollLeft = anchor.left
    }
    const mounted = Array.from(scroll.querySelectorAll<HTMLElement>('[data-span-group]')).some((element) => element.dataset.spanGroup === anchor.key)
    if (!mounted && virtual) virtualizer.scrollToIndex(index, { align: 'start' })
    restore()
    const frame = requestAnimationFrame(restore)
    return () => cancelAnimationFrame(frame)
  }, [rows, virtual, virtualizer, rememberAnchor])

  useLayoutEffect(() => {
    rememberAnchor()
  })

  function focusRow(key: string, reveal = false) {
    setActiveKey(key)
    const index = rows.findIndex((row) => spanKey(row.span) === key)
    if (reveal && virtual && index >= 0) virtualizer.scrollToIndex(index, { align: 'auto' })
    requestAnimationFrame(() => {
      const row = document.getElementById(`${id}-${key}`)
      row?.focus({ preventScroll: true })
      if (reveal && !virtual) row?.scrollIntoView?.({ block: 'nearest', inline: 'nearest' })
    })
  }

  function closeDetails() {
    onClose?.()
    if (selectedKey) focusRow(selectedKey)
  }

  function expandBranch(key: string) {
    setCollapsed((previous) => {
      const next = new Set(previous)
      next.delete(key)
      return next
    })
  }

  function collapseBranch(key: string) {
    setCollapsed((previous) => new Set(previous).add(key))
    if (selectedKey && model.ancestors.get(selectedKey)?.includes(key)) onClose?.()
    focusRow(key)
  }

  function onKeyDown(event: KeyboardEvent<HTMLDivElement>) {
    if (!(event.target instanceof HTMLElement) || event.target.closest('[data-span-detail]') || event.target.closest('button, input, select, textarea, a')) return
    const row = rows[activeIndex]
    if (!row) return
    const key = spanKey(row.span)
    let next: number
    if (event.key === 'ArrowDown') next = Math.min(rows.length - 1, activeIndex + 1)
    else if (event.key === 'ArrowUp') next = Math.max(0, activeIndex - 1)
    else if (event.key === 'Home') next = 0
    else if (event.key === 'End') next = rows.length - 1
    else if (event.key === 'Escape' && selectedKey) {
      event.preventDefault()
      closeDetails()
      return
    }
    else if (event.key === 'Enter' || event.key === ' ') {
      event.preventDefault()
      if (hierarchical && key === selectedKey) closeDetails()
      else onSelect(row.span)
      return
    } else if (hierarchical && event.key === 'ArrowLeft') {
      event.preventDefault()
      if (model.children.has(key) && !collapsed.has(key)) collapseBranch(key)
      else {
        const parent = model.ancestors.get(key)?.at(-1)
        if (parent) focusRow(parent, true)
      }
      return
    } else if (hierarchical && event.key === 'ArrowRight') {
      event.preventDefault()
      if (collapsed.has(key)) expandBranch(key)
      else if (model.children.has(key) && rows[activeIndex + 1]) focusRow(spanKey(rows[activeIndex + 1].span), true)
      return
    } else return
    event.preventDefault()
    focusRow(spanKey(rows[next].span), true)
  }

  function renderRow(row: TraceWaterfallRow, index: number, virtualStart?: number) {
    const { span } = row
    const key = spanKey(span)
    const status = getHttpStatus(span)
    const error = isErrorSpan(span)
    const color = spanServiceColor(span.service)
    const hasChildren = hierarchical && model.children.has(key)
    const selected = key === selectedKey
    const duration = timelineDuration(row.durationUs)
    const durationWidth = duration.length * 8
    const end = row.offsetPercent + row.widthPercent
    const before = (100 - end) * timingWidth / 100 < durationWidth + 12
    const position = before ? (row.offsetPercent * timingWidth / 100 >= durationWidth + 8 ? row.offsetPercent : end) : end
    return (
      <Group
        key={key}
        role="rowgroup"
        data-span-group={key}
        data-index={index}
        ref={virtual ? virtualizer.measureElement : undefined}
        style={virtual ? { position: 'absolute', top: 0, left: 0, width: '100%', transform: `translateY(${virtualStart! - AXIS_HEIGHT}px)` } : undefined}
      >
        <Row
          id={`${id}-${key}`}
          role="row"
          aria-selected={selected}
          aria-label={`${span.service} · ${span.operation_name} · ${duration}${error ? ' · Error' : ''}`}
          aria-level={hierarchical ? row.depth + 1 : undefined}
          aria-expanded={hasChildren ? !collapsed.has(key) : undefined}
          aria-rowindex={index + 2 + (hierarchical && selectedIndex >= 0 && index > selectedIndex ? 1 : 0)}
          data-span-row={key}
          data-active={index === activeIndex}
          tabIndex={index === activeIndex ? 0 : -1}
          onFocus={() => setActiveKey(key)}
          onClick={() => {
            setActiveKey(key)
            if (hierarchical && selected) closeDetails()
            else onSelect(span)
            document.getElementById(`${id}-${key}`)?.focus({ preventScroll: true })
          }}
        >
          <Label role="gridcell" data-timeline-label title={`${span.service} · ${span.operation_name}${row.missingParent ? ` · Missing parent: ${span.parent_span_id}` : ''}`}>
            <Name style={{ paddingInlineStart: `${Math.min(row.depth, 8) * 12}px` }}>
              {Array.from({ length: Math.min(row.depth, 8) }, (_, depth) => <Guide key={depth} style={{ left: `${depth * 12 + 9}px` }} aria-hidden="true" />)}
              {hasChildren ? (
                <Tooltip>
                  <TooltipTrigger asChild>
                    <BranchButton variant="ghost" size="xs" aria-label={`${collapsed.has(key) ? 'Expand' : 'Collapse'} ${span.operation_name}`} aria-expanded={!collapsed.has(key)} onClick={(event) => {
                      event.stopPropagation()
                      if (collapsed.has(key)) expandBranch(key)
                      else collapseBranch(key)
                    }}>{collapsed.has(key) ? <ChevronRight /> : <ChevronDown />}</BranchButton>
                  </TooltipTrigger>
                  <TooltipContent>{collapsed.has(key) ? 'Expand' : 'Collapse'} {span.operation_name}</TooltipContent>
                </Tooltip>
              ) : <BranchSpace />}
              <Marker style={{ background: color }} aria-hidden="true" />
              <Text><span>{span.service}</span>{' '}<Operation>{span.operation_name}</Operation></Text>
              {(error || status !== null) && <Status $error={error} data-error={error} title={error ? ['Error', status].filter(Boolean).join(' ') : String(status)}>{error && <CircleAlert aria-hidden="true" />}<span>{error ? ['Error', status].filter(Boolean).join(' ') : status}</span></Status>}
              {row.missingParent && <Status $error={false} data-error="false">Missing parent</Status>}
            </Name>
          </Label>
          <Track role="gridcell" data-timeline-track>
            {TICKS.map((position) => <Gridline key={position} data-timeline-gridline={position} data-position={position} style={{ left: `${position}%` }} aria-hidden="true" />)}
            <Bar data-timeline-bar aria-hidden="true" style={{ left: `min(${row.offsetPercent}%, calc(100% - 2px))`, width: `${row.widthPercent}%`, background: color, border: error ? '1px solid var(--color-destructive)' : undefined }} />
            <Duration style={{ left: `${position}%`, transform: before ? 'translate(calc(-100% - 6px), -50%)' : 'translate(6px, -50%)' }}>{duration}</Duration>
          </Track>
        </Row>
        {hierarchical && selected && <Details role="row" aria-rowindex={index + 3} data-span-detail style={{ '--span-service-color': color } as CSSProperties}>
          <div role="gridcell" aria-hidden="true" />
          <div role="gridcell"><SpanInspector span={span} hidden={false} offsetUs={row.offsetUs} onClose={closeDetails} /></div>
        </Details>}
      </Group>
    )
  }

  return (
    <TooltipProvider><Root>
      <Toolbar>
        <Origin>Origin: <time dateTime={spanStartTime(timeline.startTimeUs)}>{spanStartTime(timeline.startTimeUs)}</time></Origin>
        <Zoom>
          <Select value={String(zoom)} onValueChange={(value) => {
            setZoom(Number(value))
            if (value === '1' && scrollRef.current) scrollRef.current.scrollLeft = 0
          }}>
            <SelectTrigger aria-label="Timeline zoom"><SelectValue /></SelectTrigger>
            <SelectContent>{ZOOM_LEVELS.map((level) => <SelectItem key={level} value={String(level)}>{level === 1 ? 'Fit' : `${level}×`}</SelectItem>)}</SelectContent>
          </Select>
        </Zoom>
      </Toolbar>
      <Scroll ref={scrollRef} role="treegrid" aria-label={label} aria-rowcount={rows.length + 1 + (hierarchical && selectedIndex >= 0 ? 1 : 0)} aria-colcount={2} tabIndex={-1} onKeyDown={onKeyDown} onScroll={rememberAnchor}>
        <Canvas $zoom={zoom}>
          <Axis role="row" aria-rowindex={1}>
            <AxisLabel role="columnheader">
              <span>Service &amp; Operation</span>
              {hierarchical && <TreeActions>
                <Tooltip>
                  <TooltipTrigger asChild><Button variant="ghost" size="xs" aria-label="Expand all spans" onClick={() => setCollapsed(new Set())}><ChevronsDown /></Button></TooltipTrigger>
                  <TooltipContent>Expand all spans</TooltipContent>
                </Tooltip>
                <Tooltip>
                  <TooltipTrigger asChild>
                    <Button variant="ghost" size="xs" aria-label="Collapse all spans" onClick={() => {
                      setCollapsed(new Set(model.children))
                      const ancestor = selectedKey ? model.ancestors.get(selectedKey)?.[0] : undefined
                      if (ancestor) {
                        onClose?.()
                        focusRow(ancestor)
                      }
                    }}><ChevronsRight /></Button>
                  </TooltipTrigger>
                  <TooltipContent>Collapse all spans</TooltipContent>
                </Tooltip>
              </TreeActions>}
            </AxisLabel>
            <AxisTrack ref={axisTrackRef} role="columnheader" data-timeline-axis>
              {TICKS.map((position) => <Tick key={position} data-timeline-tick={position} data-position={position} style={{ left: `${position}%` }}>{formatDurationMs(timeline.durationUs * position / 100)}</Tick>)}
            </AxisTrack>
          </Axis>
          {virtual ? <div role="presentation" style={{ height: virtualizer.getTotalSize(), position: 'relative' }}>{virtualizer.getVirtualItems().map((item) => renderRow(rows[item.index], item.index, item.start))}</div> : rows.map((row, index) => renderRow(row, index))}
        </Canvas>
      </Scroll>
    </Root></TooltipProvider>
  )
}
