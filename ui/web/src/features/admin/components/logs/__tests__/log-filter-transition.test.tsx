import { act, cleanup, renderHook } from '@testing-library/react'
import { afterEach, expect, it, vi } from 'vitest'
import { useLogFilters } from '../use-log-filters'

const route = vi.hoisted(() => ({ params: new URLSearchParams(), navigate: vi.fn() }))
vi.mock('react-router-dom', () => ({ useSearchParams: () => [route.params, route.navigate] }))
afterEach(() => { cleanup(); vi.clearAllMocks() })

it('retains consecutive selections while router navigation has not committed', () => {
  const { result } = renderHook(() => useLogFilters())
  act(() => result.current.applyPatch({ channels: ['app'] }))
  expect(result.current.draft.channels).toEqual(['app'])
  act(() => result.current.applyPatch({ channels: [...result.current.draft.channels, 'auth'] }))
  expect(result.current.draft.channels).toEqual(['app', 'auth'])
  const params = route.navigate.mock.calls.at(-1)?.[0] as URLSearchParams
  expect(params.getAll('channel')).toEqual(['app', 'auth'])
})
