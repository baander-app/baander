import { describe, it, expect, beforeEach, vi } from 'vitest'
import { ActionBus } from '../bus'
import { usePlayerStore, type Track } from '@/features/player/stores/player-store'
import { useRadioStore } from '@/features/radio/stores/radio-store'
import { useEqBandsStore, DEFAULT_Q } from '@/features/equalizer/stores/eq-bands-store'
import { useContextPanelStore } from '@/features/layout/stores/context-panel-store'

describe('Mediator integration', () => {
  let bus: ActionBus

  beforeEach(() => {
    bus = new ActionBus({ maxLogSize: 100, warnOnNoHandlers: false })

    // Reset stores to initial state
    usePlayerStore.setState({
      queue: [],
      currentIndex: -1,
      currentTrack: null,
      isPlaying: false,
      currentTime: 0,
      duration: 0,
      shuffle: false,
      repeat: 'off' as const,
      volume: 75,
      muted: false,
      audioElement: null,
    })
    useRadioStore.setState({
      activeStation: null,
      activeStreamUrl: null,
      isPlaying: false,
      streamFallbackIndex: 0,
      audioElement: null,
      allStreamsFailed: false,
    })
    useEqBandsStore.setState({
      enabled: true,
      bands: [0, 0, 0, 0, 0, 0, 0, 0, 0, 0].map((gain) => ({ gain, q: DEFAULT_Q })),
      preset: 'FLAT',
      visualizerMode: 'spectrum',
    })
    useContextPanelStore.setState({
      mode: 'expanded',
      activeTab: 'queue',
      selectedItem: null,
      isOpen: true,
    })
  })

  describe('player:pause', () => {
    it('pauses the player store', () => {
      usePlayerStore.setState({ isPlaying: true })

      // Simulate handler
      bus.on('player:pause', function playerPauseHandler() {
        const state = usePlayerStore.getState()
        if (state.isPlaying) state.setIsPlaying(false)
      })

      bus.dispatch('player:pause', { reason: 'radio-started' }, 'radio')

      expect(usePlayerStore.getState().isPlaying).toBe(false)
    })
  })

  describe('radio:started → player pause', () => {
    it('player handler pauses playback when radio starts', () => {
      usePlayerStore.setState({ isPlaying: true })

      bus.on('radio:started', function playerPauseForRadioHandler() {
        const state = usePlayerStore.getState()
        if (state.isPlaying) state.setIsPlaying(false)
      })

      bus.dispatch('radio:started', { station: { name: 'Test FM', streams: [] } } as any, 'radio')

      expect(usePlayerStore.getState().isPlaying).toBe(false)
    })
  })

  describe('settings:apply-eq', () => {
    it('updates EQ store bands via handler', () => {
      bus.on('settings:apply-eq', function eqApplySettingsHandler(payload: unknown) {
        const p = payload as { bands?: number[] }
        if (p.bands) useEqBandsStore.setState({ bands: p.bands.map((gain: number) => ({ gain, q: DEFAULT_Q })) })
      })

      bus.dispatch('settings:apply-eq', { bands: [1, 2, 3, 4, 5, 6, 7, 8, 9, 10] }, 'settings')

      expect(useEqBandsStore.getState().bands).toEqual([1, 2, 3, 4, 5, 6, 7, 8, 9, 10].map((gain) => ({ gain, q: DEFAULT_Q })))
    })
  })

  describe('settings:apply-player', () => {
    it('updates player store volume via handler', () => {
      bus.on('settings:apply-player', function playerApplySettingsHandler(payload: unknown) {
        const p = payload as { volume?: number }
        if (p.volume !== undefined) usePlayerStore.setState({ volume: p.volume })
      })

      bus.dispatch('settings:apply-player', { volume: 42 }, 'settings')

      expect(usePlayerStore.getState().volume).toBe(42)
    })
  })

  describe('settings:apply-layout', () => {
    it('updates context panel mode via handler', () => {
      bus.on('settings:apply-layout', function contextPanelApplyLayoutHandler(payload: unknown) {
        const p = payload as { contextPanelMode: 'compact' | 'expanded' }
        useContextPanelStore.setState({ mode: p.contextPanelMode })
      })

      bus.dispatch('settings:apply-layout', { contextPanelMode: 'compact' }, 'settings')

      expect(useContextPanelStore.getState().mode).toBe('compact')
    })
  })

  describe('player:state-restore', () => {
    it('restores player queue and position', () => {
      const tracks: Track[] = [
        { publicId: 't1', title: 'Song 1', artistName: 'Artist' },
        { publicId: 't2', title: 'Song 2', artistName: 'Artist' },
      ]

      bus.on('player:state-restore', function playerStateRestoreHandler(payload: unknown) {
        const p = payload as { queue: Track[]; currentIndex: number; currentTime: number }
        usePlayerStore.setState({
          queue: p.queue,
          currentIndex: p.currentIndex,
          currentTime: p.currentTime,
        })
      })

      bus.dispatch('player:state-restore', {
        queue: tracks,
        currentIndex: 1,
        currentTime: 45.5,
      }, 'session')

      const state = usePlayerStore.getState()
      expect(state.queue).toEqual(tracks)
      expect(state.currentIndex).toBe(1)
      expect(state.currentTime).toBe(45.5)
    })
  })

  describe('full action log', () => {
    it('captures a complete cross-context interaction sequence', () => {
      bus.on('player:pause', vi.fn())
      bus.on('player:play', vi.fn())
      bus.on('settings:apply-eq', vi.fn())

      bus.dispatch('player:pause', { reason: 'radio' }, 'radio')
      bus.dispatch('settings:apply-eq', { bands: [1, 2, 3] }, 'settings')
      bus.dispatch('player:play', {}, 'catalog')

      const log = bus.getActionLog()
      expect(log).toHaveLength(3)

      expect(log[0].type).toBe('player:pause')
      expect(log[0].source).toBe('radio')

      expect(log[1].type).toBe('settings:apply-eq')
      expect(log[1].source).toBe('settings')

      expect(log[2].type).toBe('player:play')
      expect(log[2].source).toBe('catalog')
    })
  })
})
