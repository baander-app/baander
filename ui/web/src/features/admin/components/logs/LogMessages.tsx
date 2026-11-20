import { useRef, type KeyboardEvent, type RefObject } from 'react'
import { useVirtualizer } from '@tanstack/react-virtual'
import styled from 'styled-components'
import { textRole } from '@/shared/theme'
import { Button } from '@/shared/components/ui/button'
import { safeLogText } from './log-text'
import type { LogRecord } from '../../api/logs-api'

const Viewport = styled.div`
  flex: 1;
  min-height: 180px;
  overflow: auto;
  position: relative;
`
const Row = styled(Button)`
  position: absolute;
  left: 0;
  top: 0;
  width: 100%;
  height: 32px;
  border: 0;
  border-bottom: 1px solid color-mix(in srgb, var(--color-border) 35%, transparent);
  border-radius: 0;
  display: grid;
  grid-template-columns: 105px 80px 120px minmax(0, 1fr);
  gap: 10px;
  padding: 0 12px;
  text-align: left;
  justify-content: initial;
  ${textRole('mono-sm')}
  color: var(--color-foreground);
  background: var(--color-muted);
  &[data-level='debug']:not([aria-selected='true']) { background: color-mix(in srgb, var(--color-info) 14%, transparent); }
  &[data-level='info']:not([aria-selected='true']) { background: color-mix(in srgb, var(--color-info) 24%, transparent); }
  &[data-level='notice']:not([aria-selected='true']) { background: color-mix(in srgb, var(--color-success) 18%, transparent); }
  &[data-level='warning']:not([aria-selected='true']) { background: color-mix(in srgb, var(--color-warning) 20%, transparent); }
  &[data-level='error']:not([aria-selected='true']) { background: color-mix(in srgb, var(--color-destructive) 18%, transparent); }
  &[data-level='critical']:not([aria-selected='true']) { background: color-mix(in srgb, var(--color-destructive) 24%, transparent); }
  &[data-level='alert']:not([aria-selected='true']) { background: color-mix(in srgb, var(--color-destructive) 28%, transparent); }
  &[data-level='emergency']:not([aria-selected='true']) { background: color-mix(in srgb, var(--color-destructive) 32%, transparent); }
  &:focus-visible:not([aria-selected='true']) { background: var(--color-muted); }
  &[aria-selected='true'] { background: var(--color-primary); color: var(--color-primary-foreground); }
  & > span { overflow: hidden; white-space: nowrap; text-overflow: ellipsis; }
  @media (max-width: 700px) { grid-template-columns: 80px 76px minmax(0, 1fr); & > span:nth-child(3) { display: none; } }
`
const Header = styled.div`
  display: grid;
  grid-template-columns: 105px 80px 120px minmax(0, 1fr);
  gap: 10px;
  padding: 6px 12px;
  ${textRole('caption')}
  color: var(--color-muted-foreground);
  border-bottom: 1px solid var(--color-border);
  @media (max-width: 700px) { grid-template-columns: 80px 76px minmax(0, 1fr); & > span:nth-child(3) { display: none; } }
`


function Message({ text, search }: { text: string; search: string }) {
  const clean = safeLogText(text)
  if (!search) return <>{clean}</>
  const index = clean.toLocaleLowerCase().indexOf(search.toLocaleLowerCase())
  if (index < 0) return <>{clean}</>
  return <>{clean.slice(0, index)}<mark>{clean.slice(index, index + search.length)}</mark>{clean.slice(index + search.length)}</>
}

interface LogMessagesProps {
  listRef?: RefObject<HTMLDivElement | null>
  records: LogRecord[]
  selectedId: string | null
  onSelect: (id: string) => void
  search: string
}

export function LogMessages({ listRef, records, selectedId, onSelect, search }: LogMessagesProps) {
  const fallbackRef = useRef<HTMLDivElement>(null)
  const scrollRef = listRef ?? fallbackRef
  const virtualizer = useVirtualizer({
    count: records.length,
    getScrollElement: () => scrollRef.current,
    estimateSize: () => 32,
    getItemKey: index => records[index].id,
    overscan: 8,
  })
  const virtualRows = virtualizer.getVirtualItems()
  const activeId = virtualRows.some(item => records[item.index].id === selectedId) ? `log-row-${encodeURIComponent(selectedId!)}` : undefined
  function moveSelection(event: KeyboardEvent) {
    if (!['ArrowDown', 'ArrowUp', 'Home', 'End'].includes(event.key) || !records.length) return
    event.preventDefault()
    const current = records.findIndex(record => record.id === selectedId)
    const target = event.key === 'Home' ? 0 : event.key === 'End' ? records.length - 1
      : Math.max(0, Math.min(records.length - 1, current + (event.key === 'ArrowDown' ? 1 : -1)))
    onSelect(records[target].id)
    virtualizer.scrollToIndex(target)
  }
  return <>
    <Header aria-hidden><span>Time</span><span>Level</span><span>Source</span><span>Message</span></Header>
    <Viewport ref={scrollRef} role="listbox" aria-label="Log messages" aria-activedescendant={activeId} tabIndex={0} onKeyDown={moveSelection}>
      <div style={{ height: virtualizer.getTotalSize(), position: 'relative' }}>
        {virtualRows.map(item => {
          const record = records[item.index]
          return <Row key={record.id} id={`log-row-${encodeURIComponent(record.id)}`} variant="ghost" role="option" data-level={record.level.toLowerCase()} aria-selected={record.id === selectedId}
            tabIndex={record.id === selectedId ? 0 : -1} onClick={() => onSelect(record.id)}
            style={{ transform: `translateY(${item.start}px)` }}>
            <span title={record.timestamp ?? 'Unknown time'}>{record.timestamp ? record.timestamp.slice(11, 23) : 'Unknown'}</span>
            <span>{record.level}</span><span>{record.service ?? record.node}</span>
            <span><Message text={record.message} search={search} /></span>
          </Row>
        })}
      </div>
    </Viewport>
  </>
}
