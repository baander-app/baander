import { useState, useSyncExternalStore } from 'react'
import styled from 'styled-components'
import { storeDebugger, type createStoreDebugger } from '@/shared/stores/debug'
import { Button } from '@/shared/components/ui/button'

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

export function StoreTimeline({ debugger: recorder = storeDebugger }: { debugger?: ReturnType<typeof createStoreDebugger> }) {
  const state = useSyncExternalStore(recorder.subscribe, recorder.getSnapshot, recorder.getSnapshot)
  const [filter, setFilter] = useState('')
  const [exported, setExported] = useState<string | null>(null)
  const events = state.events.filter(event => `${event.store} ${event.action} ${event.kind}`.toLowerCase().includes(filter.toLowerCase()))
  return <Container>
    <label>Filter store actions<input value={filter} onChange={event => setFilter(event.target.value)} /></label>
    <Button size="sm" onClick={() => recorder.clear()}>Clear timeline</Button>
    <Button size="sm" onClick={() => setExported(recorder.exportTrace())}>Export trace</Button>
    <p>{state.events.length} recorded events · {state.droppedEvents} older events discarded</p>
    <p>Parent IDs link synchronous calls. Async completions retain their action ID; updates after await are shown separately, without guessed parentage.</p>
    {exported && <label>Trace JSON<textarea readOnly aria-label="Trace JSON" value={exported} rows={8} /></label>}
    {events.length === 0 && <p>No matching store events.</p>}
    {events.map(event => <details key={event.id}>
      <summary>#{event.id} {event.store} · {event.action} · {event.kind}{event.parentId !== null ? ` ← #${event.parentId}` : ''}</summary>
      {event.changedKeys && <p>Changed: {event.changedKeys.join(', ')}</p>}
      {event.args && <><strong>Arguments</strong><pre>{JSON.stringify(event.args, null, 2)}</pre></>}
      {event.before && <><strong>Before</strong><pre>{JSON.stringify(event.before, null, 2)}</pre></>}
      {event.after && <><strong>After</strong><pre>{JSON.stringify(event.after, null, 2)}</pre></>}
      {event.stack && <><strong>Call stack</strong><pre>{event.stack}</pre></>}
    </details>)}
  </Container>
}
