import { act, renderHook } from '@testing-library/react'
import { MemoryRouter, useNavigate } from 'react-router-dom'
import { afterEach, expect, it, vi } from 'vitest'
import { useLogFilters } from '../use-log-filters'

afterEach(() => vi.useRealTimers())

it('discards pending text when browser navigation changes the URL', () => {
  vi.useFakeTimers()
  const { result, unmount } = renderHook(() => ({ ...useLogFilters(), navigate: useNavigate() }), {
    wrapper: ({ children }) => <MemoryRouter initialEntries={['/admin/logs?search=original']}>{children}</MemoryRouter>,
  })
  act(() => result.current.updateText({ search: 'pending' }))
  act(() => vi.advanceTimersByTime(150))
  act(() => result.current.navigate('/admin/logs?search=from-history'))
  expect(result.current.draft.search).toBe('from-history')
  act(() => vi.advanceTimersByTime(500))
  expect(result.current.filter.search).toBe('from-history')
  expect(result.current.filterKey).toBe('search=from-history')
  act(() => result.current.updateText({ search: 'discard-on-unmount' }))
  unmount()
  expect(vi.getTimerCount()).toBe(0)
})
