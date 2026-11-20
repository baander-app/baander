import { useEffect, useMemo, useState } from 'react'
import { useInfiniteQuery, useQuery, useQueryClient, type InfiniteData } from '@tanstack/react-query'
import { getLogDetail, getLogEntries, getLogSources, resolveLogFilter, type LogFilter, type ResolvedLogFilter, type LogPage, type LogRecord } from '../api/logs-api'

export const LOG_ROW_LIMIT = 5000
type LogWindow = { since?: string; until?: string }
type LiveLogPage = LogPage & { visibleWindow?: LogWindow }
type HistoricalLogPage = LogPage & { resolvedFilter: ResolvedLogFilter }
type HistoryKey = readonly ['admin', 'logs', 'history', LogFilter, number]

const LIVE_GAP_REASON = 'Live sampling exceeded 200 records; reload history to inspect the skipped interval.'

export function mergeLogRecords(pages: readonly (readonly LogRecord[])[]): LogRecord[] {
  const records = new Map<string, LogRecord>()
  for (const page of pages) {
    for (const record of page) {
      if (!records.has(record.id)) records.set(record.id, record)
    }
  }
  return [...records.values()]
    .sort((a, b) => (b.timestamp ?? '').localeCompare(a.timestamp ?? '') || b.id.localeCompare(a.id))
}

export function useLogSources(following: boolean) {
  const client = useQueryClient()
  useEffect(() => {
    if (!following) client.cancelQueries({ queryKey: ['admin', 'logs', 'sources-live'], exact: true }).catch(() => undefined)
  }, [client, following])
  return useQuery({
    queryKey: ['admin', 'logs', 'sources-live'],
    enabled: following,
    queryFn: ({ signal }) => getLogSources(signal),
    retry: false,
    gcTime: 0,
    refetchOnWindowFocus: false,
    refetchOnReconnect: false,
  })
}

export function useLogs(filter: LogFilter, following: boolean) {
  const client = useQueryClient()
  const tailKey = ['admin', 'logs', 'tail', filter] as const
  const [historyRevision, setHistoryRevision] = useState(0)
  useEffect(() => {
    if (!following) client.cancelQueries({ queryKey: ['admin', 'logs', 'tail', filter], exact: true }).catch(() => undefined)
  }, [client, filter, following])
  const history = useInfiniteQuery<HistoricalLogPage, Error, InfiniteData<HistoricalLogPage, string | undefined>, HistoryKey, string | undefined>({
    queryKey: ['admin', 'logs', 'history', filter, historyRevision],
    enabled: query => query.state.data === undefined,
    queryFn: async ({ pageParam, signal }) => {
      const cached = client.getQueryData<InfiniteData<HistoricalLogPage, string | undefined>>(['admin', 'logs', 'history', filter, historyRevision])
      const snapshotFilter = cached?.pages[0]?.resolvedFilter
      if (pageParam && !snapshotFilter) throw new Error('Historical snapshot expired; reload history.')
      const resolvedFilter = snapshotFilter ?? resolveLogFilter(filter)
      const page = await getLogEntries(resolvedFilter, pageParam, signal)
      return { ...page, resolvedFilter }
    },
    initialPageParam: undefined as string | undefined,
    getNextPageParam: page => page.nextCursor ?? undefined,
    maxPages: 25,
    staleTime: Infinity,
    retry: false,
    gcTime: 0,
    refetchOnWindowFocus: false,
    refetchOnReconnect: false,
  })
  const tail = useQuery<LiveLogPage>({
    queryKey: tailKey,
    queryFn: async ({ signal }) => {
      const previous = client.getQueryData<LiveLogPage>(tailKey)
      if (!previous && history.data?.pages[0] && Date.now() - history.dataUpdatedAt < 1000) {
        return { ...history.data.pages[0], visibleWindow: filter.timeRange && filter.timeRange !== 'custom' ? history.data?.pages[0]?.resolvedFilter : undefined }
      }
      const resolved = resolveLogFilter(filter)
      const page = await getLogEntries(resolved, undefined, signal)
      const previousIds = new Set(previous?.items.map(record => record.id))
      const skipped = previous?.coverage.reasons.includes(LIVE_GAP_REASON)
        || (previousIds.size > 0 && page.items.length === 200
          && !page.items.some(record => previousIds.has(record.id)))
      const coverage = skipped ? {
        complete: false,
        reasons: [...page.coverage.reasons, LIVE_GAP_REASON],
      } : page.coverage
      return {
        ...page,
        visibleWindow: filter.timeRange && filter.timeRange !== 'custom' ? resolved : undefined,
        coverage,
        items: mergeLogRecords([page.items, previous?.items ?? []]).slice(0, LOG_ROW_LIMIT),
      }
    },
    enabled: following && history.isSuccess,
    retry: false,
    gcTime: 0,
    refetchOnWindowFocus: false,
    refetchOnReconnect: false,
  })
  const pages = history.data?.pages
  const tailItems = tail.data?.items
  const tailCoverage = tail.data?.coverage
  const visibleWindow = tail.data?.visibleWindow ?? (filter.timeRange && filter.timeRange !== 'custom' ? history.data?.pages[0]?.resolvedFilter : undefined)
  const coverage = useMemo(() => {
    const reports = [tailCoverage, ...(pages?.map(page => page.coverage) ?? [])]
      .filter(report => report !== undefined)
    if (!reports.length) return null
    return {
      complete: reports.every(report => report.complete),
      reasons: [...new Set(reports.flatMap(report => report.reasons))],
    }
  }, [pages, tailCoverage])
  const { records, limited } = useMemo(() => {
    const merged = mergeLogRecords([
      tailItems ?? [],
      ...(pages?.map(page => page.items) ?? []),
    ])
    const since = visibleWindow?.since ? Date.parse(visibleWindow.since) : -Infinity
    const until = visibleWindow?.until ? Date.parse(visibleWindow.until) : Infinity
    const visible = visibleWindow ? merged.filter(record => {
      const timestamp = Date.parse(record.timestamp)
      return timestamp >= since && timestamp <= until
    }) : merged
    return { records: visible.slice(0, LOG_ROW_LIMIT), limited: visible.length >= LOG_ROW_LIMIT }
  }, [pages, tailItems, visibleWindow])
  async function reloadHistory() {
    setHistoryRevision(value => value + 1)
    await client.resetQueries({ queryKey: tailKey, exact: true })
  }
  return { history, tail, reloadHistory, records, limited, coverage }
}

export function useLogDetail(id: string | null) {
  return useQuery({
    queryKey: ['admin', 'logs', 'detail', id],
    queryFn: ({ signal }) => getLogDetail(id!, signal),
    enabled: id !== null,
    retry: false,
    gcTime: 0,
    refetchOnWindowFocus: false,
    refetchOnReconnect: false,
  })
}
