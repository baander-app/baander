import { act, cleanup, render, screen } from '@testing-library/react'
import { useRef } from 'react'
import { afterEach, beforeEach, describe, expect, it } from 'vitest'
import { useLiveOutageStore } from '@/shared/live/outage-store'
import { LiveOutageItem } from '../LiveOutageItem'

const OUTAGE_ENTRY = /Live updates unavailable/

function listOutageEntry(startedMinutesAgo: number) {
  const outage = useLiveOutageStore.getState()
  outage.begin(Date.now() - startedMinutesAgo * 60_000)
  outage.listEntry()
}

function Popout() {
  const entry = useLiveOutageStore((state) => state.entry)
  const contentRef = useRef<HTMLDivElement>(null)

  return (
    <div ref={contentRef} role="group" aria-label="Popout content" tabIndex={-1}>
      {entry && <LiveOutageItem entry={entry} returnFocusTo={contentRef} />}
      <input aria-label="Search" />
    </div>
  )
}

beforeEach(() => {
  useLiveOutageStore.getState().end()
})
afterEach(() => {
  cleanup()
  useLiveOutageStore.getState().end()
})

describe('live outage item', () => {
  it('names the outage and how long ago it started', () => {
    listOutageEntry(5)

    render(<Popout />)

    const entry = screen.getByRole('button', { name: OUTAGE_ENTRY })
    expect(entry).toHaveTextContent('Live updates unavailable')
    expect(entry).toHaveTextContent('5m ago')
  })

  it('moves focus to the popout content when the outage ends while it has focus', () => {
    listOutageEntry(3)
    render(<Popout />)
    const entry = screen.getByRole('button', { name: OUTAGE_ENTRY })
    act(() => entry.focus())

    act(() => useLiveOutageStore.getState().end())

    expect(entry).not.toBeInTheDocument()
    expect(screen.getByRole('group', { name: 'Popout content' })).toHaveFocus()
  })

  it('leaves focus where it is when the outage ends while focus is elsewhere', () => {
    listOutageEntry(3)
    render(<Popout />)
    const search = screen.getByRole('textbox', { name: 'Search' })
    act(() => search.focus())

    act(() => useLiveOutageStore.getState().end())

    expect(screen.queryByRole('button', { name: OUTAGE_ENTRY })).not.toBeInTheDocument()
    expect(search).toHaveFocus()
  })
})
