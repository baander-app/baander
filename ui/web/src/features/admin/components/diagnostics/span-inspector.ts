import type { Span } from '../../api/debug-api'

export type SpanStatusFilter = 'all' | 'error' | 'non-error'
export type SpanSort = 'newest' | 'slowest'

export interface SpanFilterInputs {
  text: string
  service: string
  status: SpanStatusFilter
  minDurationMs: string
  maxDurationMs: string
  sort: SpanSort
}

export const DEFAULT_SPAN_FILTERS: SpanFilterInputs = {
  text: '',
  service: '',
  status: 'all',
  minDurationMs: '',
  maxDurationMs: '',
  sort: 'newest',
}

export interface SpanFilters {
  text: string
  service: string
  status: SpanStatusFilter
  minDurationUs: number | null
  maxDurationUs: number | null
  sort: SpanSort
}

export interface SpanFilterErrors {
  minDurationMs?: string
  maxDurationMs?: string
}

export interface SpanFilterValidation {
  filters: SpanFilters | null
  errors: SpanFilterErrors
}

export function validateSpanFilters(inputs: SpanFilterInputs): SpanFilterValidation {
  const errors: SpanFilterErrors = {}
  function durationUs(value: string, field: keyof SpanFilterErrors): number | null {
    if (value.trim() === '') return null

    const milliseconds = Number(value)
    const microseconds = milliseconds * 1000
    if (!Number.isFinite(microseconds) || milliseconds < 0) {
      errors[field] = 'Enter a finite duration of zero or more milliseconds.'
      return null
    }

    return microseconds
  }

  const minDurationUs = durationUs(inputs.minDurationMs, 'minDurationMs')
  const maxDurationUs = durationUs(inputs.maxDurationMs, 'maxDurationMs')
  if (minDurationUs !== null && maxDurationUs !== null && minDurationUs > maxDurationUs) {
    errors.maxDurationMs = 'Maximum duration must be at least the minimum duration.'
  }

  return {
    errors,
    filters: Object.keys(errors).length > 0 ? null : {
      text: inputs.text.trim().toLowerCase(),
      service: inputs.service,
      status: inputs.status,
      minDurationUs,
      maxDurationUs,
      sort: inputs.sort,
    },
  }
}

export function getHttpStatus(span: Span): string | null {
  const value = span.attributes['http.response.status_code']
  return typeof value === 'number' || typeof value === 'string' ? String(value) : null
}

export function isErrorSpan(span: Span): boolean {
  const httpStatus = getHttpStatus(span)
  return span.status_code.toLowerCase() === 'error'
    || (httpStatus !== null && Number(httpStatus) >= 400)
}

function searchableText(span: Span): string {
  return [
    span.operation_name,
    span.service,
    span.trace_id,
    span.span_id,
    span.parent_span_id ?? '',
    JSON.stringify(span.attributes),
  ].join(' ').toLowerCase()
}

export function filterSpans(spans: readonly Span[], filters: SpanFilters): Span[] {
  return spans.filter((span) => {
    if (filters.service && span.service !== filters.service) return false
    if (filters.status === 'error' && !isErrorSpan(span)) return false
    if (filters.status === 'non-error' && isErrorSpan(span)) return false
    if (filters.minDurationUs !== null && span.duration_us < filters.minDurationUs) return false
    if (filters.maxDurationUs !== null && span.duration_us > filters.maxDurationUs) return false
    return !filters.text || searchableText(span).includes(filters.text)
  }).sort((a, b) => {
    if (filters.sort === 'slowest' && a.duration_us !== b.duration_us) {
      return b.duration_us - a.duration_us
    }
    return b.start_time_us - a.start_time_us
      || a.trace_id.localeCompare(b.trace_id)
      || a.span_id.localeCompare(b.span_id)
  })
}

export interface TraceWaterfallRow {
  span: Span
  depth: number
  missingParent: boolean
  offsetUs: number
  durationUs: number
  offsetPercent: number
  widthPercent: number
}

export interface TraceWaterfall {
  rows: TraceWaterfallRow[]
  startTimeUs: number
  durationUs: number
}

export function buildTraceWaterfall(snapshot: readonly Span[], traceId: string): TraceWaterfall {
  const members = snapshot.filter((span) => span.trace_id === traceId).sort((a, b) => (
    a.start_time_us - b.start_time_us || a.span_id.localeCompare(b.span_id)
  ))
  if (members.length === 0) return { rows: [], startTimeUs: 0, durationUs: 0 }

  const startTimeUs = Math.min(...members.map((span) => span.start_time_us))
  const endTimeUs = Math.max(...members.map((span) => span.start_time_us + span.duration_us))
  const durationUs = Math.max(0, endTimeUs - startTimeUs)
  const byId = new Map(members.map((span) => [span.span_id, span]))
  const children = new Map<string, Span[]>()
  for (const span of members) {
    if (span.parent_span_id === null) continue
    const siblings = children.get(span.parent_span_id) ?? []
    siblings.push(span)
    children.set(span.parent_span_id, siblings)
  }

  const rows: TraceWaterfallRow[] = []
  const visited = new Set<Span>()
  function visit(span: Span, depth: number) {
    if (visited.has(span)) return
    visited.add(span)
    const offsetUs = span.start_time_us - startTimeUs
    rows.push({
      span,
      depth,
      missingParent: span.parent_span_id !== null && !byId.has(span.parent_span_id),
      offsetUs,
      durationUs: span.duration_us,
      offsetPercent: durationUs === 0 ? 0 : offsetUs / durationUs * 100,
      widthPercent: durationUs === 0 ? 0 : span.duration_us / durationUs * 100,
    })
    for (const child of children.get(span.span_id) ?? []) visit(child, depth + 1)
  }

  for (const span of members) {
    if (span.parent_span_id === null || !byId.has(span.parent_span_id)) visit(span, 0)
  }
  for (const span of members) visit(span, 0)

  return { rows, startTimeUs, durationUs }
}

export function spanKey(span: Span): string {
  return `${span.trace_id}:${span.span_id}`
}
export function spanStartTime(startUs: number): string {
  const milliseconds = Math.floor(startUs / 1000)
  const date = new Date(milliseconds)
  if (!Number.isFinite(date.getTime())) return `${startUs} µs since epoch`
  return date.toISOString().replace(/\.\d{3}Z$/, `.${String(Math.floor(startUs % 1_000_000)).padStart(6, '0')}Z`)
}
