import { describe, it, expect, beforeEach } from 'vitest'
import { useMediaModeStore, MEDIA_TYPES, MEDIA_TYPE_LABELS } from '@/features/layout/stores/media-mode-store'

beforeEach(() => {
  localStorage.clear()
  useMediaModeStore.setState({ activeMedia: 'music' })
})

describe('media-mode-store', () => {
  describe('defaults', () => {
    it('defaults to music', () => {
      expect(useMediaModeStore.getState().activeMedia).toBe('music')
    })
  })

  describe('setActiveMedia', () => {
    it('sets active media type', () => {
      useMediaModeStore.getState().setActiveMedia('movies')
      expect(useMediaModeStore.getState().activeMedia).toBe('movies')
    })

    it('sets each valid media type', () => {
      const types: typeof MEDIA_TYPES = ['music', 'movies', 'tv', 'podcasts', 'concerts', 'ebooks']
      types.forEach((mt) => {
        useMediaModeStore.getState().setActiveMedia(mt)
        expect(useMediaModeStore.getState().activeMedia).toBe(mt)
      })
    })
  })

  describe('persistence', () => {
    it('persists activeMedia to localStorage', () => {
      useMediaModeStore.getState().setActiveMedia('tv')
      const stored = localStorage.getItem('baander-media-mode')
      expect(stored).toBeTruthy()
      const parsed = JSON.parse(stored!)
      expect(parsed.state.activeMedia).toBe('tv')
    })
  })

  describe('MEDIA_TYPES', () => {
    it('contains all 6 media types', () => {
      expect(MEDIA_TYPES).toEqual(['music', 'movies', 'tv', 'podcasts', 'concerts', 'ebooks'])
    })
  })

  describe('MEDIA_TYPE_LABELS', () => {
    it('has labels for all media types', () => {
      expect(MEDIA_TYPE_LABELS.music).toBe('Music')
      expect(MEDIA_TYPE_LABELS.movies).toBe('Movies')
      expect(MEDIA_TYPE_LABELS.tv).toBe('TV')
      expect(MEDIA_TYPE_LABELS.podcasts).toBe('Podcasts')
      expect(MEDIA_TYPE_LABELS.concerts).toBe('Concerts')
      expect(MEDIA_TYPE_LABELS.ebooks).toBe('Ebooks')
    })

    it('has a label for every MEDIA_TYPES entry', () => {
      MEDIA_TYPES.forEach((mt) => {
        expect(MEDIA_TYPE_LABELS[mt]).toBeDefined()
        expect(typeof MEDIA_TYPE_LABELS[mt]).toBe('string')
      })
    })
  })
})
