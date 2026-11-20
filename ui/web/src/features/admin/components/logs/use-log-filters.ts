import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { useSearchParams } from 'react-router-dom'
import type { LogFilter } from '../../api/logs-api'
import { logFilterParams, readLogFilter } from './log-filter'

export function useLogFilters() {
  const [params, setParams] = useSearchParams()
  const filter = useMemo(() => readLogFilter(params), [params])
  const filterKey = params.toString()
  const [draftState, setDraftState] = useState({ key: filterKey, value: filter })
  const draft = draftState.key === filterKey ? draftState.value : filter
  const timer = useRef<ReturnType<typeof setTimeout> | null>(null)

  const cancelPending = useCallback(() => {
    if (timer.current !== null) clearTimeout(timer.current)
    timer.current = null
  }, [])
  const apply = useCallback((next: LogFilter) => {
    cancelPending()
    const nextParams = logFilterParams(next)
    const nextKey = nextParams.toString()
    setDraftState({ key: filterKey, value: next })
    if (nextKey !== filterKey) setParams(nextParams)
  }, [cancelPending, filterKey, setParams])

  const pending = draft.search !== filter.search || draft.correlationId !== filter.correlationId
  useEffect(() => {
    if (pending) timer.current = setTimeout(() => apply(draft), 300)
    return cancelPending
  }, [apply, cancelPending, draft, pending])

  function updateText(patch: Partial<Pick<LogFilter, 'search' | 'correlationId'>>) {
    setDraftState({ key: filterKey, value: { ...draft, ...patch } })
  }
  function applyPatch(patch: Partial<LogFilter>) {
    apply({ ...draft, ...patch })
  }
  return { filter, filterKey, draft, updateText, applyPatch, flushText: () => apply(draft) }
}
