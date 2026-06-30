import { describe, it, expect } from 'vitest'
import { ALL_SCHEMAS } from '@/features/layout/schemas'
import { MEDIA_TYPES } from '@/features/layout/stores/media-mode-store'

describe('sidebar schemas', () => {
  it('every MediaType has a schema', () => {
    MEDIA_TYPES.forEach((mt) => {
      const schema = ALL_SCHEMAS[mt]
      expect(schema).toBeDefined()
      expect(schema.mediaType).toBe(mt)
    })
  })

  it('every schema has at least 3 sections', () => {
    MEDIA_TYPES.forEach((mt) => {
      const schema = ALL_SCHEMAS[mt]
      expect(schema.sections.length).toBeGreaterThanOrEqual(3)
    })
  })

  it('every section has a unique id within its schema', () => {
    MEDIA_TYPES.forEach((mt) => {
      const ids = ALL_SCHEMAS[mt].sections.map((s) => s.id)
      expect(new Set(ids).size).toBe(ids.length)
    })
  })

  it('every item has a unique id within its schema', () => {
    MEDIA_TYPES.forEach((mt) => {
      const ids = ALL_SCHEMAS[mt].sections.flatMap((s) => s.items.map((i) => i.id))
      expect(new Set(ids).size).toBe(ids.length)
    })
  })

  it('music schema matches brainstorm spec', () => {
    const music = ALL_SCHEMAS.music
    const sectionLabels = music.sections.map((s) => s.label)
    expect(sectionLabels).toContain('Quick Jump')
    expect(sectionLabels).toContain('Library')
    expect(sectionLabels).toContain('Collections')
    expect(sectionLabels).toContain('Discover')
  })

  it('movies schema matches brainstorm spec', () => {
    const movies = ALL_SCHEMAS.movies
    const sectionLabels = movies.sections.map((s) => s.label)
    expect(sectionLabels).toContain('Quick Jump')
    expect(sectionLabels).toContain('Library')
    expect(sectionLabels).toContain('Collections')
    expect(sectionLabels).toContain('Discover')
  })

  it('every page_link item has a route in config', () => {
    MEDIA_TYPES.forEach((mt) => {
      ALL_SCHEMAS[mt].sections.forEach((section) => {
        section.items.forEach((item) => {
          if (item.type === 'page_link') {
            expect(item.config?.route).toBeDefined()
            expect(item.config?.route).toMatch(/^\//)
          }
        })
      })
    })
  })

  it('schemas reference only valid route patterns', () => {
    MEDIA_TYPES.forEach((mt) => {
      ALL_SCHEMAS[mt].sections.forEach((section) => {
        section.items.forEach((item) => {
          if (item.type === 'page_link' && item.config?.route) {
            const route = item.config.route as string
            const isMediaRoute = route.startsWith(`/${mt}`)
            const isGlobalRoute = ['/search', '/settings', '/equalizer'].includes(route)
            expect(
              isMediaRoute || isGlobalRoute,
              `Route ${route} in ${mt} schema is neither a media route nor global`
            ).toBe(true)
          }
        })
      })
    })
  })
})
