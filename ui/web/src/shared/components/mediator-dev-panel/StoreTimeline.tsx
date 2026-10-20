import { memo, useMemo, useState, useSyncExternalStore } from 'react'
import styled from 'styled-components'
import { storeDebugger, type createStoreDebugger, type StateCapture, type StoreTraceEvent } from '@/shared/stores/debug'
import { Button } from '@/shared/components/ui/button'
import { reconstructCaptures } from '@/shared/stores/reconstruct-captures'

const Container = styled.div`
  flex: 1;
  min-height: 0;
  overflow: auto;
  padding: 16px;
  color: var(--color-foreground);
  font-size: 12px;
  input { width: 100%; background: var(--color-background); color: inherit; border: 1px solid var(--color-border); padding: 8px; }
  textarea { width: 100%; background: var(--color-background); color: inherit; border: 1px solid var(--color-border); }
  details { border-bottom: 1px solid var(--color-border); padding: 8px 0; }
  summary { cursor: pointer; }
  pre { white-space: pre-wrap; overflow-wrap: anywhere; font-family: var(--font-mono); }
`

const EventRow = styled.div<{ $selected: boolean }>`
  border-left: 2px solid ${({ $selected }) => $selected ? 'var(--color-primary)' : 'transparent'};
  padding-left: 16px;
`

const Inspection = styled.section`
  margin: 16px 0;
  padding: 16px;
  background: var(--color-card);
  border-radius: 12px;
  h2, h3 { font-size: inherit; font-weight: 600; }
`

function Capture({ label, capture }: { label: string; capture?: StateCapture }) {
  return <>
    <h3>{label}</h3>
    {capture ? <>
      <p>{capture.complete ? 'Complete capture' : 'Incomplete capture'}</p>
      <pre>{JSON.stringify(capture.value, null, 2)}</pre>
    </> : <p>Capture unavailable.</p>}
  </>
}

const EventDetails = memo(function EventDetails({ event }: { event: StoreTraceEvent }) {
  return <details>
    <summary>#{event.id} {event.store} · {event.action} · {event.kind}{event.parentId !== null ? ` ← #${event.parentId}` : ''}</summary>
    {event.changedKeys && <p>Changed: {event.changedKeys.join(', ')}</p>}
    {event.args && <><strong>Arguments</strong><pre>{JSON.stringify(event.args, null, 2)}</pre></>}
    {event.before && <><strong>Before</strong><pre>{JSON.stringify(event.before, null, 2)}</pre></>}
    {event.after && <><strong>After</strong><pre>{JSON.stringify(event.after, null, 2)}</pre></>}
    {event.stack && <><strong>Call stack</strong><pre>{event.stack}</pre></>}
  </details>
})

export function StoreTimeline({ debugger: recorder = storeDebugger }: { debugger?: ReturnType<typeof createStoreDebugger> }) {
  const state = useSyncExternalStore(recorder.subscribe, recorder.getSnapshot, recorder.getSnapshot)
  const [filter, setFilter] = useState('')
  const [exported, setExported] = useState<string | null>(null)
  const [selectedId, setSelectedId] = useState<number | null>(null)
  const events = state.events.filter(event => `${event.store} ${event.action} ${event.kind}`.toLowerCase().includes(filter.toLowerCase()))
  const selectedIndex = events.findIndex(event => event.id === selectedId)
  const selectedEvent = events[selectedIndex]
  if (selectedId !== null && selectedIndex === -1) setSelectedId(null)
  const history = useMemo(() => selectedEvent ? reconstructCaptures(state, selectedEvent.id) : null, [state, selectedEvent])

  return <Container>
    <label>Filter store actions<input value={filter} onChange={event => setFilter(event.target.value)} /></label>
    <Button size="sm" onClick={() => recorder.clear()}>Clear timeline</Button>
    <Button size="sm" onClick={() => setExported(recorder.exportTrace())}>Export trace</Button>
    <p>{state.events.length} recorded events · {state.droppedEvents} older events discarded</p>
    {state.omittedRegistrations > 0 && <p>Omitted registrations: {state.omittedRegistrations} (100 active instance limit).</p>}
    <p>Parent IDs link synchronous calls. Async completions retain their action ID; updates after await are shown separately, without guessed parentage.</p>
    {exported && <label>Trace JSON<textarea readOnly aria-label="Trace JSON" value={exported} rows={8} /></label>}
    <Button size="sm" disabled={selectedIndex <= 0} onClick={() => setSelectedId(events[selectedIndex - 1].id)}>Previous event</Button>
    <Button size="sm" disabled={selectedIndex === -1 || selectedIndex === events.length - 1} onClick={() => setSelectedId(events[selectedIndex + 1].id)}>Next event</Button>
    <Inspection aria-label="Captured state">
      <h2>Captured state</h2>
      {selectedEvent ? <>
        <p>#{selectedEvent.id} {selectedEvent.store} · {selectedEvent.action} · {selectedEvent.kind}</p>
        {selectedEvent.kind === 'update' ? <>
          <Capture label="Before capture" capture={selectedEvent.before} />
          <Capture label="After capture" capture={selectedEvent.after} />
        </> : selectedEvent.kind === 'register'
          ? <Capture label="Registration capture" capture={selectedEvent.after} />
          : <p>This event has no state capture.</p>}
      </> : <p>Select an event to inspect its captured state.</p>}
    </Inspection>
    <Inspection aria-label="Historical store captures">
      <h2>Historical store captures</h2>
      {!history ? <p>Select an event to inspect recorded stores.</p>
        : history.status === 'unavailable' ? <p>Historical captures are unavailable.</p>
          : <>
            <p>Through event #{selectedEvent.id} · history epoch {state.baseline.epoch}</p>
            <p>{history.partial ? 'Partial diagnostic captures' : 'Complete diagnostic captures'}; inspection does not restore executable state.</p>
            {Object.keys(history.stores).length === 0 && <p>No recorded stores at this event.</p>}
            {Object.entries(history.stores).map(([id, entry]) => <details key={id} open>
              <summary>{entry.name} #{id}</summary>
              <p>Captured at event #{entry.sourceEventId}</p>
              <Capture label="State capture" capture={entry.capture} />
            </details>)}
          </>}
    </Inspection>
    {events.length === 0 && <p>No matching store events.</p>}
    {events.map(event => <EventRow key={event.id} $selected={event.id === selectedId}>
      <Button size="sm" variant="ghost" aria-label={`Inspect event #${event.id}`} aria-pressed={event.id === selectedId} onClick={() => setSelectedId(event.id)}>Inspect event #{event.id}</Button>
      <EventDetails event={event} />
    </EventRow>)}
  </Container>
}
