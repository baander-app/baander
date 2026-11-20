import { AXIOS_INSTANCE } from '@/shared/api-client/axios-instance'

import type {
  LogCoverageResource,
  LogEntriesResource,
  LogRecordResource,
  LogSnapshotResource,
  LogSourceResource,
  LogSourcesResource,
  LogSqlResource,
} from '@/shared/api-client/gen/endpoints'

export type LogCoverage = LogCoverageResource
export type LogSource = LogSourceResource
export type LogSources = LogSourcesResource
export type LogRecord = LogRecordResource
export type LogSnapshot = LogSnapshotResource
export type LogPage = LogEntriesResource
export type LogSqlResult = LogSqlResource

export const LOG_TIME_RANGES = ['5m', '15m', '30m', '1h', '3h', '6h', '12h', '1w', '1mo', '1y'] as const
export type LogTimeRange = typeof LOG_TIME_RANGES[number]

export interface LogFilter {
  sources: string[]
  search: string
  levels: string[]
  channels: string[]
  correlationId?: string
  since?: string
  until?: string
  timeRange?: LogTimeRange | 'custom'
}

export type ResolvedLogFilter = Omit<LogFilter, 'timeRange'>

function subtractCalendarMonths(now: Date, months: number): Date {
  const result = new Date(now.getTime())
  const day = result.getUTCDate()
  result.setUTCDate(1)
  result.setUTCMonth(result.getUTCMonth() - months)
  const lastDay = new Date(Date.UTC(result.getUTCFullYear(), result.getUTCMonth() + 1, 0)).getUTCDate()
  result.setUTCDate(Math.min(day, lastDay))
  return result
}

export function resolveLogFilter(filter: LogFilter, now = new Date()): ResolvedLogFilter {
  const { timeRange, ...resolved } = filter
  if (!timeRange || timeRange === 'custom') return {
    ...resolved,
    since: filter.since ? new Date(filter.since).toISOString() : undefined,
    until: filter.until ? new Date(filter.until).toISOString() : undefined,
  }
  const durations: Partial<Record<LogTimeRange, number>> = {
    '5m': 5, '15m': 15, '30m': 30, '1h': 60, '3h': 180, '6h': 360, '12h': 720, '1w': 10080,
  }
  const since = timeRange === '1mo' || timeRange === '1y'
    ? subtractCalendarMonths(now, timeRange === '1y' ? 12 : 1)
    : new Date(now.getTime() - durations[timeRange]! * 60_000)
  return { ...resolved, since: since.toISOString(), until: now.toISOString() }
}

export async function getLogSources(signal?: AbortSignal): Promise<LogSources> {
  const { data } = await AXIOS_INSTANCE.get<{ data: LogSources }>('/api/admin/logs/sources', { signal })
  return data.data
}

export async function getLogEntries(filter: LogFilter, cursor?: string, signal?: AbortSignal): Promise<LogPage> {
  const { channels, ...params } = resolveLogFilter(filter)
  const { data } = await AXIOS_INSTANCE.get<{ data: LogPage }>('/api/admin/logs/entries', {
    signal,
    params: {
      ...params,
      'channels[]': channels,
      sources: filter.sources.join(','),
      levels: filter.levels.join(','),
      cursor,
      limit: 200,
      order: 'desc',
    },
  })
  return data.data
}

export async function getLogDetail(id: string, signal?: AbortSignal): Promise<LogRecord> {
  const { data } = await AXIOS_INSTANCE.get<{ data: LogRecord }>(`/api/admin/logs/entries/${encodeURIComponent(id)}`, { signal })
  return data.data
}

export async function queryLogs(sql: string, allowPartial: boolean, filter: LogFilter, signal?: AbortSignal): Promise<LogSqlResult> {
  const { data } = await AXIOS_INSTANCE.post<{ data: LogSqlResult }>('/api/admin/logs/query', { sql, allowPartial, filter: resolveLogFilter(filter) }, { signal })
  return data.data
}
