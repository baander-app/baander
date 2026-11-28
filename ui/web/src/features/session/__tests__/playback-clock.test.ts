import { describe, expect, it } from 'vitest'
import { PlaybackClock } from '../services/playback-clock'

const epoch = Date.parse('2026-11-28T13:00:00Z')
const date = (milliseconds = 0) => new Date(epoch + milliseconds).toUTCString()

function time(skew = 0) {
  let elapsed = 0
  let offset = skew
  return {
    clock: new PlaybackClock(() => epoch + elapsed + offset, () => elapsed),
    advance: (milliseconds: number) => { elapsed += milliseconds },
    setOffset: (milliseconds: number) => { offset = milliseconds }
  }
}

describe('playback observation clock', () => {
  it.each([-20_000, 20_000])('uses the server Date with wall clock skew of %d ms', skew => {
    const { clock, advance, setOffset } = time(skew)
    advance(200)
    clock.observe(date(), 0)
    expect(clock.read().milliseconds).toBe(epoch)
    setOffset(-45_000)
    advance(12_345)
    expect(clock.read().milliseconds).toBe(epoch + 12_345)
  })

  it('retains accurate local milliseconds and refreshes without jitter inside the server interval', () => {
    const { clock, advance, setOffset } = time()
    advance(789)
    clock.observe(date(), 700)
    expect(clock.read()).toEqual({ milliseconds: epoch + 789, generation: 0 })
    setOffset(-30_000)
    advance(1_000)
    clock.observe(date(1_000), 1_700)
    expect(clock.read()).toEqual({ milliseconds: epoch + 1_789, generation: 0 })
  })

  it('falls back to the wall clock and retains a valid anchor across absent, malformed or out-of-order samples', () => {
    const { clock, advance } = time(-20_000)
    clock.observe(undefined, 0)
    expect(clock.read().milliseconds).toBe(epoch - 20_000)
    advance(200)
    clock.observe(date(), 100)
    advance(1_000)
    clock.observe(undefined, 1_100)
    clock.observe('unknown', 1_100)
    clock.observe(date(-8_000), 50)
    expect(clock.read()).toEqual({ milliseconds: epoch + 1_000, generation: 0 })
  })

  it('starts a new generation when a valid refresh corrects its timeline backwards', () => {
    const { clock, advance } = time(20_000)
    clock.observe(date(10_000), 0)
    expect(clock.read().milliseconds).toBe(epoch + 10_000)
    advance(1_000)
    clock.observe(date(1_000), 1_000)
    expect(clock.read()).toEqual({ milliseconds: epoch + 1_000, generation: 1 })
    advance(100)
    expect(clock.read()).toEqual({ milliseconds: epoch + 1_100, generation: 1 })
  })
})
