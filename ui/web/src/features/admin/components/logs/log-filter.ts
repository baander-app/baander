import { LOG_TIME_RANGES, type LogTimeRange, type LogFilter } from '../../api/logs-api'

export function readLogFilter(params: URLSearchParams): LogFilter {
  const range = params.get('range')
  return {
    timeRange: LOG_TIME_RANGES.includes(range as LogTimeRange) ? range as LogTimeRange
      : range === 'custom' || params.has('since') || params.has('until') ? 'custom' : undefined,
    sources: params.getAll('source'),
    levels: params.getAll('level'),
    search: params.get('search') ?? '',
    channels: params.getAll('channel'),
    correlationId: params.get('correlationId') || undefined,
    since: params.get('since') || undefined,
    until: params.get('until') || undefined,
  }
}

export function logFilterParams(filter: LogFilter): URLSearchParams {
  const params = new URLSearchParams()
  if (filter.timeRange) params.set('range', filter.timeRange)
  filter.sources.forEach(source => params.append('source', source))
  filter.levels.forEach(level => params.append('level', level))
  filter.channels.forEach(channel => params.append('channel', channel))
  for (const key of ['search', 'correlationId', 'since', 'until'] as const) {
    if (filter[key]) params.set(key, filter[key])
  }
  return params
}
