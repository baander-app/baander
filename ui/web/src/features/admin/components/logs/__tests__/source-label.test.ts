import { expect, it } from 'vitest'
import type { LogSource } from '../../../api/logs-api'
import { sourceDescription, sourceLabel, sourceSummary } from '../source-label'

const source: LogSource = {
  id: 'opaque-source-id', node: 'host-one', name: 'host-one:container:abcdef0123456789',
  kind: 'container', service: 'web', container: 'abcdef0123456789', format: 'text', size: 20,
  status: 'gap', available: true, lastCollectedAt: null, retainedFrom: null, issues: [],
}

it('identifies container streams without repeating the service group or treating a gap as stopped', () => {
  expect(sourceLabel(source)).toBe('Container abcdef01')
  expect(sourceLabel({ ...source, stream: 'stderr' })).toBe('Standard error abcdef01')
  expect(sourceSummary(source)).toBe('History incomplete')
  expect(sourceSummary({ ...source, status: 'following' })).toBe('Collecting logs')
  expect(sourceSummary({ ...source, available: false, retainedFrom: '2026-11-20T10:00:00Z' })).toBe('Unavailable · retained logs')
  expect(sourceDescription(source)).toContain('Container: abcdef0123456789')
})

it('names final checks using only a verified checkpoint target', () => {
  const checkpoint = { ...source, kind: 'collector', name: 'host-one:checkpoint:0123456789abcdef', container: undefined }
  expect(sourceLabel(checkpoint)).toBe('Final collection check')
  expect(sourceSummary(checkpoint)).toBe('Container 01234567 · Failed')
  expect(sourceSummary({ ...checkpoint, name: 'other-collection-error' })).toBe('Collection failed · ID ource-id')
})

it('distinguishes repeated files by their known folder and retains archive names', () => {
  const file = { ...source, kind: 'file', container: undefined, name: 'host-one:file:/logs/web/abcdef0123456789/prod.log', status: 'following' }
  expect(sourceLabel(file)).toBe('prod.log · Folder abcdef01')
  expect(sourceLabel({ ...file, name: 'host-one:file:/logs/web/1234567890abcdef/prod.log' })).toBe('prod.log · Folder 12345678')
  expect(sourceSummary(file)).toBe('Log file · Collecting logs')
  const archive = { ...file, kind: 'archive', member: 'path/worker.log', archive: '/logs/web/abcdef0123456789/worker.zip', status: 'archived' }
  expect(sourceLabel(archive)).toBe('worker.log · Folder abcdef01')
  expect(sourceSummary(archive)).toBe('Retained archive · worker.zip')
})

it('names daemon availability and labels unknown source identities honestly', () => {
  const daemon = { ...source, kind: 'collector', service: 'docker', name: 'host-one:docker', status: 'following' }
  expect(sourceLabel(daemon)).toBe('Docker daemon')
  expect(sourceSummary(daemon)).toBe('Collecting logs')
  expect(sourceSummary({ ...daemon, available: false })).toBe('Unavailable')
  expect(sourceLabel({ ...source, kind: 'other', container: undefined })).toBe('Log source · ID ource-id')
})
