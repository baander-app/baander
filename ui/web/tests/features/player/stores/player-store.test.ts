import { describe, it, expect, beforeEach } from 'vitest'
import { usePlayerStore, type Track } from '@/features/player/stores/player-store'

const mockTrack: Track = {
  publicId: 'song_1',
  title: 'Test Song',
  artistName: 'Test Artist',
  albumName: 'Test Album',
  duration: 240,
  filePath: '/music/test-song.flac',
}

const mockTrack2: Track = {
  publicId: 'song_2',
  title: 'Test Song 2',
  artistName: 'Test Artist 2',
  duration: 180,
  filePath: '/music/test-song-2.flac',
}

beforeEach(() => {
  localStorage.clear()
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
})

describe('player-store', () => {
  describe('playTrack', () => {
    it('sets current track and starts playing', () => {
      usePlayerStore.getState().playTrack(mockTrack)
      const state = usePlayerStore.getState()
      expect(state.currentTrack).toEqual(mockTrack)
      expect(state.isPlaying).toBe(true)
    })

    it('replaces queue when queue is provided', () => {
      usePlayerStore.getState().playTrack(mockTrack, [mockTrack, mockTrack2])
      const state = usePlayerStore.getState()
      expect(state.queue).toHaveLength(2)
      expect(state.currentIndex).toBe(0)
      expect(state.currentTrack).toEqual(mockTrack)
    })

    it('finds track in existing queue', () => {
      usePlayerStore.setState({ queue: [mockTrack, mockTrack2] })
      usePlayerStore.getState().playTrack(mockTrack2)
      const state = usePlayerStore.getState()
      expect(state.queue).toHaveLength(2)
      expect(state.currentIndex).toBe(1)
      expect(state.currentTrack).toEqual(mockTrack2)
    })

    it('appends track to queue if not present and no queue provided', () => {
      usePlayerStore.getState().playTrack(mockTrack)
      usePlayerStore.getState().playTrack(mockTrack2)
      const state = usePlayerStore.getState()
      expect(state.queue).toHaveLength(2)
      expect(state.currentIndex).toBe(1)
    })
  })

  describe('addToQueue', () => {
    it('adds track to queue without starting playback', () => {
      usePlayerStore.getState().addToQueue(mockTrack)
      const state = usePlayerStore.getState()
      expect(state.queue).toHaveLength(1)
      expect(state.isPlaying).toBe(false)
      expect(state.currentTrack).toBeNull()
    })

    it('does not add duplicate tracks', () => {
      usePlayerStore.setState({ queue: [mockTrack] })
      usePlayerStore.getState().addToQueue(mockTrack)
      expect(usePlayerStore.getState().queue).toHaveLength(1)
    })
  })

  describe('playNext', () => {
    it('advances to next track in queue', () => {
      usePlayerStore.setState({ queue: [mockTrack, mockTrack2], currentIndex: 0, currentTrack: mockTrack })
      usePlayerStore.getState().playNext()
      const state = usePlayerStore.getState()
      expect(state.currentIndex).toBe(1)
      expect(state.currentTrack).toEqual(mockTrack2)
    })

    it('stops at end of queue when repeat is off', () => {
      usePlayerStore.setState({ queue: [mockTrack, mockTrack2], currentIndex: 1, currentTrack: mockTrack2, repeat: 'off' as const })
      usePlayerStore.getState().playNext()
      expect(usePlayerStore.getState().isPlaying).toBe(false)
    })

    it('loops to start when repeat is all', () => {
      usePlayerStore.setState({ queue: [mockTrack, mockTrack2], currentIndex: 1, currentTrack: mockTrack2, repeat: 'all' as const })
      usePlayerStore.getState().playNext()
      const state = usePlayerStore.getState()
      expect(state.currentIndex).toBe(0)
      expect(state.isPlaying).toBe(true)
    })
  })

  describe('playPrevious', () => {
    it('goes to previous track', () => {
      usePlayerStore.setState({ queue: [mockTrack, mockTrack2], currentIndex: 1, currentTrack: mockTrack2 })
      usePlayerStore.getState().playPrevious()
      const state = usePlayerStore.getState()
      expect(state.currentIndex).toBe(0)
      expect(state.currentTrack).toEqual(mockTrack)
    })

    it('restarts current track if past 3 seconds', () => {
      usePlayerStore.setState({ queue: [mockTrack, mockTrack2], currentIndex: 1, currentTrack: mockTrack2, currentTime: 5 })
      usePlayerStore.getState().playPrevious()
      const state = usePlayerStore.getState()
      expect(state.currentIndex).toBe(1)
      expect(state.currentTime).toBe(0)
    })

    it('wraps to last track if at start of queue', () => {
      usePlayerStore.setState({ queue: [mockTrack, mockTrack2], currentIndex: 0, currentTrack: mockTrack })
      usePlayerStore.getState().playPrevious()
      expect(usePlayerStore.getState().currentIndex).toBe(1)
    })
  })

  describe('removeFromQueue', () => {
    it('removes track and adjusts current index', () => {
      usePlayerStore.setState({ queue: [mockTrack, mockTrack2], currentIndex: 0, currentTrack: mockTrack })
      usePlayerStore.getState().removeFromQueue(0)
      const state = usePlayerStore.getState()
      expect(state.queue).toHaveLength(1)
      expect(state.queue[0]).toEqual(mockTrack2)
      expect(state.currentIndex).toBe(0)
    })
  })

  describe('clearQueue', () => {
    it('resets all queue and playback state', () => {
      usePlayerStore.setState({
        queue: [mockTrack, mockTrack2], currentIndex: 1, currentTrack: mockTrack2,
        isPlaying: true, currentTime: 50, duration: 180,
      })
      usePlayerStore.getState().clearQueue()
      const state = usePlayerStore.getState()
      expect(state.queue).toHaveLength(0)
      expect(state.currentTrack).toBeNull()
      expect(state.isPlaying).toBe(false)
      expect(state.currentTime).toBe(0)
      expect(state.duration).toBe(0)
    })
  })

  describe('volume', () => {
    it('clamps volume to 0-100', () => {
      usePlayerStore.getState().setVolume(150)
      expect(usePlayerStore.getState().volume).toBe(100)
      usePlayerStore.getState().setVolume(-10)
      expect(usePlayerStore.getState().volume).toBe(0)
    })

    it('toggles mute', () => {
      expect(usePlayerStore.getState().muted).toBe(false)
      usePlayerStore.getState().toggleMute()
      expect(usePlayerStore.getState().muted).toBe(true)
      usePlayerStore.getState().toggleMute()
      expect(usePlayerStore.getState().muted).toBe(false)
    })
  })

  describe('repeat modes', () => {
    it('cycles through off -> all -> one -> off', () => {
      expect(usePlayerStore.getState().repeat).toBe('off')
      usePlayerStore.getState().toggleRepeat()
      expect(usePlayerStore.getState().repeat).toBe('all')
      usePlayerStore.getState().toggleRepeat()
      expect(usePlayerStore.getState().repeat).toBe('one')
      usePlayerStore.getState().toggleRepeat()
      expect(usePlayerStore.getState().repeat).toBe('off')
    })
  })

  describe('shuffle', () => {
    it('toggles shuffle', () => {
      expect(usePlayerStore.getState().shuffle).toBe(false)
      usePlayerStore.getState().toggleShuffle()
      expect(usePlayerStore.getState().shuffle).toBe(true)
      usePlayerStore.getState().toggleShuffle()
      expect(usePlayerStore.getState().shuffle).toBe(false)
    })
  })
})
