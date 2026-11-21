import { describe, expect, it } from 'vitest'
import type { Span } from '../../api/debug-api'
import {
  buildSpanTimeline,
  buildTraceWaterfall,
  DEFAULT_SPAN_FILTERS,
  filterSpans,
  isErrorSpan,
  validateSpanFilters,
} from './span-inspector'

function span(overrides: Partial<Span> = {}): Span {
  return {
    trace_id: 'trace-a',
    span_id: 'root',
    parent_span_id: null,
    operation_name: 'GET /admin',
    service: 'server',
    kind: 'Server',
    start_time_us: 1000,
    duration_us: 2000,
    attributes: {},
    status_code: 'Unset',
    status_message: '',
    file_path: null,
    line_number: null,
    ...overrides,
  }
}

describe('span search', () => {
  it('combines text, exact service, errors and millisecond duration bounds', () => {
    const match = span({ attributes: { route: { path: '/Albums' } }, status_code: 'Error' })
    const validation = validateSpanFilters({
      ...DEFAULT_SPAN_FILTERS,
      text: 'albums',
      service: 'server',
      status: 'error',
      minDurationMs: '2',
      maxDurationMs: '2',
    })
    expect(validation.errors).toEqual({})
    expect(validation.filters).not.toBeNull()
    expect(filterSpans([
      match,
      span({ service: 'server-extra', status_code: 'Error' }),
      span({ attributes: match.attributes }),
      span({ attributes: match.attributes, status_code: 'Error', duration_us: 1999 }),
    ], validation.filters!)).toEqual([match])
  })

  it('searches operation, service, IDs and nested attribute keys and values', () => {
    const item = span({ parent_span_id: 'parent-b', attributes: { query: [{ routeKey: 'Albums' }] } })
    for (const text of ['get /ADMIN', 'SERVER', 'trace-a', 'root', 'parent-b', 'routekey', 'albums']) {
      const { filters } = validateSpanFilters({ ...DEFAULT_SPAN_FILTERS, text })
      expect(filterSpans([item], filters!)).toEqual([item])
    }
  })

  it('includes HTTP failures and telemetry errors without classifying unset as success', () => {
    expect(isErrorSpan(span({ status_code: 'Error' }))).toBe(true)
    expect(isErrorSpan(span({ attributes: { 'http.response.status_code': '404' } }))).toBe(true)
    expect(isErrorSpan(span({ attributes: { 'http.response.status_code': 500 } }))).toBe(true)
    expect(isErrorSpan(span({ status_code: 'Unset' }))).toBe(false)
    expect(isErrorSpan(span({ status_code: 'Ok', attributes: { 'http.response.status_code': 302 } }))).toBe(false)
  })

  it('rejects negative, non-finite, malformed and reversed duration inputs', () => {
    for (const minDurationMs of ['-1', 'Infinity', 'NaN', '2ms']) {
      const result = validateSpanFilters({ ...DEFAULT_SPAN_FILTERS, minDurationMs })
      expect(result.filters).toBeNull()
      expect(result.errors.minDurationMs).toBeTruthy()
    }
    const reversed = validateSpanFilters({ ...DEFAULT_SPAN_FILTERS, minDurationMs: '3', maxDurationMs: '2' })
    expect(reversed.filters).toBeNull()
    expect(reversed.errors.maxDurationMs).toBeTruthy()
  })

  it('sorts newest by start timestamp and slowest by duration, retaining zero-duration spans', () => {
    const oldSlow = span({ span_id: 'slow', start_time_us: 1, duration_us: 5000 })
    const recent = span({ span_id: 'recent', start_time_us: 5, duration_us: 0 })
    const middle = span({ span_id: 'middle', start_time_us: 3, duration_us: 2000 })
    const items = [middle, oldSlow, recent]
    const newest = validateSpanFilters(DEFAULT_SPAN_FILTERS).filters!
    const slowest = validateSpanFilters({ ...DEFAULT_SPAN_FILTERS, sort: 'slowest', minDurationMs: '0' }).filters!
    expect(filterSpans(items, newest)).toEqual([recent, middle, oldSlow])
    expect(filterSpans(items, slowest)).toEqual([oldSlow, middle, recent])
    expect(items).toEqual([middle, oldSlow, recent])
  })
})

describe('retained span timeline', () => {
  it('compares independent requests on one domain without inventing hierarchy or changing order', () => {
    const early = span({ trace_id: 'early', start_time_us: 1000, duration_us: 2000 })
    const later = span({ trace_id: 'later', parent_span_id: 'outside', start_time_us: 2000, duration_us: 3000 })
    const result = buildSpanTimeline([later, early])
    expect(result.startTimeUs).toBe(1000)
    expect(result.durationUs).toBe(4000)
    expect(result.rows.map(row => row.span)).toEqual([later, early])
    expect(result.rows[0]).toMatchObject({ depth: 0, missingParent: false, offsetPercent: 25, widthPercent: 75 })
    expect(result.rows[1]).toMatchObject({ depth: 0, offsetPercent: 0, widthPercent: 50 })
    const reordered = buildSpanTimeline([early, later])
    expect(reordered.startTimeUs).toBe(result.startTimeUs)
    expect(reordered.durationUs).toBe(result.durationUs)
    expect(buildSpanTimeline([later])).toMatchObject({ startTimeUs: 2000, durationUs: 3000 })
  })

  it('keeps empty and instantaneous snapshots finite', () => {
    expect(buildSpanTimeline([])).toEqual({ rows: [], startTimeUs: 0, durationUs: 0 })
    expect(buildSpanTimeline([span({ duration_us: 0 })])).toMatchObject({
      durationUs: 0,
      rows: [{ offsetPercent: 0, widthPercent: 0 }],
    })
  })
})

describe('retained trace waterfall', () => {
  it('orders parents first and calculates timing from all retained trace members', () => {
    const root = span({ duration_us: 1000 })
    const child = span({ span_id: 'child', parent_span_id: 'root', start_time_us: 1500, duration_us: 500 })
    const orphan = span({ span_id: 'orphan', parent_span_id: 'evicted', start_time_us: 3000, duration_us: 2000 })
    const result = buildTraceWaterfall([child, orphan, span({ trace_id: 'other', start_time_us: 0 }), root], 'trace-a')
    expect(result.startTimeUs).toBe(1000)
    expect(result.durationUs).toBe(4000)
    expect(result.rows.map(({ span: item, depth, missingParent }) => [item.span_id, depth, missingParent])).toEqual([
      ['root', 0, false], ['child', 1, false], ['orphan', 0, true],
    ])
    expect(result.rows[1]).toMatchObject({ offsetUs: 500, durationUs: 500, offsetPercent: 12.5, widthPercent: 12.5 })
    expect(result.rows[2]).toMatchObject({ offsetPercent: 50, widthPercent: 50 })
  })

  it('includes disconnected, self-parented and cyclic spans exactly once', () => {
    const items = [
      span({ span_id: 'a', parent_span_id: 'b' }),
      span({ span_id: 'b', parent_span_id: 'a' }),
      span({ span_id: 'self', parent_span_id: 'self' }),
      span({ span_id: 'separate' }),
    ]
    const rows = buildTraceWaterfall(items, 'trace-a').rows
    expect(rows).toHaveLength(4)
    expect(new Set(rows.map(({ span: item }) => item.span_id)).size).toBe(4)
    expect(rows.every((row) => Number.isFinite(row.widthPercent))).toBe(true)
  })

  it('handles zero-duration and absent traces without invalid geometry', () => {
    expect(buildTraceWaterfall([span({ duration_us: 0 })], 'trace-a')).toMatchObject({
      durationUs: 0,
      rows: [{ offsetPercent: 0, widthPercent: 0 }],
    })
    expect(buildTraceWaterfall([], 'absent')).toEqual({ rows: [], startTimeUs: 0, durationUs: 0 })
  })
})
