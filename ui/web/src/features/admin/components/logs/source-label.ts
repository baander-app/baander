import type { LogSource } from '../../api/logs-api'

function basename(name: string): string {
  return name.replaceAll('\\', '/').split('/').at(-1) || name
}

function containerIdentity(source: LogSource): string | undefined {
  return source.container?.slice(0, 8) || source.name.match(/:container:([a-f0-9]{12,64})$/i)?.[1].slice(0, 8)
}

function fileIdentity(source: LogSource): string {
  const folders = (source.archive || source.name).replaceAll('\\', '/').split('/').slice(0, -1)
  const folder = folders.reverse().find(value => /^[a-f0-9]{16,64}$/i.test(value))
  return folder ? `Folder ${folder.slice(0, 8)}` : `ID ${source.id.slice(-8)}`
}

function statusLabel(source: LogSource): string {
  if (source.available === false) return source.retainedFrom ? 'Unavailable · retained logs' : 'Unavailable'
  switch (source.status) {
    case 'gap': return 'History incomplete'
    case 'following': return 'Collecting logs'
    case 'catching_up': return 'Collecting earlier logs'
    case 'archived': return 'Retained archive'
    case 'ready': return 'Ready to collect'
    case 'error': return 'Collection error'
    default: return 'Status unknown'
  }
}

function isDockerDaemon(source: LogSource): boolean {
  return source.service === 'docker' && (source.kind === 'daemon' || (source.kind === 'collector' && source.name.endsWith(':docker')))
}

export function sourceLabel(source: LogSource): string {
  if (isDockerDaemon(source)) return 'Docker daemon'
  if (source.kind === 'collector') return 'Final collection check'
  if (source.kind === 'daemon') return source.service === 'docker' ? 'Docker daemon' : 'Log daemon'
  if (source.kind === 'container') {
    const id = containerIdentity(source)
    const stream = source.stream === 'stdout' ? 'Standard output' : source.stream === 'stderr' ? 'Standard error' : 'Container'
    return id ? `${stream} ${id}` : `Container log · ID ${source.id.slice(-8)}`
  }
  if (['file', 'archive', 'gzip', 'zip'].includes(source.kind)) {
    return `${basename(source.member || source.archive || source.name)} · ${fileIdentity(source)}`
  }
  return `Log source · ID ${source.id.slice(-8)}`
}

export function sourceSummary(source: LogSource): string {
  if (isDockerDaemon(source)) return statusLabel(source)
  if (source.kind === 'collector') {
    const target = source.name.match(/:checkpoint:([a-f0-9]{12,64})$/i)?.[1]
    return target ? `Container ${target.slice(0, 8)} · Failed` : `Collection failed · ID ${source.id.slice(-8)}`
  }
  const status = statusLabel(source)
  if (source.member) {
    const archive = source.archive ? basename(source.archive) : source.name.match(/([^/:]+\.(?:zip|gz))(?::|$)/i)?.[1]
    return archive ? `${status} · ${archive}` : `${status} · Archive member`
  }
  if (source.kind === 'file') return `Log file · ${status}`
  if (['archive', 'gzip', 'zip'].includes(source.kind)) return `Compressed log · ${status}`
  return status
}

export function sourceDescription(source: LogSource): string {
  return [
    source.name,
    `Node: ${source.node}`,
    `Kind: ${source.kind}`,
    `Format: ${source.format}`,
    `Status: ${source.status}`,
    `Size: ${source.size} bytes`,
    `Available: ${source.available !== false ? 'yes' : 'no'}`,
    source.service && `Service: ${source.service}`,
    source.stream && `Stream: ${source.stream}`,
    source.container && `Container: ${source.container}`,
    source.archive && `Archive: ${source.archive}`,
    source.member && `Member: ${source.member}`,
    source.lastCollectedAt && `Collected: ${source.lastCollectedAt}`,
    source.retainedFrom && `Retained since: ${source.retainedFrom}`,
    source.error && `Error: ${source.error}`,
  ].filter(Boolean).join('\n')
}
