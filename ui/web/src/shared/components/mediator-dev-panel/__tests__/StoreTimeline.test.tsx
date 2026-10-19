import { act, fireEvent, render, screen, within } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { createStore } from 'zustand/vanilla'
import { ThemeProvider } from 'styled-components'
import { resolveTheme } from '@/shared/theme/resolve-theme'
import { createStoreDebugger, type StoreDebugSnapshot } from '@/shared/stores/debug'
import { StoreTimeline } from '../StoreTimeline'

describe('store timeline UI', () => {
  function setup(limit = 200) {
    const recorder = createStoreDebugger(true, limit)
    const counter = createStore(recorder.instrument<{ count: number; increment: () => void }>('counter', (set, get) => ({
      count: 0, increment: () => set({ count: get().count + 1 }),
    })))
    render(<ThemeProvider theme={resolveTheme('dark', 'violet')}><StoreTimeline debugger={recorder} /></ThemeProvider>)
    return { recorder, counter }
  }

  it('selects and steps through retained events without changing live state or recording events', () => {
    const { recorder, counter } = setup()
    act(() => { counter.getState().increment() })
    const inspection = within(screen.getByRole('region', { name: 'Captured state' }))
    const previous = screen.getByRole('button', { name: 'Previous event' })
    const next = screen.getByRole('button', { name: 'Next event' })
    expect(previous).toBeDisabled()
    expect(next).toBeDisabled()
    fireEvent.click(screen.getByRole('button', { name: 'Inspect event #2' }))
    expect(inspection.getByText('#2 counter · setState · update')).toBeInTheDocument()
    expect(inspection.getByText('Before capture')).toBeInTheDocument()
    expect(inspection.getByText(/"count": 0/)).toBeInTheDocument()
    expect(inspection.getByText(/"count": 1/)).toBeInTheDocument()
    expect(inspection.getAllByText('Complete capture')).toHaveLength(2)
    act(() => { counter.getState().increment() })
    expect(inspection.getByText('#2 counter · setState · update')).toBeInTheDocument()
    expect(inspection.getByText(/"count": 1/)).toBeInTheDocument()
    const snapshot = recorder.getSnapshot()
    const liveState = counter.getState()
    fireEvent.click(previous)
    expect(inspection.getByText('#1 counter · increment · action')).toBeInTheDocument()
    expect(inspection.getByText('This event has no state capture.')).toBeInTheDocument()
    expect(previous).toBeDisabled()
    fireEvent.click(next)
    fireEvent.click(next)
    expect(inspection.getByText('#3 counter · increment · resolved')).toBeInTheDocument()
    expect(inspection.getByText('This event has no state capture.')).toBeInTheDocument()
    fireEvent.click(screen.getByRole('button', { name: 'Inspect event #6' }))
    expect(next).toBeDisabled()
    expect(recorder.getSnapshot()).toBe(snapshot)
    expect(counter.getState()).toBe(liveState)
    expect(liveState.count).toBe(2)
  })

  it('navigates filtered order and forgets a hidden or cleared selection', () => {
    const { recorder, counter } = setup()
    act(() => {
      counter.getState().increment()
      counter.getState().increment()
    })
    const filter = screen.getByLabelText('Filter store actions')
    const inspection = within(screen.getByRole('region', { name: 'Captured state' }))
    fireEvent.change(filter, { target: { value: 'update' } })
    fireEvent.click(screen.getByRole('button', { name: 'Inspect event #2' }))
    expect(screen.getByRole('button', { name: 'Previous event' })).toBeDisabled()
    fireEvent.click(screen.getByRole('button', { name: 'Next event' }))
    expect(inspection.getByText('#5 counter · setState · update')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Next event' })).toBeDisabled()
    fireEvent.change(filter, { target: { value: 'resolved' } })
    fireEvent.change(filter, { target: { value: '' } })
    expect(inspection.getByText('Select an event to inspect its captured state.')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Inspect event #5' })).toHaveAttribute('aria-pressed', 'false')
    fireEvent.click(screen.getByRole('button', { name: 'Inspect event #5' }))
    fireEvent.click(screen.getByRole('button', { name: 'Clear timeline' }))
    expect(inspection.getByText('Select an event to inspect its captured state.')).toBeInTheDocument()
    expect(recorder.getSnapshot().events).toHaveLength(0)
  })

  it('removes an evicted selection without selecting a replacement', () => {
    const { counter } = setup(3)
    act(() => { counter.getState().increment() })
    fireEvent.click(screen.getByRole('button', { name: 'Inspect event #2' }))
    act(() => { counter.getState().increment() })
    expect(screen.queryByRole('button', { name: 'Inspect event #2' })).not.toBeInTheDocument()
    expect(screen.getByText('Select an event to inspect its captured state.')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Next event' })).toBeDisabled()
  })

  it('shows each capture completeness and markers without substituting latest store state', () => {
    const snapshot: StoreDebugSnapshot = {
      events: [
        { schemaVersion: 1, id: 1, parentId: null, store: 'counter', kind: 'update', action: 'setState', timestamp: 0,
          before: { complete: false, value: { password: '[redacted]', nested: '[truncated]' } },
          after: { complete: true, value: { count: 1 } } },
        { schemaVersion: 1, id: 2, parentId: null, store: 'counter', kind: 'update', action: 'setState', timestamp: 1,
          after: { complete: false, value: '[unavailable]' } },
        { schemaVersion: 1, id: 3, parentId: null, store: 'counter', kind: 'rejected', action: 'fail', timestamp: 2 },
      ],
      stores: { counter: { complete: true, value: { count: 999 } } },
      droppedEvents: 0,
    }
    const recorder = { ...createStoreDebugger(true), getSnapshot: () => snapshot }
    render(<ThemeProvider theme={resolveTheme('dark', 'violet')}><StoreTimeline debugger={recorder} /></ThemeProvider>)
    const inspection = within(screen.getByRole('region', { name: 'Captured state' }))
    fireEvent.click(screen.getByRole('button', { name: 'Inspect event #1' }))
    expect(inspection.getByText('Incomplete capture')).toBeInTheDocument()
    expect(inspection.getByText('Complete capture')).toBeInTheDocument()
    expect(inspection.getByText(/\[redacted\]/)).toHaveTextContent('[truncated]')
    fireEvent.click(screen.getByRole('button', { name: 'Next event' }))
    expect(inspection.getByText('Capture unavailable.')).toBeInTheDocument()
    expect(inspection.getByText('"[unavailable]"')).toBeInTheDocument()
    expect(inspection.queryByText(/999/)).not.toBeInTheDocument()
    fireEvent.click(screen.getByRole('button', { name: 'Next event' }))
    expect(inspection.getByText('This event has no state capture.')).toBeInTheDocument()
  })

  it('updates without polling, filters, shows state changes/stacks, exports and clears', () => {
    const recorder = createStoreDebugger(true)
    const counter = createStore(recorder.instrument<{ count: number; increment: () => void }>('counter', (set, get) => ({
      count: 0, increment: () => set({ count: get().count + 1 }),
    })))
    render(<ThemeProvider theme={resolveTheme('dark', 'violet')}><StoreTimeline debugger={recorder} /></ThemeProvider>)
    expect(screen.getByText('No matching store events.')).toBeInTheDocument()
    act(() => { counter.getState().increment() })
    expect(screen.getByText(/counter · increment · action/)).toBeInTheDocument()
    expect(screen.getByText('Changed: count')).toBeInTheDocument()
    expect(screen.getByText('Before')).toBeInTheDocument()
    expect(screen.getByText('After')).toBeInTheDocument()
    expect(screen.getAllByText('Call stack')).toHaveLength(2)
    fireEvent.change(screen.getByLabelText('Filter store actions'), { target: { value: 'absent' } })
    expect(screen.getByText('No matching store events.')).toBeInTheDocument()
    fireEvent.click(screen.getByRole('button', { name: 'Export trace' }))
    const trace = JSON.parse((screen.getByRole('textbox', { name: 'Trace JSON' }) as HTMLTextAreaElement).value)
    expect(trace.schemaVersion).toBe(1)
    expect(trace.events).toHaveLength(3)
    fireEvent.click(screen.getByRole('button', { name: 'Clear timeline' }))
    expect(recorder.getSnapshot().events).toHaveLength(0)
  })
})
