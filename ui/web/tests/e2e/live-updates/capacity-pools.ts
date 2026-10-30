export interface PoolStats { id: number; active: number; free: number; limit: number }
export interface PoolWorkerStats { worker_id: number; process: { pid: number }; pools: PoolStats[] }

/** Pool IDs are worker-local object identities; array positions can change on lazy registration. */
export function quietPoolAssignmentViolations(before: readonly (PoolWorkerStats | undefined)[], after: PoolWorkerStats): string[] {
  const prefix = `worker ${after.worker_id} PID ${after.process.pid}`
  if (!before.length || before.some(worker => !worker || worker.worker_id !== after.worker_id || worker.process.pid !== after.process.pid)) {
    return [`${prefix}: missing or changed worker baseline`]
  }
  const errors = new Set<string>()
  const envelope = new Map<number, { active: number; limit: number }>()
  const poolMap = (pools: PoolStats[]) => {
    const mapped = new Map<number, PoolStats>()
    for (const pool of pools) {
      if (!Number.isSafeInteger(pool.id) || pool.id < 1) errors.add(`${prefix}: invalid pool identity`)
      if (mapped.has(pool.id)) errors.add(`${prefix}: duplicate pool ${pool.id}`)
      mapped.set(pool.id, pool)
    }
    return mapped
  }
  for (const worker of before) {
    for (const [id, pool] of poolMap(worker!.pools)) {
      const previous = envelope.get(id)
      if (previous && previous.limit !== pool.limit) errors.add(`${prefix} pool ${id}: changed limit`)
      envelope.set(id, { active: Math.max(previous?.active ?? 0, pool.active), limit: pool.limit })
    }
  }
  const current = poolMap(after.pools)
  for (const id of envelope.keys()) {
    if (!current.has(id)) errors.add(`${prefix}: missing baseline pool ${id}`)
  }
  for (const [id, pool] of current) {
    const baseline = envelope.get(id)
    if (baseline && baseline.limit !== pool.limit) errors.add(`${prefix} pool ${id}: changed limit`)
    if (pool.active > (baseline?.active ?? 0)) {
      errors.add(`${prefix} pool ${id}: assigned ${pool.active} exceeds quiet envelope ${baseline?.active ?? 0}`)
    }
  }
  return [...errors]
}
