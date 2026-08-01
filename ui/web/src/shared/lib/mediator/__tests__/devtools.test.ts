import { describe, it, expect, beforeEach, vi } from 'vitest'
import { ActionBus } from '../bus'
import { filterActionLog } from '../devtools'

describe('devtools', () => {
  let bus: ActionBus

  beforeEach(() => {
    bus = new ActionBus({ maxLogSize: 100 })
  })

  describe('action log entries', () => {
    it('have correct timestamps, sources, and payloads', () => {
      bus.on('player:pause', vi.fn())
      bus.dispatch('player:pause', { reason: 'radio' }, 'radio')

      const [entry] = bus.getActionLog()
      expect(entry.timestamp).toBeTruthy()
      expect(new Date(entry.timestamp).toISOString()).toBe(entry.timestamp)
      expect(entry.source).toBe('radio')
      expect(entry.payload).toEqual({ reason: 'radio' })
      expect(entry.type).toBe('player:pause')
    })
  })

  describe('filterActionLog', () => {
    beforeEach(() => {
      bus.on('player:pause', vi.fn())
      bus.on('player:play', vi.fn())
      bus.on('settings:apply-eq', vi.fn())

      bus.dispatch('player:pause', { reason: 'radio' }, 'radio')
      bus.dispatch('player:play', {}, 'catalog')
      bus.dispatch('settings:apply-eq', { bands: [1, 2, 3] }, 'settings')
      bus.dispatch('player:pause', { reason: 'test' }, 'test')
    })

    it('filters by action type prefix', () => {
      const log = bus.getActionLog()
      const filtered = filterActionLog(log, { typePrefix: 'player:' })
      expect(filtered).toHaveLength(3)
      expect(filtered.every((e) => e.type.startsWith('player:'))).toBe(true)
    })

    it('filters by source context', () => {
      const log = bus.getActionLog()
      const filtered = filterActionLog(log, { source: 'radio' })
      expect(filtered).toHaveLength(1)
      expect(filtered[0].source).toBe('radio')
    })

    it('combines type prefix and source filters', () => {
      const log = bus.getActionLog()
      const filtered = filterActionLog(log, { typePrefix: 'player:', source: 'radio' })
      expect(filtered).toHaveLength(1)
    })

    it('returns all entries when no filters provided', () => {
      const log = bus.getActionLog()
      const filtered = filterActionLog(log, {})
      expect(filtered).toHaveLength(4)
    })
  })

  describe('getHandlerMap', () => {
    it('returns map of action types to registered handler names', () => {
      bus.on('player:pause', function pauseHandler() {})
      bus.on('player:play', function playHandler() {})

      const map = bus.getHandlerMap()
      expect(map['player:pause']).toEqual(['pauseHandler'])
      expect(map['player:play']).toEqual(['playHandler'])
    })
  })

  describe('clearLog', () => {
    it('empties the log', () => {
      bus.on('player:pause', vi.fn())
      bus.dispatch('player:pause', {}, 'test')

      bus.clearLog()
      expect(bus.getActionLog()).toHaveLength(0)
    })
  })
})
