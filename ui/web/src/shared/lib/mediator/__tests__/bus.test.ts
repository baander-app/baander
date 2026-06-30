import { describe, it, expect, beforeEach, vi } from 'vitest'
import { ActionBus } from '../bus'

describe('ActionBus', () => {
  let bus: ActionBus

  beforeEach(() => {
    bus = new ActionBus({ maxLogSize: 100 })
  })

  describe('on / dispatch', () => {
    it('calls registered handler when action is dispatched', () => {
      const handler = vi.fn()
      bus.on('player:pause', handler)

      bus.dispatch('player:pause', { reason: 'test' }, 'test-context')

      expect(handler).toHaveBeenCalledOnce()
      expect(handler).toHaveBeenCalledWith({ reason: 'test' })
    })

    it('does not call handlers for other action types', () => {
      const pauseHandler = vi.fn()
      const playHandler = vi.fn()

      bus.on('player:pause', pauseHandler)
      bus.on('player:play', playHandler)

      bus.dispatch('player:pause', {}, 'test')

      expect(pauseHandler).toHaveBeenCalledOnce()
      expect(playHandler).not.toHaveBeenCalled()
    })

    it('calls multiple handlers for the same action type', () => {
      const handler1 = vi.fn()
      const handler2 = vi.fn()

      bus.on('player:pause', handler1)
      bus.on('player:pause', handler2)

      bus.dispatch('player:pause', {}, 'test')

      expect(handler1).toHaveBeenCalledOnce()
      expect(handler2).toHaveBeenCalledOnce()
    })

    it('unsubscribe function removes the handler', () => {
      const handler = vi.fn()
      const unsub = bus.on('player:pause', handler)

      unsub()
      bus.dispatch('player:pause', {}, 'test')

      expect(handler).not.toHaveBeenCalled()
    })
  })

  describe('action log', () => {
    it('records dispatched actions in the log', () => {
      bus.on('player:pause', vi.fn())
      bus.dispatch('player:pause', { reason: 'radio' }, 'radio')

      const log = bus.getActionLog()
      expect(log).toHaveLength(1)
      expect(log[0].type).toBe('player:pause')
      expect(log[0].source).toBe('radio')
      expect(log[0].payload).toEqual({ reason: 'radio' })
      expect(log[0].handlerCount).toBe(1)
      expect(log[0].errors).toHaveLength(0)
    })

    it('assigns monotonically increasing IDs', () => {
      bus.on('player:pause', vi.fn())

      bus.dispatch('player:pause', {}, 'test')
      bus.dispatch('player:pause', {}, 'test')

      const log = bus.getActionLog()
      expect(log[0].id).toBeLessThan(log[1].id)
    })

    it('records timestamps as ISO strings', () => {
      bus.on('player:pause', vi.fn())
      bus.dispatch('player:pause', {}, 'test')

      const log = bus.getActionLog()
      expect(typeof log[0].timestamp).toBe('string')
      expect(new Date(log[0].timestamp).toISOString()).toBe(log[0].timestamp)
    })

    it('evicts oldest entries when log exceeds maxLogSize', () => {
      const smallBus = new ActionBus({ maxLogSize: 3 })
      smallBus.on('player:pause', vi.fn())

      smallBus.dispatch('player:pause', {}, 'test')
      smallBus.dispatch('player:pause', {}, 'test')
      smallBus.dispatch('player:pause', {}, 'test')
      smallBus.dispatch('player:pause', {}, 'test')

      const log = smallBus.getActionLog()
      expect(log).toHaveLength(3)
      expect(log[0].id).toBe(2)
    })

    it('clearLog empties the action log', () => {
      bus.on('player:pause', vi.fn())
      bus.dispatch('player:pause', {}, 'test')

      bus.clearLog()

      expect(bus.getActionLog()).toHaveLength(0)
    })

    it('records handler errors without breaking dispatch', () => {
      bus.on('player:pause', () => {
        throw new Error('handler exploded')
      })
      bus.on('player:pause', vi.fn())

      bus.dispatch('player:pause', {}, 'test')

      const log = bus.getActionLog()
      expect(log[0].errors).toHaveLength(1)
      expect(log[0].errors[0].message).toBe('handler exploded')
      expect(log[0].handlerCount).toBe(2)
    })

    it('records zero handlerCount for actions with no handlers', () => {
      bus.dispatch('player:pause', {}, 'test')

      const log = bus.getActionLog()
      expect(log[0].handlerCount).toBe(0)
    })
  })

  describe('subscribe (global listener)', () => {
    it('subscriber receives all dispatched actions', () => {
      const listener = vi.fn()
      bus.on('player:pause', vi.fn())
      bus.on('player:play', vi.fn())

      bus.subscribe(listener)

      bus.dispatch('player:pause', {}, 'test')
      bus.dispatch('player:play', {}, 'test')

      expect(listener).toHaveBeenCalledTimes(2)
      expect(listener).toHaveBeenCalledWith(
        expect.objectContaining({ type: 'player:pause' }),
      )
    })

    it('unsubscribe function stops the listener', () => {
      const listener = vi.fn()
      const unsub = bus.subscribe(listener)

      unsub()
      bus.on('player:pause', vi.fn())
      bus.dispatch('player:pause', {}, 'test')

      expect(listener).not.toHaveBeenCalled()
    })
  })

  describe('getHandlerMap', () => {
    it('returns map of action types to handler descriptions', () => {
      bus.on('player:pause', function playerPauseHandler() {})
      bus.on('player:pause', function anotherPauseHandler() {})
      bus.on('player:play', function playerPlayHandler() {})

      const map = bus.getHandlerMap()

      expect(map['player:pause']).toHaveLength(2)
      expect(map['player:play']).toHaveLength(1)
      expect(map['player:pause']).toContain('playerPauseHandler')
      expect(map['player:pause']).toContain('anotherPauseHandler')
      expect(map['player:play']).toContain('playerPlayHandler')
    })
  })

  describe('recursion guard', () => {
    it('warns when dispatch exceeds max recursion depth', () => {
      const warnSpy = vi.spyOn(console, 'warn').mockImplementation(() => {})

      bus.on('player:pause', () => {
        bus.dispatch('player:pause', {}, 'recursive')
      })

      bus.dispatch('player:pause', {}, 'test')

      expect(warnSpy).toHaveBeenCalledWith(
        expect.stringContaining('recursion'),
      )

      warnSpy.mockRestore()
    })
  })

  describe('warn on no handlers', () => {
    it('warns when dispatching an action with no handlers', () => {
      const warnSpy = vi.spyOn(console, 'warn').mockImplementation(() => {})

      bus.dispatch('player:pause', {}, 'test')

      expect(warnSpy).toHaveBeenCalledWith(
        expect.stringContaining('No handlers'),
      )

      warnSpy.mockRestore()
    })

    it('does not warn when warnOnNoHandlers is false', () => {
      const quietBus = new ActionBus({ warnOnNoHandlers: false })
      const warnSpy = vi.spyOn(console, 'warn').mockImplementation(() => {})

      quietBus.dispatch('player:pause', {}, 'test')

      expect(warnSpy).not.toHaveBeenCalledWith(
        expect.stringContaining('No handlers'),
      )

      warnSpy.mockRestore()
    })
  })
})
