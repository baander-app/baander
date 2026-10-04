import { act, fireEvent, render, screen } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { createStore } from 'zustand/vanilla'
import { ThemeProvider } from 'styled-components'
import { resolveTheme } from '@/shared/theme/resolve-theme'
import { createStoreDebugger } from '@/shared/stores/debug'
import { StoreTimeline } from '../StoreTimeline'

describe('store timeline UI', () => {
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
