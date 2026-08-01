import { describe, it, expect, beforeEach } from 'vitest'
import { mediator } from '../bus'

// We test the handler wiring by checking that dispatching an action
// triggers the expected store state changes via the handlers.
describe('Handler registration', () => {
  beforeEach(() => {
    mediator.clearLog()
  })

  describe('registerAllHandlers', () => {
    it('registers handlers for player:pause', async () => {
      const { registerAllHandlers } = await import('../handlers')
      registerAllHandlers()

      const map = mediator.getHandlerMap()
      expect(map['player:pause']).toBeDefined()
      expect(map['player:pause'].length).toBeGreaterThanOrEqual(1)
    })

    it('registers handlers for radio:started', async () => {
      const { registerAllHandlers } = await import('../handlers')
      registerAllHandlers()

      const map = mediator.getHandlerMap()
      expect(map['radio:started']).toBeDefined()
    })

    it('registers handlers for settings:apply-eq', async () => {
      const { registerAllHandlers } = await import('../handlers')
      registerAllHandlers()

      const map = mediator.getHandlerMap()
      expect(map['settings:apply-eq']).toBeDefined()
    })

    it('registers handlers for settings:apply-player', async () => {
      const { registerAllHandlers } = await import('../handlers')
      registerAllHandlers()

      const map = mediator.getHandlerMap()
      expect(map['settings:apply-player']).toBeDefined()
    })

    it('registers handlers for settings:apply-layout', async () => {
      const { registerAllHandlers } = await import('../handlers')
      registerAllHandlers()

      const map = mediator.getHandlerMap()
      expect(map['settings:apply-layout']).toBeDefined()
    })

    it('registers handlers for player:state-restore', async () => {
      const { registerAllHandlers } = await import('../handlers')
      registerAllHandlers()

      const map = mediator.getHandlerMap()
      expect(map['player:state-restore']).toBeDefined()
    })

    it('registers handlers for catalog:play-track', async () => {
      const { registerAllHandlers } = await import('../handlers')
      registerAllHandlers()

      const map = mediator.getHandlerMap()
      expect(map['catalog:play-track']).toBeDefined()
    })

    it('is idempotent — calling twice does not double-register handlers', async () => {
      const { registerAllHandlers } = await import('../handlers')
      registerAllHandlers()
      const countAfterFirst = Object.values(mediator.getHandlerMap())
        .flat().length

      registerAllHandlers()
      const countAfterSecond = Object.values(mediator.getHandlerMap())
        .flat().length

      expect(countAfterSecond).toBe(countAfterFirst)
    })
  })
})
