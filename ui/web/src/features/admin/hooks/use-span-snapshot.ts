import { useEffect, useRef, useState } from 'react'
import { useQueryClient } from '@tanstack/react-query'
import type { Span } from '../api/debug-api'
import { useSpans } from './use-debug-stats'

const QUERY_KEY = ['debug', 'spans', 500] as const

export function useSpanSnapshot() {
  const query = useSpans(500)
  const client = useQueryClient()
  const [snapshot, setSnapshot] = useState<Span[] | undefined>(query.data)
  const [live, setLiveState] = useState(false)
  const generation = useRef(0)
  const suppressedData = useRef<Span[] | undefined>(undefined)

  useEffect(() => {
    if (query.data && query.data !== suppressedData.current && (live || snapshot === undefined)) {
      setSnapshot(query.data)
    }
  }, [query.data, live, snapshot])

  async function refresh() {
    const requestGeneration = ++generation.current
    const result = await query.refetch()
    if (requestGeneration === generation.current && !result.error && result.data) {
      suppressedData.current = undefined
      setSnapshot(result.data)
    }
  }

  function setLive(next: boolean) {
    generation.current++
    if (next && query.data && query.data !== suppressedData.current) {
      suppressedData.current = undefined
      setSnapshot(query.data)
    }
    setLiveState(next)
  }

  async function clearSnapshot() {
    generation.current++
    await client.cancelQueries({ queryKey: QUERY_KEY, exact: true })
    suppressedData.current = client.getQueryData<Span[]>(QUERY_KEY)
    client.setQueryData<Span[]>(QUERY_KEY, [])
    setSnapshot([])
  }

  return {
    spans: snapshot ?? query.data,
    live,
    setLive,
    refresh,
    clearSnapshot,
    error: query.error,
    isPending: query.isPending,
    isFetching: query.isFetching,
  }
}
