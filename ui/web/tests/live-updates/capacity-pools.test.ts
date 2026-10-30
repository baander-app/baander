import { describe, expect, it } from 'vitest'
import { quietPoolAssignmentViolations, type PoolStats } from '../e2e/live-updates/capacity-pools'

const pool = (id: number, active = 0, free = 1): PoolStats => ({ id, active, free, limit: 10 })
const worker = (pools: PoolStats[], pid = 100) => ({ worker_id: 0, process: { pid }, pools })

describe('capacity quiet pool assignments', () => {
  it('compares identities across lazy priority insertion and reordered positions', () => {
    expect(quietPoolAssignmentViolations([worker([pool(1, 1), pool(2)])],
      worker([pool(3, 0, 2), pool(2), pool(1, 1)]))).toEqual([])
  })

  it('does not let a reordered leaking pool borrow another pool’s active envelope', () => {
    expect(quietPoolAssignmentViolations([worker([pool(1, 1), pool(2)])],
      worker([pool(2, 1), pool(1)]))).toEqual([expect.stringContaining('pool 2: assigned 1 exceeds quiet envelope 0')])
  })

  it('requires a newly observed pool to return with no assigned slots', () => {
    expect(quietPoolAssignmentViolations([worker([pool(1)])], worker([pool(1), pool(2, 1)])))
      .toEqual([expect.stringContaining('pool 2: assigned 1 exceeds quiet envelope 0')])
  })

  it('retains an observer-active envelope while allowing warmed free allocations', () => {
    expect(quietPoolAssignmentViolations([worker([pool(1)]), worker([pool(1, 1)])], worker([pool(1, 1, 4)])))
      .toEqual([])
  })

  it('rejects a missing previously observed pool', () => {
    expect(quietPoolAssignmentViolations([worker([pool(1), pool(2)])], worker([pool(1)])))
      .toEqual([expect.stringContaining('missing baseline pool 2')])
  })

  it('rejects changed worker identity rather than comparing a restarted process', () => {
    expect(quietPoolAssignmentViolations([worker([pool(1)])], worker([pool(1)], 101)))
      .toEqual([expect.stringContaining('missing or changed worker baseline')])
  })

  it('rejects missing, duplicate and invalid pool identities', () => {
    expect(quietPoolAssignmentViolations([undefined], worker([pool(1)])))
      .toEqual([expect.stringContaining('missing or changed worker baseline')])
    expect(quietPoolAssignmentViolations([worker([pool(1), pool(1)])], worker([pool(1)])))
      .toEqual([expect.stringContaining('duplicate pool 1')])
    expect(quietPoolAssignmentViolations([worker([pool(Number.NaN)])], worker([pool(Number.NaN)])))
      .toContainEqual(expect.stringContaining('invalid pool identity'))
  })

  it('rejects a pool limit changing under the same identity', () => {
    expect(quietPoolAssignmentViolations([worker([pool(1)])], worker([{ ...pool(1), limit: 20 }])))
      .toEqual([expect.stringContaining('pool 1: changed limit')])
  })
})
