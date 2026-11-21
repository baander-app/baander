import { useEffect, useMemo, useRef, useState } from 'react'
import { Button } from '@/shared/components/ui/button'
import { useClearSpans } from '../../hooks/use-debug-stats'
import { useSpanSnapshot } from '../../hooks/use-span-snapshot'
import type { Span } from '../../api/debug-api'
import { DiagnosticsCard } from './DiagnosticsCard'
import { SectionError, SectionSkeleton } from './SectionStates'
import { buildSpanTimeline, buildTraceWaterfall, DEFAULT_SPAN_FILTERS, filterSpans, spanKey, validateSpanFilters } from './span-inspector'
import { SpanFilters } from './SpanFilters'
import { SpanTimeline } from './SpanTimeline'
import { SpanTraceHeader } from './SpanTraceHeader'
import { Actions, ErrorNotice, InspectorBody, Notice } from './span-inspector-styles'

export function SpansSection() {
  const { spans, live, setLive, isPending, isFetching, error, refresh, clearSnapshot } = useSpanSnapshot()
  const clearSpans = useClearSpans()
  const [inputs, setInputs] = useState(DEFAULT_SPAN_FILTERS)
  const [focusedKey, setFocusedKey] = useState<string | undefined>()
  const [selectedKey, setSelectedKey] = useState<string | undefined>()
  const [evicted, setEvicted] = useState(false)
  const resultsRef = useRef<HTMLDivElement>(null)
  const restoreFocus = useRef(false)
  const validation = useMemo(() => validateSpanFilters(inputs), [inputs])
  const results = useMemo(() => validation.filters && spans ? filterSpans(spans, validation.filters) : [], [spans, validation.filters])
  const services = useMemo(() => [...new Set([...(spans?.map((span) => span.service) ?? []), inputs.service])].filter(Boolean).sort(), [spans, inputs.service])
  const focused = spans?.find((span) => spanKey(span) === focusedKey)
  const selected = spans?.find((span) => spanKey(span) === selectedKey)
  const traceId = focused?.trace_id
  const overview = useMemo(() => buildSpanTimeline(results), [results])
  const trace = useMemo(() => spans && traceId ? buildTraceWaterfall(spans, traceId) : null, [spans, traceId])

  if ((focusedKey && !focused) || (selectedKey && !selected)) {
    setFocusedKey(undefined)
    setSelectedKey(undefined)
    setEvicted(true)
  }

  useEffect(() => {
    if (!focusedKey && restoreFocus.current) {
      restoreFocus.current = false
      resultsRef.current?.querySelector<HTMLElement>('[data-span-row]')?.focus()
    }
  }, [focusedKey])

  function openTrace(span: Span) {
    setFocusedKey(spanKey(span))
    setSelectedKey(undefined)
    setEvicted(false)
  }

  function backToResults() {
    restoreFocus.current = true
    setFocusedKey(undefined)
    setSelectedKey(undefined)
  }

  const actions = (
    <Actions>
      <Button variant={live ? 'secondary' : 'outline'} size="xs" aria-label="Live updates" aria-pressed={live} onClick={() => setLive(!live)}>{live ? 'Pause' : 'Live'}</Button>
      <Button variant="ghost" size="xs" disabled={isFetching} onClick={() => refresh()}>Refresh</Button>
      <Button variant="ghost" size="xs" disabled={clearSpans.isPending} onClick={() => clearSpans.mutate(undefined, {
        onSuccess: async () => {
          await clearSnapshot()
          setSelectedKey(undefined)
          setFocusedKey(undefined)
          setEvicted(false)
        },
      })}>Clear</Button>
    </Actions>
  )

  return (
    <DiagnosticsCard title="Recent spans" action={actions}>
      <InspectorBody>
        {clearSpans.isError && <ErrorNotice role="alert">Failed to clear spans.</ErrorNotice>}
        {isPending && !spans ? <SectionSkeleton /> : !spans ? <SectionError message="Failed to load spans." onRetry={() => refresh()} /> : <>
          {error && <ErrorNotice role="alert">Failed to refresh spans. Showing the last retained snapshot.</ErrorNotice>}
          {evicted && <Notice role="status">The selected span is no longer retained.</Notice>}
          {trace && focused ? <>
            <SpanTraceHeader timeline={trace} span={focused} onBack={backToResults} />
            <SpanTimeline key={traceId} timeline={trace} label="Trace waterfall" hierarchical selectedKey={selectedKey} onSelect={(span) => setSelectedKey(spanKey(span))} onClose={() => setSelectedKey(undefined)} />
          </> : <>
            <Notice>{live ? 'Live updates enabled.' : 'Snapshot paused. Refresh to load new spans, or enable Live.'} Search up to 500 retained spans; open a row to inspect its trace.</Notice>
            <SpanFilters value={inputs} services={services} errors={validation.errors} onChange={setInputs} onReset={() => setInputs(DEFAULT_SPAN_FILTERS)} />
            <div ref={resultsRef} style={{ minWidth: 0 }}>
              <Notice>{results.length} of {spans.length} retained spans</Notice>
              {spans.length === 0 ? <Notice>No spans recorded.</Notice> : !validation.filters ? <Notice>Correct the duration filters to show results.</Notice> : results.length === 0 ? <Notice>No spans match the current filters.</Notice> : (
                <SpanTimeline key="results" timeline={overview} label="Spans" onSelect={openTrace} />
              )}
            </div>
          </>}
        </>}
      </InspectorBody>
    </DiagnosticsCard>
  )
}
