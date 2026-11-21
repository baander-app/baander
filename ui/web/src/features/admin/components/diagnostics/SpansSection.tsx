import { useMemo, useRef, useState } from 'react'
import styled from 'styled-components'
import { Button } from '@/shared/components/ui/button'
import { useClearSpans, useSpans } from '../../hooks/use-debug-stats'
import type { Span } from '../../api/debug-api'
import { DiagnosticsCard } from './DiagnosticsCard'
import { SectionError, SectionSkeleton } from './SectionStates'
import { DEFAULT_SPAN_FILTERS, filterSpans, spanKey, validateSpanFilters } from './span-inspector'
import { SpanFilters } from './SpanFilters'
import { SpanResults } from './SpanResults'
import { SpanInspector, SpanTrace } from './SpanInspector'
import { Actions, ErrorNotice, InspectorBody, Notice } from './span-inspector-styles'

const Layout = styled.div<{ $inspecting: boolean }>`
  display: grid;
  grid-template-columns: ${({ $inspecting }) => $inspecting ? 'minmax(0, 1fr) minmax(0, 360px)' : 'minmax(0, 1fr)'};
  gap: var(--space-md);
  align-items: start;
  @media (max-width: 767px) { grid-template-columns: minmax(0, 1fr); }
`
export function SpansSection() {
  const { data: spans, isPending, isFetching, error, refetch } = useSpans(500)
  const clearSpans = useClearSpans()
  const [inputs, setInputs] = useState(DEFAULT_SPAN_FILTERS)
  const [selectedKey, setSelectedKey] = useState<string | undefined>()
  const resultsRef = useRef<HTMLDivElement>(null)
  const validation = useMemo(() => validateSpanFilters(inputs), [inputs])
  const results = useMemo(() => validation.filters && spans ? filterSpans(spans, validation.filters) : [], [spans, validation.filters])
  const services = useMemo(() => [...new Set([...(spans?.map((span) => span.service) ?? []), inputs.service])].filter(Boolean).sort(), [spans, inputs.service])
  const selected = spans?.find((span) => spanKey(span) === selectedKey)
  function select(span: Span) {
    setSelectedKey(spanKey(span))
  }
  function close() {
    setSelectedKey(undefined)
    resultsRef.current?.querySelector<HTMLElement>('[role="listbox"]')?.focus()
  }
  const actions = <Actions>
    <Button variant="ghost" size="xs" disabled={isFetching} onClick={() => refetch()}>Refresh</Button>
    <Button variant="ghost" size="xs" disabled={clearSpans.isPending} onClick={() => clearSpans.mutate(undefined, { onSuccess: () => setSelectedKey(undefined) })}>Clear</Button>
  </Actions>
  return (
    <DiagnosticsCard title="Recent spans" action={actions}>
      <InspectorBody>
        {clearSpans.isError && <ErrorNotice role="alert">Failed to clear spans.</ErrorNotice>}
        {isPending ? <SectionSkeleton /> : !spans ? <SectionError message="Failed to load spans." onRetry={() => refetch()} /> : <>
          {error && <ErrorNotice role="alert">Failed to refresh spans. Showing the last retained snapshot.</ErrorNotice>}
          <Notice>Search the current snapshot of up to 500 completed spans. New traffic and restarts remove older spans. Refresh to fetch the latest snapshot.</Notice>
          <SpanFilters value={inputs} services={services} errors={validation.errors} onChange={setInputs} onReset={() => setInputs(DEFAULT_SPAN_FILTERS)} />
          {selectedKey && !selected && <Notice role="status">The selected span is no longer retained.</Notice>}
          <Layout $inspecting={!!selected}>
            <div ref={resultsRef} style={{ minWidth: 0 }}>
              <Notice>{results.length} of {spans.length} retained spans</Notice>
              {spans.length === 0 ? <Notice>No spans recorded.</Notice> : !validation.filters ? <Notice>Correct the duration filters to show results.</Notice> : results.length === 0 ? <Notice>No spans match the current filters.</Notice> : <SpanResults spans={results} selectedKey={selectedKey} onSelect={select} />}
              {selected && <SpanTrace span={selected} snapshot={spans} onSelect={select} />}
            </div>
            {selected && <SpanInspector span={selected} hidden={!results.some((span) => spanKey(span) === selectedKey)} onClose={close} />}
          </Layout>
        </>}
      </InspectorBody>
    </DiagnosticsCard>
  )
}
