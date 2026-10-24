const requireEvidence = (condition, message) => {
  if (!condition) throw new Error(`Unexplained video cancellation: ${message}`)
}
const signedQuery = url => ['sig', 'exp'].every(key => url.searchParams.getAll(key).length === 1)
  && /^[a-f0-9]{64}$/.test(url.searchParams.get('sig') ?? '')
  && /^[1-9]\d*$/.test(url.searchParams.get('exp') ?? '')
  && Number.isSafeInteger(Number(url.searchParams.get('exp')))

function authenticatedSet(fixture) {
  const master = new URL(fixture.manifestUrl)
  requireEvidence(master.origin === fixture.origin && /\/master\.m3u8$/.test(master.pathname) && signedQuery(master), 'invalid signed master')
  requireEvidence(master.searchParams.getAll('set').length === 1, 'missing authenticated rendition set')
  const known = new Map(Object.values(fixture.tiers).map(tier => [tier.jobId, tier.name]))
  const members = new Map()
  for (const entry of (master.searchParams.get('set') ?? '').split(',')) {
    const match = /^([A-Za-z0-9_-]+)\.([1-9]\d*)$/.exec(entry)
    requireEvidence(match && known.has(match[1]) && !members.has(match[1]) && Number.isSafeInteger(Number(match[2])), 'unknown or repeated set member')
    members.set(match[1], { tier: known.get(match[1]), generation: Number(match[2]) })
  }
  requireEvidence(known.size >= 2 && members.size === known.size, 'incomplete authenticated rendition set')
  return { members, expires: Number(master.searchParams.get('exp')), encoded: master.searchParams.get('set') }
}

function videoResource(value, fixture, authority) {
  try {
    const url = new URL(value)
    const match = /^\/api\/transcode\/([A-Za-z0-9_-]+)\/tracks\/video\/segment\.m4s$/.exec(url.pathname)
    if (url.origin !== fixture.origin || !match || !signedQuery(url)) return null
    if ([...url.searchParams.keys()].some(key => !['sig', 'exp', 'generation', 'index'].includes(key))) return null
    if (!['generation', 'index'].every(key => url.searchParams.getAll(key).length === 1)) return null
    if (!/^[1-9]\d*$/.test(url.searchParams.get('generation') ?? '') || !/^(0|[1-9]\d*)$/.test(url.searchParams.get('index') ?? '')) return null
    const generation = Number(url.searchParams.get('generation'))
    const index = Number(url.searchParams.get('index'))
    const member = authority.members.get(match[1])
    if (!member || !Number.isSafeInteger(index) || generation !== member.generation || Number(url.searchParams.get('exp')) !== authority.expires) return null
    return { url: url.href, jobId: match[1], tier: member.tier, generation, index }
  } catch { return null }
}

export function canDeferVideoCancellation(failure, fixture) {
  if (failure.canceled !== true || failure.error !== 'net::ERR_ABORTED' || failure.blockedReason || failure.method !== 'GET') return false
  try { return videoResource(failure.url, fixture, authenticatedSet(fixture)) !== null } catch { return false }
}

function serverReceipt(transfer, resource, requests, fixture, authority, terminal) {
  requireEvidence(Number.isFinite(transfer.wallTime) && Number.isFinite(transfer.started) && Number.isFinite(terminal)
    && terminal >= transfer.started, 'missing transfer timestamps')
  const endWall = transfer.wallTime + terminal - transfer.started
  const matches = requests.filter(receipt => {
    try {
      return new URL(receipt.path, fixture.origin).href === resource.url
        && receipt.time >= transfer.wallTime - 0.5 && receipt.time <= endWall + 0.5
    } catch { return false }
  })
  requireEvidence(matches.length === 1, 'missing or ambiguous server receipt')
  const receipt = matches[0]
  // HTTP 200 here records the fixture server's actual signature validation.
  requireEvidence(receipt.status === 200 && receipt.closed === true && receipt.track === 'video'
    && receipt.jobId === resource.jobId && receipt.tier === resource.tier && receipt.index === resource.index
    && Number.isFinite(receipt.time) && receipt.time <= authority.expires
    && Number.isFinite(receipt.bytes) && receipt.bytes > 0
    && Number.isFinite(receipt.segmentStart) && receipt.segmentStart >= 0
    && Number.isFinite(receipt.segmentEnd) && receipt.segmentEnd > receipt.segmentStart, 'invalid signed closed-file server receipt')
  return receipt
}

export function classifyVideoCancellations({ fixture, cancellations, network, requests }) {
  if (cancellations.length === 0) return []
  const authority = authenticatedSet(fixture)
  return cancellations.map(failure => {
    requireEvidence(canDeferVideoCancellation(failure, fixture), 'ineligible transport error')
    const abandoned = network.filter(transfer => transfer.id === failure.id)
    requireEvidence(abandoned.length === 1, 'missing or ambiguous abandoned transfer')
    const transfer = abandoned[0]
    requireEvidence(transfer.url === failure.url && transfer.method === 'GET' && transfer.canceled === true
      && transfer.error === 'net::ERR_ABORTED' && !transfer.blockedReason && Number.isFinite(transfer.failed)
      && failure.failed === transfer.failed && transfer.finished === undefined && (transfer.status === undefined || transfer.status === 200), 'invalid abandoned transfer')
    const source = videoResource(transfer.url, fixture, authority)
    const sourceReceipt = serverReceipt(transfer, source, requests, fixture, authority, transfer.failed)
    const candidates = network.flatMap(other => {
      const resource = videoResource(other.url, fixture, authority)
      const overlap = other.started - transfer.failed
      if (!resource || resource.jobId === source.jobId || resource.tier === source.tier || resource.generation !== source.generation
        || other.method !== 'GET' || other.started <= transfer.started || overlap < -0.5 || overlap >= 12
        || !Number.isFinite(other.started) || !Number.isFinite(other.wallTime)
        || Math.abs((other.wallTime - transfer.wallTime) - (other.started - transfer.started)) > 0.5
        || !Number.isFinite(other.finished) || other.finished < other.started
        || other.failed !== undefined || other.error || other.canceled || other.blockedReason || other.status !== 200
        || other.fromDiskCache !== false || other.fromServiceWorker !== false || !Number.isFinite(other.bytes) || other.bytes <= 0) return []
      let receipt
      try { receipt = serverReceipt(other, resource, requests, fixture, authority, other.finished) } catch { return [] }
      if (Math.abs(receipt.segmentStart - sourceReceipt.segmentStart) >= 0.05
        || Math.abs(receipt.segmentEnd - sourceReceipt.segmentEnd) >= 0.05) return []
      return [{ transfer: other, serverReceipt: receipt, resource, secondsFromAbort: overlap }]
    })
    requireEvidence(candidates.length === 1, 'no unique completed replacement in the authenticated native interval')
    return { cancellation: failure, abandoned: { transfer, serverReceipt: sourceReceipt, resource: source },
      replacement: candidates[0], authenticatedSet: authority.encoded }
  })
}
